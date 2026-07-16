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

/* hsa_eligible lands in migration 010 — same probe, same reason. The WBM
   export segments on this (All / HSA-Eligible / HSA-Non-Eligible): HSA-eligible
   points are the ones handed to the client's external system for a real credit
   into the participant's HSA. Filter: ?hsa=eligible|non|all (default all). */
$hasHsa = false;
try {
    $hasHsa = ((int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rewards_item'
            AND COLUMN_NAME = 'hsa_eligible'"
    )->fetchColumn()) > 0;
} catch (Throwable $e) { $hasHsa = false; }

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
$buildFilter = function () use ($consumer, $subId, $hasVoided, $hasHsa): array {
    $where = ['r.`consumer_id` = ?', 'r.`sub_id` = ?'];
    $args  = [(int) $consumer['id'], $subId];
    if ($hasHsa) {
        /* ?hsa=eligible -> only items flagged HSA-eligible; ?hsa=non -> only
           those not flagged. Anything else (incl. absent) = all. */
        $hf = strtolower(trim((string) ($_GET['hsa'] ?? '')));
        if ($hf === 'eligible')            $where[] = 'i.`hsa_eligible` = 1';
        elseif ($hf === 'non' || $hf === 'non-eligible') $where[] = 'i.`hsa_eligible` = 0';
    }
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
    $hsaCols  = $hasHsa    ? ", i.`hsa_eligible`" : '';
    try {
        $st = $pdo->prepare(
            "SELECT r.`id`, r.`redeemed_at`,
                    r.`rewards_item_id`, i.`name` AS `item_name`, i.`location` AS `item_location`,
                    r.`sub_id`,
                    r.`redeemer_email`, r.`redeemer_key`,
                    r.`points_awarded`, r.`money_value`, r.`currency`,
                    r.`user_agent`" . $voidCols . $hsaCols . "
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

/* ── action=manual_award ───────────────────────────────────────────────────
   2026-07-17 (Marty). The QR-failure fallback. The client is retiring the
   paper attendance sheet, so when a scan fails there was previously NO way to
   award a person their points short of direct DB access.

   Re-uses the reward definition: same item, same points_allocated, same
   money_value_per_point — an admin cannot invent a value, only attribute an
   existing reward to a person. That keeps manual awards inside the client's
   agreed schedule.

   Deliberately NOT reusing /v1/redeem.php: that path is authenticated BY the
   qr_token (the token IS the credential) and carries the WBM membership hard
   gate + per-IP rate limit, all aimed at a participant scanning their own
   phone. This is an authenticated admin acting on someone else's behalf —
   different actor, different auth (consumer key), different audit needs.

   POST { item_id, email, actor }  — actor = the admin doing it. */
if ($action === 'manual_award') {
    if ($method !== 'POST') rewards_json_err('POST required', 405);

    $itemId = (int) $param('item_id', 0);
    $email  = strtolower(trim((string) $param('email', '')));
    $actor  = trim((string) $param('actor', ''));

    if ($itemId <= 0) rewards_json_err('item_id required', 400);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        rewards_json_err('a valid email is required — it is the key the external system credits', 400);
    }
    if ($actor === '') rewards_json_err('actor required — a manual award must record who made it', 400);

    /* Item must exist, be active, and belong to THIS consumer + sub. Never
       trust an item_id from the client. */
    $st = $pdo->prepare("SELECT `id`, `name`, `points_allocated`, `money_value_per_point`, `currency`, `is_active`
                           FROM `rewards_item`
                          WHERE `id` = ? AND `consumer_id` = ? AND `sub_id` = ? LIMIT 1");
    $st->execute([$itemId, (int) $consumer['id'], $subId]);
    $item = $st->fetch(PDO::FETCH_ASSOC);
    if (!$item)                      rewards_json_err('reward not found for this subscription', 404);
    if ((int) $item['is_active'] !== 1) rewards_json_err('that reward is not active', 409);

    $points = (int) $item['points_allocated'];
    $money  = round($points * (float) $item['money_value_per_point'], 4);

    /* Provenance columns land in mig012 — probe so this deploys ahead of it. */
    $hasSrc = false;
    try {
        $hasSrc = ((int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rewards_redemption'
                AND COLUMN_NAME = 'award_source'"
        )->fetchColumn()) > 0;
    } catch (Throwable $e) { $hasSrc = false; }
    if (!$hasSrc) rewards_json_err('manual awards need migration 012 — run api/migrate/run.php', 409);

    try {
        $ins = $pdo->prepare(
            "INSERT INTO `rewards_redemption`
               (`consumer_id`, `rewards_item_id`, `sub_id`, `redeemer_email`,
                `points_awarded`, `money_value`, `currency`,
                `award_source`, `awarded_by_email`, `redeemed_at`)
             VALUES (?,?,?,?,?,?,?, 'MANUAL', ?, UTC_TIMESTAMP())"
        );
        $ins->execute([
            (int) $consumer['id'], $itemId, $subId, $email,
            $points, $money, (string) $item['currency'], $actor,
        ]);
        $newId = (int) $pdo->lastInsertId();
    } catch (Throwable $e) {
        rewards_safe_error_response($e, 'manual award failed');
    }

    try {
        $hasAudit = ((int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rewards_audit'"
        )->fetchColumn()) > 0;
        if ($hasAudit) {
            $pdo->prepare("INSERT INTO `rewards_audit`
                             (`actor_admin_user_id`, `action`, `entity_type`, `entity_id`, `details`)
                           VALUES (NULL, 'redemption_manual_award', 'rewards_redemption', ?, ?)")
                ->execute([$newId, json_encode([
                    'actor'  => $actor, 'email' => $email, 'sub_id' => $subId,
                    'item_id' => $itemId, 'item' => $item['name'],
                    'points' => $points, 'money' => $money,
                ], JSON_UNESCAPED_SLASHES)]);
        }
    } catch (Throwable $e) { /* non-fatal */ }

    rewards_json_ok([
        'id' => $newId, 'email' => $email, 'item' => $item['name'],
        'points' => $points, 'money_value' => $money,
        'currency' => $item['currency'], 'awarded_by' => $actor,
    ]);
}

/* ── action=delete ─────────────────────────────────────────────────────────
   2026-07-17 (Marty) — super-admin hard delete. Distinct from void: void keeps
   the row and its history (the normal correction), delete removes it entirely.
   Use only to purge test/erroneous data. */
if ($action === 'delete') {
    if ($method !== 'POST') rewards_json_err('POST required', 405);
    $id    = (int) $param('id', 0);
    $actor = trim((string) $param('actor', 'bank-super-admin'));
    if ($id <= 0) rewards_json_err('id required', 400);

    $st = $pdo->prepare("SELECT `id`, `redeemer_email`, `points_awarded`, `money_value`
                           FROM `rewards_redemption`
                          WHERE `id` = ? AND `consumer_id` = ? AND `sub_id` = ? LIMIT 1");
    $st->execute([$id, (int) $consumer['id'], $subId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) rewards_json_err('redemption not found for this subscription', 404);

    /* Audit BEFORE the delete — afterwards there is nothing left to describe. */
    try {
        $hasAudit = ((int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rewards_audit'"
        )->fetchColumn()) > 0;
        if ($hasAudit) {
            $pdo->prepare("INSERT INTO `rewards_audit`
                             (`actor_admin_user_id`, `action`, `entity_type`, `entity_id`, `details`)
                           VALUES (NULL, 'redemption_delete', 'rewards_redemption', ?, ?)")
                ->execute([$id, json_encode([
                    'actor' => $actor, 'sub_id' => $subId,
                    'deleted_row' => $row,
                ], JSON_UNESCAPED_SLASHES)]);
        }
    } catch (Throwable $e) { /* non-fatal */ }

    try {
        $pdo->prepare("DELETE FROM `rewards_redemption` WHERE `id` = ? AND `consumer_id` = ? AND `sub_id` = ?")
            ->execute([$id, (int) $consumer['id'], $subId]);
    } catch (Throwable $e) {
        rewards_safe_error_response($e, 'delete failed');
    }
    rewards_json_ok(['id' => $id, 'deleted' => true]);
}

rewards_json_err('unknown action: ' . $action, 400);
