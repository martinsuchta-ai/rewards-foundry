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
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Consumer-Key');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$consumer = rewards_require_consumer();   /* 401s on bad/missing key */
$action   = (string) ($_GET['action'] ?? '');

$proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host  = $_SERVER['HTTP_HOST'] ?? 'www.rewards-foundry.com';
$base  = $proto . '://' . $host;
/* QR PNG endpoint stamps Cache-Control: public, max-age=86400 so
   browsers + intermediate caches will keep serving any previously-
   composited PNG for up to a day. Bump &v=... here whenever the
   composition contract changes (logo ratio, padding, background
   colour logic, etc.) so the URL changes and caches miss. */
$qrCacheBust = '20260622-white-logo-recolour';
$decorate = function (array $row) use ($base, $qrCacheBust): array {
    $tok = (string) $row['qr_token'];
    $row['public_url'] = $base . '/redeem?t=' . rawurlencode($tok);
    $row['qr_png_url'] = $base . '/api/v1/qr.php?t=' . rawurlencode($tok) . '&v=' . $qrCacheBust;
    return $row;
};

/* ── POST create / update / delete ────────────────────────────
   Bank Super (and any other consumer) needs CRUD; the admin UI on
   this domain has its own admin-session endpoint at
   /api/admin/items.php with full visibility. /v1/items.php is the
   per-consumer CRUD: every write is automatically scoped to the
   auth'd consumer's items, so consumer A can't mutate consumer B's
   rows even via id-guessing. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($body)) $body = [];

    $pdo = rewards_db();

    /* CREATE ───────────────────────────────────────────────── */
    if ($action === 'create') {
        $subId = trim((string) ($body['sub_id'] ?? ''));
        $name  = trim((string) ($body['name']   ?? ''));
        if ($subId === '' || !preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $subId)) {
            rewards_json_err('sub_id required (alphanumeric / dash / underscore, 1-64)', 400);
        }
        if ($name === '') rewards_json_err('name required', 400);

        /* Mint a unique qr_token (32 hex chars). */
        $token = '';
        for ($i = 0; $i < 8; $i++) {
            $cand = bin2hex(random_bytes(16));
            $chk  = $pdo->prepare("SELECT 1 FROM `rewards_item` WHERE `qr_token` = ? LIMIT 1");
            $chk->execute([$cand]);
            if (!$chk->fetchColumn()) { $token = $cand; break; }
        }
        if ($token === '') rewards_json_err('qr_token mint failed', 500);

        try {
            $ins = $pdo->prepare(
                "INSERT INTO `rewards_item`
                   (`consumer_id`, `sub_id`, `name`, `location`,
                    `points_allocated`, `money_value_per_point`, `currency`,
                    `max_redemptions_per_person`,
                    `qr_token`, `theme_primary_hex`, `logo_url`,
                    `is_active`, `created_by_email`)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
            );
            $ins->execute([
                (int)   $consumer['id'],
                $subId,
                mb_substr($name, 0, 160),
                isset($body['location'])              ? trim((string) $body['location'])              : null,
                isset($body['points_allocated'])      ? (int)   $body['points_allocated']             : 0,
                isset($body['money_value_per_point']) ? (float) $body['money_value_per_point']        : 0,
                isset($body['currency'])              ? strtoupper(trim((string) $body['currency'])) : 'AUD',
                isset($body['max_redemptions_per_person']) && $body['max_redemptions_per_person'] !== ''
                    ? (int) $body['max_redemptions_per_person'] : null,
                $token,
                isset($body['theme_primary_hex']) ? trim((string) $body['theme_primary_hex']) : null,
                isset($body['logo_url'])          ? trim((string) $body['logo_url'])          : null,
                array_key_exists('is_active', $body) ? (!empty($body['is_active']) ? 1 : 0) : 1,
                isset($body['created_by_email']) ? trim((string) $body['created_by_email']) : null,
            ]);
            $id = (int) $pdo->lastInsertId();

            $st = $pdo->prepare(
                "SELECT i.*, 0 AS `redemption_count`
                   FROM `rewards_item` i
                  WHERE i.`id` = ? AND i.`consumer_id` = ? LIMIT 1"
            );
            $st->execute([$id, (int) $consumer['id']]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            rewards_safe_error_response($e, 'create failed');
        }
        http_response_code(201);
        rewards_json_ok(['item' => $decorate($row)], 201);
    }

    /* UPDATE ───────────────────────────────────────────────── */
    if ($action === 'update') {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) rewards_json_err('id required', 400);

        /* Build SET clause from supplied editable fields. consumer_id,
           sub_id, qr_token, legacy_wbm_id are immutable. */
        $cols = []; $args = [];
        foreach ([
            'name', 'location', 'points_allocated', 'money_value_per_point',
            'currency', 'max_redemptions_per_person',
            'theme_primary_hex', 'logo_url', 'is_active'
        ] as $k) {
            if (!array_key_exists($k, $body)) continue;
            $v = $body[$k];
            if ($k === 'currency')        $v = $v === null ? null : strtoupper(trim((string) $v));
            if ($k === 'points_allocated') $v = (int) $v;
            if ($k === 'money_value_per_point') $v = (float) $v;
            if ($k === 'max_redemptions_per_person') $v = ($v === '' || $v === null) ? null : (int) $v;
            if ($k === 'is_active') $v = !empty($v) ? 1 : 0;
            if (is_string($v) && in_array($k, ['name','location','theme_primary_hex','logo_url'], true)) {
                $v = trim($v);
                if ($k === 'name' && $v === '') continue;   /* never blank-name */
            }
            $cols[] = '`' . $k . '` = ?';
            $args[] = $v;
        }
        if (!$cols) rewards_json_err('no updatable fields supplied', 400);
        $args[] = $id;
        $args[] = (int) $consumer['id'];

        try {
            $upd = $pdo->prepare(
                "UPDATE `rewards_item` SET " . implode(', ', $cols) .
                " WHERE `id` = ? AND `consumer_id` = ?"
            );
            $upd->execute($args);
            if ($upd->rowCount() === 0) {
                /* Either no such id OR id belongs to a different consumer.
                   Either way, this consumer sees "not found". */
                rewards_json_err('item not found', 404);
            }

            $st = $pdo->prepare(
                "SELECT i.*,
                        (SELECT COUNT(*) FROM `rewards_redemption` rr
                           WHERE rr.`rewards_item_id` = i.`id`) AS `redemption_count`
                   FROM `rewards_item` i
                  WHERE i.`id` = ? AND i.`consumer_id` = ?"
            );
            $st->execute([$id, (int) $consumer['id']]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            rewards_safe_error_response($e, 'update failed');
        }
        rewards_json_ok(['item' => $decorate($row)]);
    }

    /* DELETE (soft -- is_active=0) ─────────────────────────── */
    if ($action === 'delete') {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) rewards_json_err('id required', 400);
        try {
            $upd = $pdo->prepare(
                "UPDATE `rewards_item` SET `is_active` = 0
                  WHERE `id` = ? AND `consumer_id` = ?"
            );
            $upd->execute([$id, (int) $consumer['id']]);
            if ($upd->rowCount() === 0) rewards_json_err('item not found', 404);
        } catch (Throwable $e) {
            rewards_safe_error_response($e, 'delete failed');
        }
        rewards_json_ok(['item_id' => $id, 'is_active' => 0]);
    }

    rewards_json_err('unknown POST action: ' . $action, 400);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') rewards_json_err('GET or POST required', 405);

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

/* Decorate each row via the $decorate closure defined up top
   (single source of truth for the public_url + qr_png_url shape). */
$items = array_map($decorate, $items);

rewards_json_ok([
    'consumer'      => ['id' => (int) $consumer['id'], 'name' => $consumer['name']],
    'sub_id'        => $subId,
    'item_count'    => count($items),
    'items'         => $items,
]);
