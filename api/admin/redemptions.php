<?php
/**
 * api/admin/redemptions.php — admin redemption analytics + CSV.
 *
 *   GET ?action=list
 *       [&consumer_id=N][&sub_id=SUB-XXX][&item_id=N]
 *       [&from=YYYY-MM-DD][&to=YYYY-MM-DD]
 *       [&limit=200]
 *     Lists redemptions with item + consumer joined. Newest first.
 *     Default limit 200; hard cap 5000.
 *
 *   GET ?action=summary
 *       [&consumer_id=N][&sub_id=SUB-XXX]
 *     Returns aggregate KPIs: total redemptions, unique redeemers,
 *     total points awarded, total money value (per-currency).
 *
 *   GET ?action=csv  (same filters as list)
 *     Streams CSV download. No row limit -- streams up to 100k rows.
 */

declare(strict_types=1);

require_once __DIR__ . '/../rewards_bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/admin_session.php';

rewards_send_cors_origin();
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Admin-Session');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

rewards_admin_require_session();   /* 401s if no valid session */

$action = (string) ($_GET['action'] ?? '');
$pdo    = rewards_db();

/* Build a shared WHERE clause from query params. */
$buildFilter = function (): array {
    $where = ['1=1'];
    $args  = [];
    if (!empty($_GET['consumer_id'])) {
        $where[] = 'r.`consumer_id` = ?';
        $args[]  = (int) $_GET['consumer_id'];
    }
    if (!empty($_GET['sub_id'])) {
        $where[] = 'r.`sub_id` = ?';
        $args[]  = trim((string) $_GET['sub_id']);
    }
    if (!empty($_GET['item_id'])) {
        $where[] = 'r.`rewards_item_id` = ?';
        $args[]  = (int) $_GET['item_id'];
    }
    if (!empty($_GET['from'])) {
        $f = trim((string) $_GET['from']);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f)) {
            $where[] = 'r.`redeemed_at` >= ?';
            $args[]  = $f . ' 00:00:00';
        }
    }
    if (!empty($_GET['to'])) {
        $t = trim((string) $_GET['to']);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $t)) {
            $where[] = 'r.`redeemed_at` <= ?';
            $args[]  = $t . ' 23:59:59';
        }
    }
    return [implode(' AND ', $where), $args];
};

header('Content-Type: application/json; charset=utf-8');

if ($action === 'list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    [$where, $args] = $buildFilter();
    $limit = (int) ($_GET['limit'] ?? 200);
    if ($limit < 1)   $limit = 200;
    if ($limit > 5000) $limit = 5000;

    try {
        $st = $pdo->prepare(
            "SELECT r.`id`, r.`redeemed_at`, r.`consumer_id`, c.`name` AS `consumer_name`,
                    r.`rewards_item_id`, i.`name` AS `item_name`,
                    r.`sub_id`,
                    r.`redeemer_email`, r.`redeemer_key`,
                    r.`points_awarded`, r.`money_value`, r.`currency`,
                    r.`user_agent`
               FROM `rewards_redemption` r
               JOIN `rewards_item`     i ON i.`id` = r.`rewards_item_id`
               JOIN `rewards_consumer` c ON c.`id` = r.`consumer_id`
              WHERE $where
              ORDER BY r.`redeemed_at` DESC
              LIMIT $limit"
        );
        $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        rewards_safe_error_response($e, 'redemption list failed');
    }
    rewards_json_ok(['redemption_count' => count($rows), 'redemptions' => $rows]);
}

if ($action === 'summary' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    [$where, $args] = $buildFilter();
    try {
        $total = $pdo->prepare(
            "SELECT COUNT(*) AS `n`,
                    COUNT(DISTINCT COALESCE(r.`redeemer_email`, r.`redeemer_key`)) AS `unique_redeemers`,
                    SUM(r.`points_awarded`) AS `points_sum`
               FROM `rewards_redemption` r
              WHERE $where"
        );
        $total->execute($args);
        $t = $total->fetch(PDO::FETCH_ASSOC) ?: [];

        $byCcy = $pdo->prepare(
            "SELECT r.`currency`, SUM(r.`money_value`) AS `money_sum`, COUNT(*) AS `n`
               FROM `rewards_redemption` r
              WHERE $where
              GROUP BY r.`currency`"
        );
        $byCcy->execute($args);
        $byCurrency = $byCcy->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        rewards_safe_error_response($e, 'summary failed');
    }
    rewards_json_ok([
        'total_redemptions' => (int) ($t['n']               ?? 0),
        'unique_redeemers'  => (int) ($t['unique_redeemers'] ?? 0),
        'total_points'      => (int) ($t['points_sum']       ?? 0),
        'by_currency'       => $byCurrency,
    ]);
}

if ($action === 'csv' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    [$where, $args] = $buildFilter();
    try {
        $st = $pdo->prepare(
            "SELECT r.`id`, r.`redeemed_at`, c.`name` AS `consumer_name`,
                    i.`name` AS `item_name`, r.`sub_id`,
                    r.`redeemer_email`, r.`redeemer_key`,
                    r.`points_awarded`, r.`money_value`, r.`currency`
               FROM `rewards_redemption` r
               JOIN `rewards_item`     i ON i.`id` = r.`rewards_item_id`
               JOIN `rewards_consumer` c ON c.`id` = r.`consumer_id`
              WHERE $where
              ORDER BY r.`redeemed_at` DESC
              LIMIT 100000"
        );
        $st->execute($args);
    } catch (Throwable $e) {
        rewards_safe_error_response($e, 'csv query failed');
    }

    /* Stream CSV. */
    header_remove('Content-Type');
    $stamp = gmdate('Ymd-His');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="rewards-redemptions-' . $stamp . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'redemption_id', 'redeemed_at_utc', 'consumer_name', 'item_name',
        'sub_id', 'redeemer_email', 'redeemer_key',
        'points_awarded', 'money_value', 'currency',
    ]);
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [
            $r['id'], $r['redeemed_at'], $r['consumer_name'], $r['item_name'],
            $r['sub_id'], $r['redeemer_email'] ?? '', $r['redeemer_key'] ?? '',
            $r['points_awarded'] ?? '', $r['money_value'] ?? '', $r['currency'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

rewards_json_err('unknown action: ' . $action, 400);
