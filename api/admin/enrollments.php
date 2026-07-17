<?php
/**
 * api/admin/enrollments.php — rewards enrolment roster admin API.
 *
 * Backs the Enrolments tab in the RF admin UI and (via WBM's
 * rewards_proxy.php) the WBM bank rewards-foundry area. Migration 014.
 *
 *   GET  ?action=list&sub_id=SUB-XXX[&source=system|manual][&status=active|suspended|unenrolled][&q=text][&limit=500]
 *        → { ok, enrollments:[...], counts:{ source:{system,manual}, status:{active,suspended,unenrolled}, total } }
 *
 *   POST ?action=enroll                       (manual enrol — double-entry email enforced server-side)
 *        body { sub_id, first_name, last_name, email, email_confirm }
 *        → { ok, id, created:bool, status }
 *
 *   POST ?action=set_status                   (suspend / unenrol / reactivate)
 *        body { sub_id, email | id, status: active|suspended|unenrolled }
 *        → { ok, id, status }
 *
 * Auth: admin session, same gate as admin/redemptions.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../rewards_bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/admin_session.php';
require_once __DIR__ . '/../lib/enrollment.php';

rewards_send_cors_origin();
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Admin-Session');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$adminSess = rewards_admin_require_session();   /* 401s if no valid session; carries admin email */
header('Content-Type: application/json; charset=utf-8');

$pdo    = rewards_db();
$action = (string) ($_GET['action'] ?? '');

/* Setup guard — readable 409 instead of a raw SQLSTATE when migration
   014 hasn't run. Mirrors the WBM ml_scorm_guard pattern. */
if (!rewards_enrollment_tables_ready($pdo)) {
    http_response_code(409);
    echo json_encode([
        'ok'        => false,
        'error'     => 'Enrolments isn\'t set up on this site yet — its database table hasn\'t been created.',
        'setup'     => true,
        'fix'       => 'Run migration 014 (api/migrate/run.php), then reload.',
        'migration' => '014_rewards_enrollment',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

/* ── LIST ──────────────────────────────────────────────────────── */
if ($action === 'list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $subId = trim((string) ($_GET['sub_id'] ?? ''));
    if ($subId === '') rewards_json_err('sub_id required', 400);

    $where = ['`sub_id` = ?'];
    $args  = [$subId];
    $src = strtolower(trim((string) ($_GET['source'] ?? '')));
    if ($src === 'system' || $src === 'manual') { $where[] = '`source` = ?'; $args[] = $src; }
    $stt = strtolower(trim((string) ($_GET['status'] ?? '')));
    if (in_array($stt, ['active', 'suspended', 'unenrolled'], true)) { $where[] = '`status` = ?'; $args[] = $stt; }
    $q = trim((string) ($_GET['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(`email` LIKE ? OR `first_name` LIKE ? OR `last_name` LIKE ? OR CONCAT(`first_name`,\' \',`last_name`) LIKE ?)';
        $like = '%' . $q . '%';
        array_push($args, $like, $like, $like, $like);
    }
    $limit = (int) ($_GET['limit'] ?? 500);
    if ($limit < 1) $limit = 500;
    if ($limit > 5000) $limit = 5000;
    $whereSql = implode(' AND ', $where);

    try {
        $st = $pdo->prepare(
            "SELECT `id`, `sub_id`, `email`, `first_name`, `last_name`,
                    `source`, `status`, `created_by`, `created_at`, `updated_at`
               FROM `rewards_enrollment`
              WHERE $whereSql
              ORDER BY `status` = 'active' DESC, `last_name`, `first_name`, `email`
              LIMIT $limit"
        );
        $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        /* Counts across the WHOLE sub (ignore the source/status/q filters
           so the filter chips can show totals). */
        $cst = $pdo->prepare(
            "SELECT `source`, `status`, COUNT(*) AS n
               FROM `rewards_enrollment` WHERE `sub_id` = ?
              GROUP BY `source`, `status`"
        );
        $cst->execute([$subId]);
        $counts = ['source' => ['system' => 0, 'manual' => 0],
                   'status' => ['active' => 0, 'suspended' => 0, 'unenrolled' => 0],
                   'total'  => 0];
        foreach ($cst->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $n = (int) $c['n'];
            $counts['total'] += $n;
            if (isset($counts['source'][$c['source']])) $counts['source'][$c['source']] += $n;
            if (isset($counts['status'][$c['status']])) $counts['status'][$c['status']] += $n;
        }
    } catch (Throwable $e) {
        rewards_safe_error_response($e, 'enrolment list failed');
    }
    rewards_json_ok(['enrollments' => $rows, 'counts' => $counts]);
}

/* POST actions read a JSON body. */
$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body)) $body = [];

/* ── ENROLL (manual) ───────────────────────────────────────────── */
if ($action === 'enroll' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $subId        = trim((string) ($body['sub_id'] ?? ''));
    $first        = trim((string) ($body['first_name'] ?? ''));
    $last         = trim((string) ($body['last_name'] ?? ''));
    $email        = strtolower(trim((string) ($body['email'] ?? '')));
    $emailConfirm = strtolower(trim((string) ($body['email_confirm'] ?? '')));

    if ($subId === '' || !preg_match('/^SUB-[A-Za-z0-9]{1,32}$/', $subId)) rewards_json_err('valid sub_id required', 400);
    if ($first === '' || $last === '') rewards_json_err('first and last name are required', 400);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) rewards_json_err('a valid email is required', 400);
    /* Double-entry guard (also enforced in the drawer, never trusted). */
    if ($emailConfirm === '' || $email !== $emailConfirm) rewards_json_err('the two email addresses don\'t match', 422);

    /* Resolve consumer_id from any item on this sub (nullable if none yet). */
    $consumerId = null;
    try {
        $ci = $pdo->prepare("SELECT `consumer_id` FROM `rewards_item` WHERE `sub_id` = ? LIMIT 1");
        $ci->execute([$subId]);
        $v = $ci->fetchColumn();
        if ($v !== false) $consumerId = (int) $v;
    } catch (Throwable $_) {}

    $admin = (string) ($adminSess['email'] ?? '');   /* who's adding them */

    try {
        /* Upsert: a manual enrol of someone already present (e.g. a system
           row, or a previously unenrolled/suspended person) reactivates
           them and fills in the name. It never downgrades an existing
           'manual' source back to 'system'. */
        $up = $pdo->prepare(
            "INSERT INTO `rewards_enrollment`
               (`consumer_id`, `sub_id`, `email`, `first_name`, `last_name`, `source`, `status`, `created_by`)
             VALUES (?, ?, ?, ?, ?, 'manual', 'active', ?)
             ON DUPLICATE KEY UPDATE
               `first_name` = VALUES(`first_name`),
               `last_name`  = VALUES(`last_name`),
               `source`     = 'manual',
               `status`     = 'active'"
        );
        $up->execute([$consumerId, $subId, $email, $first, $last, $admin]);
        $created = ($up->rowCount() === 1);   /* 1 = insert, 2 = update */

        $idSt = $pdo->prepare("SELECT `id`, `status` FROM `rewards_enrollment` WHERE `sub_id` = ? AND `email` = ? LIMIT 1");
        $idSt->execute([$subId, $email]);
        $r = $idSt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        rewards_safe_error_response($e, 'manual enrol failed');
    }
    rewards_json_ok(['id' => (int) ($r['id'] ?? 0), 'created' => $created, 'status' => (string) ($r['status'] ?? 'active')]);
}

/* ── SET STATUS (suspend / unenrol / reactivate) ───────────────── */
if ($action === 'set_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $status = strtolower(trim((string) ($body['status'] ?? '')));
    if (!in_array($status, ['active', 'suspended', 'unenrolled'], true)) rewards_json_err('status must be active, suspended or unenrolled', 400);

    $id    = (int) ($body['id'] ?? 0);
    $subId = trim((string) ($body['sub_id'] ?? ''));
    $email = strtolower(trim((string) ($body['email'] ?? '')));
    if ($id <= 0 && ($subId === '' || $email === '')) rewards_json_err('id, or sub_id + email, required', 400);

    try {
        if ($id > 0) {
            $pdo->prepare("UPDATE `rewards_enrollment` SET `status` = ? WHERE `id` = ?")->execute([$status, $id]);
        } else {
            $pdo->prepare("UPDATE `rewards_enrollment` SET `status` = ? WHERE `sub_id` = ? AND `email` = ?")
                ->execute([$status, $subId, $email]);
            $idSt = $pdo->prepare("SELECT `id` FROM `rewards_enrollment` WHERE `sub_id` = ? AND `email` = ? LIMIT 1");
            $idSt->execute([$subId, $email]);
            $id = (int) ($idSt->fetchColumn() ?: 0);
        }
    } catch (Throwable $e) {
        rewards_safe_error_response($e, 'status update failed');
    }
    rewards_json_ok(['id' => $id, 'status' => $status]);
}

rewards_json_err('unknown action: ' . $action, 400);
