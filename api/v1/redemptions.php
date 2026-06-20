<?php
/**
 * /v1/redemptions.php — consumer-scoped redemption read API.
 *
 *   GET ?action=list&sub_id=SUB-XXX[&item_id=N][&from=YYYY-MM-DD][&to=YYYY-MM-DD][&limit=200]
 *   GET ?action=summary&sub_id=SUB-XXX
 *
 *   Headers: X-Consumer-Key: <api_key>  (required)
 *
 * Auto-scoped to the auth'd consumer (no consumer_id query param --
 * the gate determines it). Same response shape the admin endpoint
 * uses but filtered to one consumer.
 *
 * Notes
 *   - sub_id is REQUIRED here -- the consumer (e.g. WBM bank super)
 *     always operates in a specific sub context. Admin-side
 *     /api/admin/redemptions.php allows unscoped lists; this one
 *     doesn't.
 *   - CSV export stays admin-only. Consumers that need raw data
 *     should iterate the list endpoint themselves.
 */

declare(strict_types=1);

require_once __DIR__ . '/../rewards_bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../rewards_consumer_auth.php';

header('Content-Type: application/json; charset=utf-8');
rewards_send_cors_origin();
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Consumer-Key');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET')     rewards_json_err('GET required', 405);

$consumer = rewards_require_consumer();
$action   = (string) ($_GET['action'] ?? 'list');

$subId = trim((string) ($_GET['sub_id'] ?? ''));
if ($subId === '' || !preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $subId)) {
    rewards_json_err('sub_id required (alphanumeric / dash / underscore, 1-64)', 400);
}

$pdo = rewards_db();

/* Shared WHERE — always consumer_id-scoped + sub_id-scoped. */
$buildFilter = function () use ($consumer, $subId): array {
    $where = ['r.`consumer_id` = ?', 'r.`sub_id` = ?'];
    $args  = [(int) $consumer['id'], $subId];
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

if ($action === 'list') {
    [$where, $args] = $buildFilter();
    $limit = (int) ($_GET['limit'] ?? 200);
    if ($limit < 1)   $limit = 200;
    if ($limit > 5000) $limit = 5000;

    try {
        $st = $pdo->prepare(
            "SELECT r.`id`, r.`redeemed_at`,
                    r.`rewards_item_id`, i.`name` AS `item_name`,
                    r.`sub_id`,
                    r.`redeemer_email`, r.`redeemer_key`,
                    r.`points_awarded`, r.`money_value`, r.`currency`,
                    r.`user_agent`
               FROM `rewards_redemption` r
               JOIN `rewards_item`       i ON i.`id` = r.`rewards_item_id`
              WHERE $where
              ORDER BY r.`redeemed_at` DESC
              LIMIT $limit"
        );
        $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        rewards_safe_error_response($e, 'redemption list failed');
    }
    rewards_json_ok([
        'sub_id'             => $subId,
        'redemption_count'   => count($rows),
        'redemptions'        => $rows,
    ]);
}

if ($action === 'summary') {
    [$where, $args] = $buildFilter();
    try {
        $total = $pdo->prepare(
            "SELECT COUNT(*) AS `n`,
                    COUNT(DISTINCT COALESCE(r.`redeemer_email`, r.`redeemer_key`)) AS `unique_redeemers`,
                    COALESCE(SUM(r.`points_awarded`), 0) AS `points_sum`,
                    MAX(r.`redeemed_at`) AS `last_redeemed_at`
               FROM `rewards_redemption` r
              WHERE $where"
        );
        $total->execute($args);
        $t = $total->fetch(PDO::FETCH_ASSOC) ?: [];

        $byCcy = $pdo->prepare(
            "SELECT r.`currency`, COALESCE(SUM(r.`money_value`), 0) AS `money_sum`, COUNT(*) AS `n`
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
        'sub_id'             => $subId,
        'total_redemptions'  => (int) ($t['n']                ?? 0),
        'unique_redeemers'   => (int) ($t['unique_redeemers'] ?? 0),
        'total_points'       => (int) ($t['points_sum']       ?? 0),
        'last_redeemed_at'   => $t['last_redeemed_at']        ?? null,
        'by_currency'        => $byCurrency,
    ]);
}

rewards_json_err('unknown action: ' . $action, 400);
