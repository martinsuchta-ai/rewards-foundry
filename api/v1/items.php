<?php
/**
 * /v1/items.php — public consumer API: list reward items.
 *
 *   GET ?sub_id=SUB-XXX[&include_inactive=1]
 *   Headers: X-Consumer-Key: <api_key>     (preferred)
 *   OR query: ?consumer_key=<api_key>      (fallback)
 *
 * Returns:
 *   { ok, items: [ { id, name, location, points_allocated, money_value_per_point,
 *                    currency, max_redemptions_per_person, qr_token, is_active,
 *                    created_at, updated_at, created_by_email,
 *                    theme_primary_hex, logo_url,
 *                    public_url, qr_png_url, redemption_count } ] }
 *
 * Scope: items WHERE consumer_id = <auth'd consumer> AND sub_id = ?.
 * Default is_active = 1 only; include_inactive=1 returns everything.
 *
 * No write surface here -- writes go via /admin/items.php (admin-
 * session-gated) or, in the WBM-bank-super flow, via a /v1/items
 * POST endpoint that we add in Phase D when the proxy lands.
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

$consumer = rewards_require_consumer();   /* 401s on bad/missing key */

$subId = trim((string) ($_GET['sub_id'] ?? ''));
if ($subId === '' || !preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $subId)) {
    rewards_json_err('sub_id required (alphanumeric / dash / underscore, 1-64 chars)', 400);
}
$includeInactive = !empty($_GET['include_inactive']);

try {
    $pdo = rewards_db();
    $sql = "SELECT i.`id`, i.`sub_id`, i.`name`, i.`location`,
                   i.`points_allocated`, i.`money_value_per_point`, i.`currency`,
                   i.`max_redemptions_per_person`, i.`qr_token`,
                   i.`theme_primary_hex`, i.`logo_url`,
                   i.`is_active`, i.`created_at`, i.`updated_at`, i.`created_by_email`,
                   (SELECT COUNT(*) FROM `rewards_redemption` r
                      WHERE r.`rewards_item_id` = i.`id`) AS `redemption_count`
              FROM `rewards_item` i
             WHERE i.`consumer_id` = ?
               AND i.`sub_id`      = ?
            " . ($includeInactive ? '' : 'AND i.`is_active` = 1') . "
             ORDER BY i.`created_at` DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([(int) $consumer['id'], $subId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    rewards_safe_error_response($e, 'item list failed');
}

/* Decorate each row with the public redemption URL + QR PNG URL on
   THIS domain (rewards-foundry.com). Computed at response time so the
   stored values stay clean (no URLs in the DB). */
$proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host  = $_SERVER['HTTP_HOST'] ?? 'www.rewards-foundry.com';
$base  = $proto . '://' . $host;

foreach ($items as &$it) {
    $tok = (string) $it['qr_token'];
    $it['public_url'] = $base . '/redeem?t=' . rawurlencode($tok);
    $it['qr_png_url'] = $base . '/api/v1/qr.php?t=' . rawurlencode($tok);
}
unset($it);

rewards_json_ok([
    'consumer'      => ['id' => (int) $consumer['id'], 'name' => $consumer['name']],
    'sub_id'        => $subId,
    'item_count'    => count($items),
    'items'         => $items,
]);
