<?php
/**
 * api/admin/schools.php — Schools Pilot management endpoints.
 *
 * 2026-06-26. Phase D of the Schools Pilot. Powers the new "Schools"
 * tab in the rewards-foundry admin (public/admin/index.html).
 *
 * Admin-session-gated. Every action requires
 * rewards_admin_require_session() (X-Admin-Session header).
 *
 *   GET ?action=catalogues
 *     → { ok, catalogues: [{ id, sub_id, edition_key, trusted_role_label,
 *                            self_scan_policy, theme_primary_hex, active,
 *                            consumer_name, activity_count }] }
 *
 *   GET ?action=activities&catalogue_id=N
 *       [&scope=ME|WE|US|GENERIC] [&direction=UP|DOWN]
 *       [&dimension=POSITIVE_EMOTIONS|...] [&q=<search title>]
 *       [&include_inactive=1]
 *     → { ok, activities: [{...activity fields, scope_label, dimension_label,
 *                            qr_url, redeem_url, awards_count }] }
 *
 *   POST ?action=update_activity&id=N
 *     body: { title?, points?, active?, self_scan_enabled? }
 *     → { ok, activity }
 *
 *   GET ?action=pending&catalogue_id=N [&limit=200]
 *     → { ok, pending: [{ award_id, participant_email, participant_key,
 *                          behaviour_title, scope_label, dimension_label,
 *                          direction, points, awarded_at,
 *                          self_scan_source_type, self_scan_minutes_ago }] }
 *
 *   POST ?action=confirm_pending&id=N
 *     body: { approver_email }
 *     → { ok, award_id, new_status:"CONFIRMED", points_applied, balance }
 *
 *   POST ?action=reject_pending&id=N
 *     body: { approver_email }
 *     → { ok, award_id, new_status:"REJECTED", points_applied, balance }
 *
 *   GET ?action=stats&catalogue_id=N
 *     → { ok, stats: { activities_total, activities_active,
 *                       by_scope: {ME:n,WE:n,US:n,GENERIC:n},
 *                       awards_confirmed_total, awards_pending_total,
 *                       awards_today, awards_this_week } }
 */

declare(strict_types=1);

require_once __DIR__ . '/../rewards_bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/admin_session.php';

header('Content-Type: application/json; charset=utf-8');
rewards_send_cors_origin();
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Admin-Session');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

rewards_admin_require_session();

$action = (string) ($_GET['action'] ?? '');
$pdo    = rewards_db();

$proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host  = $_SERVER['HTTP_HOST'] ?? 'www.rewards-foundry.com';
$base  = $proto . '://' . $host;

/* ─────────────────────────────────────────────────────────────────
   Action: catalogues — list every behaviour_catalogue with the
                        owning consumer + an activity count.
   ───────────────────────────────────────────────────────────── */
if ($action === 'catalogues' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $rows = $pdo->query(
            "SELECT cat.`id`, cat.`sub_id`, cat.`edition_key`,
                    cat.`trusted_role_label`, cat.`self_scan_policy`,
                    cat.`theme_primary_hex`, cat.`active`,
                    cat.`scope_labels`, cat.`dimension_labels`, cat.`terminology`,
                    c.`name` AS consumer_name,
                    (SELECT COUNT(*) FROM `rewards_behaviour_activity` ba
                       WHERE ba.`catalogue_id` = cat.`id`) AS activity_count
               FROM `rewards_behaviour_catalogue` cat
               JOIN `rewards_consumer` c ON c.`id` = cat.`consumer_id`
              ORDER BY cat.`active` DESC, cat.`edition_key`, cat.`sub_id`"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        rewards_safe_error_response($e, 'catalogues query failed');
    }
    foreach ($rows as &$r) {
        $r['scope_labels']     = json_decode((string) $r['scope_labels'],     true) ?: [];
        $r['dimension_labels'] = json_decode((string) $r['dimension_labels'], true) ?: [];
        $r['terminology']      = json_decode((string) $r['terminology'],      true) ?: [];
        $r['id']               = (int) $r['id'];
        $r['active']           = (int) $r['active'] === 1;
        $r['activity_count']   = (int) $r['activity_count'];
    }
    unset($r);
    rewards_json_ok(['catalogues' => $rows]);
}

/* ─────────────────────────────────────────────────────────────────
   Action: activities — list activities for a catalogue with filters.
   ───────────────────────────────────────────────────────────── */
if ($action === 'activities' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $catId = (int) ($_GET['catalogue_id'] ?? 0);
    if ($catId <= 0) rewards_json_err('catalogue_id required', 400);

    /* Resolve catalogue labels once for decoration. */
    $cat = _wm_school_catalogue_or_fail($pdo, $catId);
    $scopeLabels = $cat['scope_labels_decoded'];
    $dimLabels   = $cat['dimension_labels_decoded'];

    $where  = ['ba.`catalogue_id` = ?'];
    $params = [$catId];
    if (!empty($_GET['scope']) && in_array($_GET['scope'], ['ME','WE','US','GENERIC'], true)) {
        $where[]  = 'ba.`scope` = ?'; $params[] = $_GET['scope'];
    }
    if (!empty($_GET['direction']) && in_array($_GET['direction'], ['UP','DOWN'], true)) {
        $where[]  = 'ba.`direction` = ?'; $params[] = $_GET['direction'];
    }
    if (!empty($_GET['dimension'])) {
        $where[]  = 'ba.`dimension_key` = ?'; $params[] = (string) $_GET['dimension'];
    }
    if (empty($_GET['include_inactive'])) {
        $where[]  = 'ba.`active` = 1';
    }
    if (!empty($_GET['q'])) {
        $where[]  = 'ba.`title` LIKE ?';
        $params[] = '%' . str_replace(['%','_'], ['\\%','\\_'], (string) $_GET['q']) . '%';
    }
    $whereSql = implode(' AND ', $where);

    try {
        $st = $pdo->prepare(
            "SELECT ba.`id`, ba.`qr_token`, ba.`scope`, ba.`direction`,
                    ba.`dimension_key`, ba.`title`, ba.`points`,
                    ba.`self_scan_enabled`, ba.`active`, ba.`theme_primary_hex`,
                    ba.`created_at`, ba.`updated_at`,
                    (SELECT COUNT(*) FROM `rewards_point_award` rpa
                       WHERE rpa.`behaviour_activity_id` = ba.`id`) AS awards_count
               FROM `rewards_behaviour_activity` ba
              WHERE $whereSql
              ORDER BY ba.`scope`, ba.`direction`, ba.`dimension_key`, ba.`id`
              LIMIT 500");
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        rewards_safe_error_response($e, 'activities query failed');
    }

    foreach ($rows as &$r) {
        $r['id']               = (int) $r['id'];
        $r['points']           = (int) $r['points'];
        $r['active']           = (int) $r['active'] === 1;
        $r['self_scan_enabled']= (int) $r['self_scan_enabled'] === 1;
        $r['scope_label']      = (string) ($scopeLabels[$r['scope']] ?? $r['scope']);
        $dim                   = (string) ($r['dimension_key'] ?? '');
        $r['dimension_label']  = $dim !== '' ? (string) ($dimLabels[$dim] ?? $dim) : null;
        $r['awards_count']     = (int) $r['awards_count'];
        $r['qr_url']           = $base . '/api/v1/qr.php?t=' . rawurlencode((string) $r['qr_token']);
        $r['redeem_url']       = $base . '/redeem?t=' . rawurlencode((string) $r['qr_token']);
    }
    unset($r);
    rewards_json_ok(['activities' => $rows]);
}

/* ─────────────────────────────────────────────────────────────────
   Action: update_activity — inline edit (title / points / active /
                              self_scan_enabled). qr_token is immutable
                              (cards printed in the field). scope /
                              direction / dimension_key also immutable
                              (changing those would invalidate the
                              catalogue's slot accounting).
   ───────────────────────────────────────────────────────────── */
if ($action === 'update_activity' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) rewards_json_err('id required', 400);

    $body = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($body)) rewards_json_err('JSON body required', 400);

    $sets   = [];
    $params = [];
    if (isset($body['title'])) {
        $title = trim((string) $body['title']);
        if ($title === '' || strlen($title) > 255) rewards_json_err('title invalid (1-255 chars)', 400);
        $sets[]   = '`title` = ?'; $params[] = $title;
    }
    if (isset($body['points'])) {
        $p = (int) $body['points'];
        if ($p <= 0 || $p > 999) rewards_json_err('points must be 1-999', 400);
        $sets[]   = '`points` = ?'; $params[] = $p;
    }
    if (isset($body['active'])) {
        $sets[]   = '`active` = ?'; $params[] = ((bool) $body['active']) ? 1 : 0;
    }
    if (isset($body['self_scan_enabled'])) {
        $sets[]   = '`self_scan_enabled` = ?'; $params[] = ((bool) $body['self_scan_enabled']) ? 1 : 0;
    }
    if (empty($sets)) rewards_json_err('no editable fields supplied', 400);

    $params[] = $id;
    try {
        $upd = $pdo->prepare("UPDATE `rewards_behaviour_activity` SET " . implode(', ', $sets) . " WHERE `id` = ? LIMIT 1");
        $upd->execute($params);
    } catch (Throwable $e) {
        rewards_safe_error_response($e, 'update failed');
    }

    /* Re-read for the response. */
    $st = $pdo->prepare(
        "SELECT `id`, `qr_token`, `scope`, `direction`, `dimension_key`,
                `title`, `points`, `self_scan_enabled`, `active`, `updated_at`
           FROM `rewards_behaviour_activity` WHERE `id` = ?");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) rewards_json_err('activity not found', 404);
    $row['id']               = (int) $row['id'];
    $row['points']           = (int) $row['points'];
    $row['active']           = (int) $row['active'] === 1;
    $row['self_scan_enabled']= (int) $row['self_scan_enabled'] === 1;

    rewards_json_ok(['activity' => $row]);
}

/* ─────────────────────────────────────────────────────────────────
   Action: pending — list PENDING awards for a catalogue. Used by the
                     "Awaiting confirmation" panel in the admin UI.
   ───────────────────────────────────────────────────────────── */
if ($action === 'pending' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $catId = (int) ($_GET['catalogue_id'] ?? 0);
    if ($catId <= 0) rewards_json_err('catalogue_id required', 400);
    $limit = max(1, min(500, (int) ($_GET['limit'] ?? 200)));

    $cat = _wm_school_catalogue_or_fail($pdo, $catId);
    $scopeLabels = $cat['scope_labels_decoded'];
    $dimLabels   = $cat['dimension_labels_decoded'];

    try {
        $st = $pdo->prepare(
            "SELECT pa.`id` AS award_id,
                    pa.`participant_email`, pa.`participant_key`,
                    pa.`source_type`, pa.`points`, pa.`awarded_at`,
                    ba.`title` AS behaviour_title,
                    ba.`scope`, ba.`direction`, ba.`dimension_key`
               FROM `rewards_point_award` pa
               JOIN `rewards_behaviour_activity` ba ON ba.`id` = pa.`behaviour_activity_id`
              WHERE pa.`status` = 'PENDING'
                AND ba.`catalogue_id` = ?
              ORDER BY pa.`awarded_at` DESC
              LIMIT $limit");
        $st->execute([$catId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        rewards_safe_error_response($e, 'pending query failed');
    }

    foreach ($rows as &$r) {
        $r['award_id']         = (int) $r['award_id'];
        $r['points']           = (int) $r['points'];
        $r['scope_label']      = (string) ($scopeLabels[$r['scope']] ?? $r['scope']);
        $dim                   = (string) ($r['dimension_key'] ?? '');
        $r['dimension_label']  = $dim !== '' ? (string) ($dimLabels[$dim] ?? $dim) : null;
        /* Friendly "5 minutes ago" / "3 hours ago" calc. */
        try {
            $ts = strtotime((string) $r['awarded_at'] . ' UTC');
            $secsAgo = max(0, time() - $ts);
            $r['ago'] = _wm_school_ago_label($secsAgo);
        } catch (Throwable $_) { $r['ago'] = ''; }
    }
    unset($r);
    rewards_json_ok(['pending' => $rows]);
}

/* ─────────────────────────────────────────────────────────────────
   Action: confirm_pending / reject_pending — flip PENDING → CONFIRMED|REJECTED.
   Mirrors api/v1/behaviour_approve.php but admin-session-auth instead
   of consumer-key. Records the admin's email as awarded_by_email.
   ───────────────────────────────────────────────────────────── */
if (($action === 'confirm_pending' || $action === 'reject_pending') && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $awardId = (int) ($_GET['id'] ?? 0);
    if ($awardId <= 0) rewards_json_err('id required', 400);

    $body = json_decode(file_get_contents('php://input') ?: '', true);
    $approver = strtolower(trim((string) ($body['approver_email'] ?? '')));
    if ($approver === '' || !filter_var($approver, FILTER_VALIDATE_EMAIL)) {
        rewards_json_err('approver_email required + must be valid email', 400);
    }
    $newStatus = $action === 'confirm_pending' ? 'CONFIRMED' : 'REJECTED';

    try {
        $st = $pdo->prepare(
            "SELECT `id`, `participant_email`, `status`, `points`
               FROM `rewards_point_award` WHERE `id` = ? LIMIT 1");
        $st->execute([$awardId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) rewards_json_err('award not found', 404);
        if ((string) $row['status'] !== 'PENDING') {
            rewards_json_err('award already finalised', 409, ['status' => (string) $row['status']]);
        }

        $upd = $pdo->prepare(
            "UPDATE `rewards_point_award`
                SET `status` = ?, `awarded_by_email` = ?
              WHERE `id` = ? AND `status` = 'PENDING' LIMIT 1");
        $upd->execute([$newStatus, $approver, $awardId]);
        if ($upd->rowCount() === 0) {
            rewards_json_err('award already finalised (concurrent approver)', 409);
        }

        try {
            $pdo->prepare(
                "INSERT INTO `rewards_audit`
                    (`actor_admin_user_id`, `action`, `entity_type`, `entity_id`, `details`)
                 VALUES (NULL, ?, 'rewards_point_award', ?, ?)"
            )->execute([
                $newStatus === 'CONFIRMED' ? 'behaviour_award_confirmed' : 'behaviour_award_rejected',
                (string) $awardId,
                json_encode([
                    'approver_email'  => $approver,
                    'previous_status' => 'PENDING',
                    'new_status'      => $newStatus,
                    'via'             => 'admin_ui',
                    'points'          => (int) $row['points'],
                ]),
            ]);
        } catch (Throwable $_) { /* non-fatal */ }

        $balance = null;
        $pEmail = (string) ($row['participant_email'] ?? '');
        if ($pEmail !== '') {
            try {
                $bq = $pdo->prepare(
                    "SELECT COALESCE(SUM(`points`), 0) FROM `rewards_point_award`
                      WHERE `participant_email` = ? AND `status` = 'CONFIRMED'");
                $bq->execute([$pEmail]);
                $balance = (int) $bq->fetchColumn();
            } catch (Throwable $_) { /* non-fatal */ }
        }

        rewards_json_ok([
            'award_id'       => $awardId,
            'new_status'     => $newStatus,
            'points_applied' => (int) $row['points'],
            'balance'        => $balance,
        ]);
    } catch (Throwable $e) {
        rewards_safe_error_response($e, 'pending flip failed');
    }
}

/* ─────────────────────────────────────────────────────────────────
   Action: stats — top-line numbers for the catalogue overview card.
   ───────────────────────────────────────────────────────────── */
if ($action === 'stats' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $catId = (int) ($_GET['catalogue_id'] ?? 0);
    if ($catId <= 0) rewards_json_err('catalogue_id required', 400);

    try {
        $stmt = $pdo->prepare(
            "SELECT
                COUNT(*)                                        AS activities_total,
                SUM(CASE WHEN `active` = 1 THEN 1 ELSE 0 END)   AS activities_active,
                SUM(CASE WHEN `scope`  = 'ME'      THEN 1 ELSE 0 END) AS s_me,
                SUM(CASE WHEN `scope`  = 'WE'      THEN 1 ELSE 0 END) AS s_we,
                SUM(CASE WHEN `scope`  = 'US'      THEN 1 ELSE 0 END) AS s_us,
                SUM(CASE WHEN `scope`  = 'GENERIC' THEN 1 ELSE 0 END) AS s_generic
               FROM `rewards_behaviour_activity` WHERE `catalogue_id` = ?");
        $stmt->execute([$catId]);
        $a = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $stmt2 = $pdo->prepare(
            "SELECT
                SUM(CASE WHEN pa.`status` = 'CONFIRMED' THEN 1 ELSE 0 END) AS awards_confirmed_total,
                SUM(CASE WHEN pa.`status` = 'PENDING'   THEN 1 ELSE 0 END) AS awards_pending_total,
                SUM(CASE WHEN DATE(pa.`awarded_at`) = UTC_DATE()          THEN 1 ELSE 0 END) AS awards_today,
                SUM(CASE WHEN pa.`awarded_at` >= UTC_TIMESTAMP() - INTERVAL 7 DAY THEN 1 ELSE 0 END) AS awards_this_week
               FROM `rewards_point_award` pa
               JOIN `rewards_behaviour_activity` ba ON ba.`id` = pa.`behaviour_activity_id`
              WHERE ba.`catalogue_id` = ?");
        $stmt2->execute([$catId]);
        $b = $stmt2->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        rewards_safe_error_response($e, 'stats query failed');
    }

    rewards_json_ok(['stats' => [
        'activities_total'       => (int) ($a['activities_total']  ?? 0),
        'activities_active'      => (int) ($a['activities_active'] ?? 0),
        'by_scope' => [
            'ME'      => (int) ($a['s_me']      ?? 0),
            'WE'      => (int) ($a['s_we']      ?? 0),
            'US'      => (int) ($a['s_us']      ?? 0),
            'GENERIC' => (int) ($a['s_generic'] ?? 0),
        ],
        'awards_confirmed_total' => (int) ($b['awards_confirmed_total'] ?? 0),
        'awards_pending_total'   => (int) ($b['awards_pending_total']   ?? 0),
        'awards_today'           => (int) ($b['awards_today']           ?? 0),
        'awards_this_week'       => (int) ($b['awards_this_week']       ?? 0),
    ]]);
}

rewards_json_err('unknown action', 400);

/* ─────────────────────────────────────────────────────────────────
   Helpers (declared at the bottom — kept below the main action
   dispatch so the file reads top-down).
   ───────────────────────────────────────────────────────────── */

function _wm_school_catalogue_or_fail(PDO $pdo, int $catId): array {
    $st = $pdo->prepare(
        "SELECT `id`, `sub_id`, `edition_key`, `scope_labels`, `dimension_labels`,
                `terminology`, `trusted_role_label`, `self_scan_policy`,
                `theme_primary_hex`
           FROM `rewards_behaviour_catalogue` WHERE `id` = ? LIMIT 1");
    $st->execute([$catId]);
    $cat = $st->fetch(PDO::FETCH_ASSOC);
    if (!$cat) rewards_json_err('catalogue not found', 404);
    $cat['scope_labels_decoded']     = json_decode((string) $cat['scope_labels'],     true) ?: [];
    $cat['dimension_labels_decoded'] = json_decode((string) $cat['dimension_labels'], true) ?: [];
    $cat['terminology_decoded']      = json_decode((string) $cat['terminology'],      true) ?: [];
    return $cat;
}

function _wm_school_ago_label(int $secs): string {
    if ($secs < 60)        return $secs . 's ago';
    if ($secs < 3600)      return (int) ($secs / 60) . 'm ago';
    if ($secs < 86400)     return (int) ($secs / 3600) . 'h ago';
    return (int) ($secs / 86400) . 'd ago';
}
