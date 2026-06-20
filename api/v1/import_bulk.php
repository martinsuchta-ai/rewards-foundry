<?php
/**
 * /v1/import_bulk.php — bulk import from a consumer's existing data.
 *
 * Phase C of the carve-out: WBM's
 * `api/admin/export_to_rewards_foundry.php` collates every
 * reward_items + reward_redemptions row + posts them here in one
 * call. Idempotent on repeat — both sides dedup on `legacy_wbm_id`
 * (migration 002 column).
 *
 *   POST body JSON:
 *     {
 *       "items": [
 *         { "legacy_wbm_id":  bigint,            (required, dedup key)
 *           "sub_id":         "SUB-XXX",
 *           "name":           "Coffee voucher",
 *           "location":       "Cafe X",          (optional)
 *           "points_allocated":             5,
 *           "money_value_per_point":        0.5,
 *           "currency":       "AUD",
 *           "max_redemptions_per_person":   3,    (nullable)
 *           "qr_token":       "32 hex chars",    (required, PRESERVED VERBATIM)
 *           "theme_primary_hex": "#e8621a",      (optional)
 *           "logo_url":          "https://...",   (optional)
 *           "is_active":      1,
 *           "created_at":     "2026-06-19 ...",  (mirrored to preserve audit)
 *           "created_by_email": "admin@..."
 *         },
 *         ...
 *       ],
 *       "redemptions": [
 *         { "legacy_wbm_id":  bigint,            (required, dedup key)
 *           "qr_token":       "32 hex chars",    (looked up -> rewards_item.id)
 *           "sub_id":         "SUB-XXX",
 *           "redeemer_email": "...",             (one of these two required)
 *           "redeemer_key":   "...",
 *           "points_awarded": 5,
 *           "money_value":    2.5,
 *           "currency":       "AUD",
 *           "ip_hash":        "...",             (sha256 from WBM; we just copy verbatim)
 *           "user_agent":     "...",
 *           "redeemed_at":    "2026-06-20 ..."
 *         },
 *         ...
 *       ]
 *     }
 *
 *   Headers: X-Consumer-Key: <api_key>     (required)
 *
 *   Response:
 *     { ok: true,
 *       items: { received, inserted, updated, skipped, errors:[] },
 *       redemptions: { received, inserted, skipped_dupe, skipped_no_item, errors:[] } }
 */

declare(strict_types=1);
@set_time_limit(120);

require_once __DIR__ . '/../rewards_bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../rewards_consumer_auth.php';

header('Content-Type: application/json; charset=utf-8');
rewards_send_cors_origin();
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Consumer-Key');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    rewards_json_err('POST required', 405);

$consumer = rewards_require_consumer();   /* 401s on bad/missing key */

$raw = file_get_contents('php://input') ?: '';
if (strlen($raw) > 8 * 1024 * 1024) rewards_json_err('payload too large (8MB cap)', 413);
$body = json_decode($raw, true);
if (!is_array($body)) rewards_json_err('JSON body required', 400);

$itemsIn = is_array($body['items']       ?? null) ? $body['items']       : [];
$redsIn  = is_array($body['redemptions'] ?? null) ? $body['redemptions'] : [];

$pdo = rewards_db();

$itemStats = ['received' => count($itemsIn), 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
$redStats  = ['received' => count($redsIn),  'inserted' => 0, 'skipped_dupe' => 0, 'skipped_no_item' => 0, 'errors' => []];

/* ── Items ──────────────────────────────────────────────────────
   Dedup on legacy_wbm_id (UNIQUE in migration 002). INSERT ON
   DUPLICATE KEY UPDATE so re-runs refresh editable fields without
   touching the qr_token (immutable — printed in the field). */
$itemUpsert = $pdo->prepare(
    "INSERT INTO `rewards_item`
       (`consumer_id`, `sub_id`, `name`, `location`,
        `points_allocated`, `money_value_per_point`, `currency`,
        `max_redemptions_per_person`,
        `qr_token`, `theme_primary_hex`, `logo_url`,
        `is_active`, `created_at`, `created_by_email`,
        `legacy_wbm_id`)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
     ON DUPLICATE KEY UPDATE
       `sub_id`                     = VALUES(`sub_id`),
       `name`                       = VALUES(`name`),
       `location`                   = VALUES(`location`),
       `points_allocated`           = VALUES(`points_allocated`),
       `money_value_per_point`      = VALUES(`money_value_per_point`),
       `currency`                   = VALUES(`currency`),
       `max_redemptions_per_person` = VALUES(`max_redemptions_per_person`),
       `theme_primary_hex`          = VALUES(`theme_primary_hex`),
       `logo_url`                   = VALUES(`logo_url`),
       `is_active`                  = VALUES(`is_active`)
       /* qr_token + created_at + created_by_email NOT updated --
          immutable / first-write-wins semantics. */"
);

foreach ($itemsIn as $idx => $it) {
    if (!is_array($it)) { $itemStats['skipped']++; continue; }
    $lid = isset($it['legacy_wbm_id']) ? (int) $it['legacy_wbm_id'] : 0;
    $tok = trim((string) ($it['qr_token'] ?? ''));
    if ($lid <= 0 || $tok === '' || !preg_match('/^[A-Za-z0-9_\-]{16,64}$/', $tok)) {
        $itemStats['skipped']++;
        $itemStats['errors'][] = "items[$idx]: missing legacy_wbm_id or invalid qr_token";
        continue;
    }
    try {
        /* Is this legacy_wbm_id already in the table? Drives the
           inserted-vs-updated counter. */
        $chk = $pdo->prepare("SELECT 1 FROM `rewards_item` WHERE `legacy_wbm_id` = ? LIMIT 1");
        $chk->execute([$lid]);
        $existed = (bool) $chk->fetchColumn();

        $itemUpsert->execute([
            (int)   $consumer['id'],
            (string)($it['sub_id'] ?? ''),
            (string)($it['name']   ?? ''),
                     ($it['location'] ?? null),
            (int)   ($it['points_allocated']      ?? 0),
            (float) ($it['money_value_per_point'] ?? 0),
            (string)($it['currency']              ?? 'AUD'),
                     ($it['max_redemptions_per_person'] ?? null),
            $tok,
                     ($it['theme_primary_hex'] ?? null),
                     ($it['logo_url']          ?? null),
            !empty($it['is_active']) ? 1 : 0,
                     ($it['created_at']       ?? gmdate('Y-m-d H:i:s')),
                     ($it['created_by_email'] ?? null),
            $lid,
        ]);
        if ($existed) $itemStats['updated']++;
        else          $itemStats['inserted']++;
    } catch (Throwable $e) {
        $itemStats['skipped']++;
        $itemStats['errors'][] = "items[$idx] (legacy_wbm_id=$lid): " . $e->getMessage();
    }
}

/* ── Redemptions ────────────────────────────────────────────────
   Dedup on legacy_wbm_id (UNIQUE in migration 002). Map to the new
   rewards_item.id via qr_token lookup. INSERT IGNORE — if the row
   already exists, silently skip + count. */
$lookupItem = $pdo->prepare("SELECT `id` FROM `rewards_item` WHERE `qr_token` = ? LIMIT 1");
$redInsert  = $pdo->prepare(
    "INSERT IGNORE INTO `rewards_redemption`
       (`consumer_id`, `rewards_item_id`, `sub_id`,
        `redeemer_email`, `redeemer_key`,
        `points_awarded`, `money_value`, `currency`,
        `ip_hash`, `user_agent`,
        `redeemed_at`, `legacy_wbm_id`)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
);

/* Cache token→item_id lookups within this request (a busy item
   might have many redemptions in one import). */
$tokenToItemId = [];

foreach ($redsIn as $idx => $r) {
    if (!is_array($r)) { $redStats['skipped_no_item']++; continue; }
    $lid = isset($r['legacy_wbm_id']) ? (int) $r['legacy_wbm_id'] : 0;
    $tok = trim((string) ($r['qr_token'] ?? ''));
    if ($lid <= 0 || $tok === '') {
        $redStats['skipped_no_item']++;
        $redStats['errors'][] = "redemptions[$idx]: missing legacy_wbm_id or qr_token";
        continue;
    }
    /* Resolve item id by qr_token (cached). */
    if (!array_key_exists($tok, $tokenToItemId)) {
        try {
            $lookupItem->execute([$tok]);
            $iid = $lookupItem->fetchColumn();
            $tokenToItemId[$tok] = $iid !== false ? (int) $iid : null;
        } catch (Throwable $e) {
            $tokenToItemId[$tok] = null;
            $redStats['errors'][] = "redemptions[$idx] qr_token lookup failed: " . $e->getMessage();
        }
    }
    $itemId = $tokenToItemId[$tok];
    if (!$itemId) {
        $redStats['skipped_no_item']++;
        $redStats['errors'][] = "redemptions[$idx] (legacy_wbm_id=$lid): no rewards_item with qr_token=$tok -- import items first";
        continue;
    }
    try {
        $redInsert->execute([
            (int)   $consumer['id'],
            $itemId,
            (string)($r['sub_id'] ?? ''),
                     ($r['redeemer_email'] ?? null),
                     ($r['redeemer_key']   ?? null),
            isset($r['points_awarded']) ? (int)   $r['points_awarded'] : null,
            isset($r['money_value'])    ? (float) $r['money_value']    : null,
                     ($r['currency']    ?? null),
                     ($r['ip_hash']     ?? null),
                     ($r['user_agent']  ?? null),
                     ($r['redeemed_at'] ?? gmdate('Y-m-d H:i:s')),
            $lid,
        ]);
        if ($redInsert->rowCount() > 0) $redStats['inserted']++;
        else                            $redStats['skipped_dupe']++;
    } catch (Throwable $e) {
        $redStats['errors'][] = "redemptions[$idx] (legacy_wbm_id=$lid): " . $e->getMessage();
    }
}

rewards_json_ok([
    'consumer'    => ['id' => (int) $consumer['id'], 'name' => $consumer['name']],
    'items'       => $itemStats,
    'redemptions' => $redStats,
]);
