<?php
/**
 * /v1/enrollments.php — consumer-scoped enrolment roster API.
 *
 *   GET  ?action=list&sub_id=SUB-XXX[&source=system|manual][&status=active|suspended|unenrolled][&q=text][&limit=500]
 *        → { ok, enrollments:[...], counts:{ source, status, total } }
 *   POST ?action=enroll      body { sub_id, first_name, last_name, email, email_confirm, actor }
 *   POST ?action=set_status  body { sub_id, email|id, status, actor }
 *
 *   Headers: X-Consumer-Key: <api_key>  (required)
 *
 * The consumer layer the WBM bank talks to via rewards_proxy.php. Scoped
 * to the auth'd consumer + sub. Mirrors the shape of admin/enrollments.php
 * (which serves the RF admin UI on an admin session). Migration 014.
 * Setup-guarded: returns a friendly 409 {setup:true} when the table is
 * absent, never a raw SQLSTATE.
 */

declare(strict_types=1);

require_once __DIR__ . '/../rewards_bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../rewards_consumer_auth.php';
require_once __DIR__ . '/../lib/enrollment.php';

header('Content-Type: application/json; charset=utf-8');
rewards_send_cors_origin();
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Consumer-Key');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$method   = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET' && $method !== 'POST') rewards_json_err('GET or POST required', 405);
$consumer = rewards_require_consumer();
$cid      = (int) $consumer['id'];
$action   = (string) ($_GET['action'] ?? $_POST['action'] ?? 'list');

$subId = trim((string) ($_GET['sub_id'] ?? $_POST['sub_id'] ?? ''));
if ($subId === '' || !preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $subId)) {
    rewards_json_err('sub_id required (alphanumeric / dash / underscore, 1-64)', 400);
}

$pdo = rewards_db();

/* Setup guard — friendly 409 instead of a raw SQLSTATE before migration 014. */
if (!rewards_enrollment_tables_ready($pdo)) {
    rewards_json_err(
        'Enrolments isn\'t set up on this site yet — its database table hasn\'t been created.',
        409,
        ['setup' => true, 'fix' => 'Run migration 014 (api/migrate/run.php), then reload.', 'migration' => '014_rewards_enrollment']
    );
}

/* POST body — JSON or form, query fallback (mirrors v1/redemptions.php). */
$_body = [];
if ($method === 'POST') {
    $raw = (string) file_get_contents('php://input');
    $j   = json_decode($raw, true);
    if (is_array($j))       $_body = $j;
    elseif (!empty($_POST)) $_body = $_POST;
}
$param = function (string $k, $default = null) use ($_body) {
    if (array_key_exists($k, $_body)) return $_body[$k];
    if (array_key_exists($k, $_GET))  return $_GET[$k];
    return $default;
};

/* Scope: this consumer + sub. `consumer_id IS NULL` is tolerated so a row
   created before the item's consumer was resolved still surfaces to the
   sub's owner (a sub belongs to exactly one consumer). */
$scopeWhere = '`sub_id` = ? AND (`consumer_id` = ? OR `consumer_id` IS NULL)';

/* ── LIST ──────────────────────────────────────────────────────── */
if ($action === 'list') {
    $where = [$scopeWhere];
    $args  = [$subId, $cid];
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

        $cst = $pdo->prepare(
            "SELECT `source`, `status`, COUNT(*) AS n
               FROM `rewards_enrollment` WHERE $scopeWhere
              GROUP BY `source`, `status`"
        );
        $cst->execute([$subId, $cid]);
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
    rewards_json_ok(['sub_id' => $subId, 'enrollments' => $rows, 'counts' => $counts]);
}

/* ── ENROLL (manual) ───────────────────────────────────────────── */
if ($action === 'enroll') {
    if ($method !== 'POST') rewards_json_err('POST required', 405);
    $first        = trim((string) $param('first_name', ''));
    $last         = trim((string) $param('last_name', ''));
    $email        = strtolower(trim((string) $param('email', '')));
    $emailConfirm = strtolower(trim((string) $param('email_confirm', '')));
    $actor        = trim((string) $param('actor', ''));

    if ($first === '' || $last === '') rewards_json_err('first and last name are required', 400);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) rewards_json_err('a valid email is required', 400);
    if ($emailConfirm === '' || $email !== $emailConfirm) rewards_json_err('the two email addresses don\'t match', 422);

    try {
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
        $up->execute([$cid, $subId, $email, $first, $last, ($actor !== '' ? $actor : null)]);
        $created = ($up->rowCount() === 1);
        $idSt = $pdo->prepare("SELECT `id`, `status` FROM `rewards_enrollment` WHERE `sub_id` = ? AND `email` = ? LIMIT 1");
        $idSt->execute([$subId, $email]);
        $r = $idSt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        rewards_safe_error_response($e, 'manual enrol failed');
    }
    rewards_json_ok(['id' => (int) ($r['id'] ?? 0), 'created' => $created, 'status' => (string) ($r['status'] ?? 'active')]);
}

/* ── SET STATUS (suspend / unenrol / reactivate) ───────────────── */
if ($action === 'set_status') {
    if ($method !== 'POST') rewards_json_err('POST required', 405);
    $status = strtolower(trim((string) $param('status', '')));
    if (!in_array($status, ['active', 'suspended', 'unenrolled'], true)) rewards_json_err('status must be active, suspended or unenrolled', 400);

    $id    = (int) $param('id', 0);
    $email = strtolower(trim((string) $param('email', '')));
    if ($id <= 0 && $email === '') rewards_json_err('id or email required', 400);

    try {
        if ($id > 0) {
            $pdo->prepare("UPDATE `rewards_enrollment` SET `status` = ? WHERE `id` = ? AND $scopeWhere")
                ->execute([$status, $id, $subId, $cid]);
        } else {
            $pdo->prepare("UPDATE `rewards_enrollment` SET `status` = ? WHERE `email` = ? AND $scopeWhere")
                ->execute([$status, $email, $subId, $cid]);
            $idSt = $pdo->prepare("SELECT `id` FROM `rewards_enrollment` WHERE `email` = ? AND $scopeWhere LIMIT 1");
            $idSt->execute([$email, $subId, $cid]);
            $id = (int) ($idSt->fetchColumn() ?: 0);
        }
    } catch (Throwable $e) {
        rewards_safe_error_response($e, 'status update failed');
    }
    rewards_json_ok(['id' => $id, 'status' => $status]);
}

rewards_json_err('unknown action: ' . $action, 400);
