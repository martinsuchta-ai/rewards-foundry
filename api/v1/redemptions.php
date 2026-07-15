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
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Consumer-Key');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET' && $method !== 'POST') rewards_json_err('GET or POST required', 405);

$consumer = rewards_require_consumer();
$action   = (string) ($_GET['action'] ?? $_POST['action'] ?? 'list');

$subId = trim((string) ($_GET['sub_id'] ?? $_POST['sub_id'] ?? ''));
if ($subId === '' || !preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $subId)) {
    rewards_json_err('sub_id required (alphanumeric / dash / underscore, 1-64)', 400);
}

$pdo = rewards_db();

/* The void columns land in migration 009 — probe once so this endpoint is
   safe to deploy AHEAD of the migration. Everything voided-related no-ops
   until the column exists. */
$hasVoided = false;
try {
    $hasVoided = ((int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rewards_redemption'
            AND COLUMN_NAME = 'voided'"
    )->fetchColumn()) > 0;
} catch (Throwable $e) { $hasVoided = false; }

/* Body params for POST mutations — accept JSON or form, fall back to query. */
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

/* Shared WHERE — always consumer_id-scoped + sub_id-scoped. Voided rows are
   EXCLUDED by default; ?voided=only shows only voided, ?voided=all shows both
   (include_voided=1 is a legacy alias for 'all'). */
$buildFilter = function () use ($consumer, $subId, $hasVoided): array {
    $where = ['r.`consumer_id` = ?', 'r.`sub_id` = ?'];
    $args  = [(int) $consumer['id'], $subId];
    if ($hasVoided) {
        $vf  = strtolower(trim((string) ($_GET['voided'] ?? '')));
        $all = ($vf === 'all') || !empty($_GET['include_voided']);
        if ($vf === 'only')  $where[] = 'r.`voided` = 1';
        elseif (!$all)       $where[] = 'r.`voided` = 0';
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

if ($action === 'list') {
    [$where, $args] = $buildFilter();
    $limit = (int) ($_GET['limit'] ?? 200);
    if ($limit < 1)   $limit = 200;
    if ($limit > 5000) $limit = 5000;

    $voidCols = $hasVoided ? ", r.`voided`, r.`void_reason`, r.`voided_at`, r.`voided_by`" : '';
    try {
        $st = $pdo->prepare(
            "SELECT r.`id`, r.`redeemed_at`,
                    r.`rewards_item_id`, i.`name` AS `item_name`, i.`location` AS `item_location`,
                    r.`sub_id`,
                    r.`redeemer_email`, r.`redeemer_key`,
                    r.`points_awarded`, r.`money_value`, r.`currency`,
                    r.`user_agent`" . $voidCols . "
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

/* ── VOID / UN-VOID a redemption (POST) ──────────────────────────────────
   Soft-void: flags the row + records reason/who/when, keeps it for audit.
   Scoped to this consumer + sub. Voided rows drop out of the default list,
   the summary KPIs, and per-item counts. Un-void reverses an accidental
   void. Both are also written to rewards_audit (best-effort). */
if ($action === 'void' || $action === 'unvoid') {
    if ($method !== 'POST')  rewards_json_err('POST required for ' . $action, 405);
    if (!$hasVoided)         rewards_json_err('void unavailable — apply migration 009 first', 409);

    $id = (int) $param('id', 0);
    if ($id < 1) rewards_json_err('id required', 400);
    $actor = mb_substr(trim((string) $param('actor', '')) ?: 'bank-admin', 0, 255);
    $reason = mb_substr(trim((string) $param('reason', '')), 0, 500);
    if ($action === 'void' && $reason === '') rewards_json_err('reason required to void', 400);

    /* Confirm the redemption is in THIS consumer + sub scope before mutating. */
    try {
        $chk = $pdo->prepare("SELECT `id` FROM `rewards_redemption`
                               WHERE `id` = ? AND `consumer_id` = ? AND `sub_id` = ? LIMIT 1");
        $chk->execute([$id, (int) $consumer['id'], $subId]);
        if (!$chk->fetchColumn()) rewards_json_err('redemption not found in this scope', 404);
    } catch (Throwable $e) { rewards_safe_error_response($e, $action . ' lookup failed'); }

    try {
        if ($action === 'void') {
            $pdo->prepare("UPDATE `rewards_redemption`
                              SET `voided` = 1, `void_reason` = ?, `voided_at` = NOW(), `voided_by` = ?
                            WHERE `id` = ? AND `consumer_id` = ? AND `sub_id` = ?")
                ->execute([$reason, $actor, $id, (int) $consumer['id'], $subId]);
        } else {
            $pdo->prepare("UPDATE `rewards_redemption`
                              SET `voided` = 0, `void_reason` = NULL, `voided_at` = NULL, `voided_by` = NULL
                            WHERE `id` = ? AND `consumer_id` = ? AND `sub_id` = ?")
                ->execute([$id, (int) $consumer['id'], $subId]);
        }
    } catch (Throwable $e) { rewards_safe_error_response($e, $action . ' failed'); }

    /* Best-effort audit trail (rewards_audit; NULL actor_admin_user_id — the
       actor is a WM-side bank admin, carried in details). */
    try {
        $hasAudit = ((int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rewards_audit'"
        )->fetchColumn()) > 0;
        if ($hasAudit) {
            $pdo->prepare("INSERT INTO `rewards_audit`
                             (`actor_admin_user_id`, `action`, `entity_type`, `entity_id`, `details`)
                           VALUES (NULL, ?, 'rewards_redemption', ?, ?)")
                ->execute([
                    'redemption_' . $action, $id,
                    json_encode([
                        'action' => $action,
                        'reason' => $action === 'void' ? $reason : null,
                        'actor'  => $actor,
                        'sub_id' => $subId,
                    ], JSON_UNESCAPED_SLASHES),
                ]);
        }
    } catch (Throwable $e) { /* non-fatal */ }

    rewards_json_ok(['id' => $id, 'action' => $action, 'voided' => ($action === 'void')]);
}

rewards_json_err('unknown action: ' . $action, 400);
