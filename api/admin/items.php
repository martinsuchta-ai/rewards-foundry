<?php
/**
 * api/admin/items.php — admin CRUD on rewards_item.
 *
 * All actions require a valid admin session. Admin has visibility into
 * EVERY consumer's items (vs the public /v1/items.php which is
 * scoped to the auth'd consumer).
 *
 *   GET ?action=list[&consumer_id=N][&sub_id=SUB-XXX][&include_inactive=1]
 *     Lists rewards_item rows with the same row-shape /v1/items.php
 *     returns, plus consumer_name (joined).
 *
 *   POST ?action=create
 *     body: { consumer_id, sub_id, name, location?, points_allocated?,
 *             money_value_per_point?, currency?, max_redemptions_per_person?,
 *             theme_primary_hex?, logo_url? }
 *     Server mints the qr_token (32 hex chars, UNIQUE-checked).
 *     → 201 { ok, item } with all decorated URLs.
 *
 *   POST ?action=update&id=N
 *     body: same as create EXCEPT qr_token (immutable -- printed in
 *     the field). consumer_id also immutable; if you need to re-
 *     attribute an item, delete + recreate.
 *     → 200 { ok, item }
 *
 *   POST ?action=delete&id=N
 *     Sets is_active=0 (soft delete). Hard delete is not exposed --
 *     redemptions are FK'd ON DELETE CASCADE so a hard delete would
 *     lose audit data.
 *
 *   GET ?action=get&id=N
 *     Single item lookup for the editor form.
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

rewards_admin_require_session();   /* 401s if no valid session */

$action = (string) ($_GET['action'] ?? '');
$pdo    = rewards_db();

/* Resolve the public URLs each row carries. Same shape /v1/items.php uses. */
$proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host  = $_SERVER['HTTP_HOST'] ?? 'www.rewards-foundry.com';
$base  = $proto . '://' . $host;
$decorate = function (array $row) use ($base): array {
    $tok = (string) $row['qr_token'];
    $row['public_url'] = $base . '/redeem?t=' . rawurlencode($tok);
    $row['qr_png_url'] = $base . '/api/v1/qr.php?t=' . rawurlencode($tok);
    return $row;
};

/* ── LIST ─────────────────────────────────────────────────────── */
if ($action === 'list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $consumerId      = isset($_GET['consumer_id']) ? (int) $_GET['consumer_id'] : 0;
    $subId           = trim((string) ($_GET['sub_id'] ?? ''));
    $includeInactive = !empty($_GET['include_inactive']);

    $where = ['1=1'];
    $args  = [];
    if ($consumerId > 0) { $where[] = 'i.`consumer_id` = ?'; $args[] = $consumerId; }
    if ($subId !== '')   { $where[] = 'i.`sub_id`      = ?'; $args[] = $subId; }
    if (!$includeInactive) $where[] = 'i.`is_active` = 1';

    try {
        $st = $pdo->prepare(
            "SELECT i.*, c.`name` AS `consumer_name`,
                    (SELECT COUNT(*) FROM `rewards_redemption` r
                       WHERE r.`rewards_item_id` = i.`id`) AS `redemption_count`
               FROM `rewards_item` i
               JOIN `rewards_consumer` c ON c.`id` = i.`consumer_id`
              WHERE " . implode(' AND ', $where) . "
              ORDER BY i.`created_at` DESC
              LIMIT 1000"
        );
        $st->execute($args);
        $rows = array_map($decorate, $st->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        rewards_safe_error_response($e, 'item list failed');
    }
    rewards_json_ok(['item_count' => count($rows), 'items' => $rows]);
}

/* ── GET (single) ─────────────────────────────────────────────── */
if ($action === 'get' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) rewards_json_err('id required', 400);
    try {
        $st = $pdo->prepare(
            "SELECT i.*, c.`name` AS `consumer_name`,
                    (SELECT COUNT(*) FROM `rewards_redemption` r
                       WHERE r.`rewards_item_id` = i.`id`) AS `redemption_count`
               FROM `rewards_item` i
               JOIN `rewards_consumer` c ON c.`id` = i.`consumer_id`
              WHERE i.`id` = ? LIMIT 1"
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        rewards_safe_error_response($e, 'item get failed');
    }
    if (!$row) rewards_json_err('item not found', 404);
    rewards_json_ok(['item' => $decorate($row)]);
}

/* ── CREATE / UPDATE shared field validation ──────────────────── */
$validate_fields = function (array $b, bool $forCreate): array {
    $out = [];

    if ($forCreate) {
        $cid = (int) ($b['consumer_id'] ?? 0);
        if ($cid <= 0) rewards_json_err('consumer_id required', 400);
        $out['consumer_id'] = $cid;

        $sub = trim((string) ($b['sub_id'] ?? ''));
        if ($sub === '' || !preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $sub)) {
            rewards_json_err('sub_id required (alphanumeric/dash/underscore, 1-64)', 400);
        }
        $out['sub_id'] = $sub;
    }

    $name = trim((string) ($b['name'] ?? ''));
    if ($forCreate && $name === '') rewards_json_err('name required', 400);
    if ($name !== '') $out['name'] = mb_substr($name, 0, 160);

    foreach (['location', 'theme_primary_hex', 'logo_url', 'redeem_image_url'] as $k) {
        if (array_key_exists($k, $b)) $out[$k] = $b[$k] === null ? null : trim((string) $b[$k]);
    }
    if (isset($out['theme_primary_hex']) && $out['theme_primary_hex'] !== '' && $out['theme_primary_hex'] !== null) {
        $h = ltrim($out['theme_primary_hex'], '#');
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $h)) {
            rewards_json_err('theme_primary_hex must be #RRGGBB hex', 400);
        }
        $out['theme_primary_hex'] = '#' . strtolower($h);
    }

    if (array_key_exists('points_allocated', $b))
        $out['points_allocated'] = (int) $b['points_allocated'];
    if (array_key_exists('money_value_per_point', $b))
        $out['money_value_per_point'] = (float) $b['money_value_per_point'];
    if (array_key_exists('currency', $b)) {
        $c = strtoupper(trim((string) $b['currency']));
        if ($c !== '' && !preg_match('/^[A-Z]{3}$/', $c)) {
            rewards_json_err('currency must be 3-letter ISO code', 400);
        }
        $out['currency'] = $c ?: 'AUD';
    }
    if (array_key_exists('max_redemptions_per_person', $b)) {
        $v = $b['max_redemptions_per_person'];
        $out['max_redemptions_per_person'] = ($v === null || $v === '') ? null : (int) $v;
    }
    if (array_key_exists('is_active', $b)) $out['is_active'] = !empty($b['is_active']) ? 1 : 0;
    if (array_key_exists('enforce_account', $b)) $out['enforce_account'] = !empty($b['enforce_account']) ? 1 : 0;
    return $out;
};

/* ── CREATE ───────────────────────────────────────────────────── */
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($b)) rewards_json_err('JSON body required', 400);
    $f = $validate_fields($b, true);

    /* Mint a unique qr_token (32 hex chars). Retry up to 8 times on
       the astronomically unlikely UNIQUE collision. */
    $token = '';
    try {
        for ($i = 0; $i < 8; $i++) {
            $candidate = bin2hex(random_bytes(16));
            $chk = $pdo->prepare("SELECT 1 FROM `rewards_item` WHERE `qr_token` = ? LIMIT 1");
            $chk->execute([$candidate]);
            if (!$chk->fetchColumn()) { $token = $candidate; break; }
        }
        if ($token === '') rewards_json_err('qr_token mint failed (8 collisions)', 500);

        $session = rewards_admin_session_resolve();
        $by      = $session ? (string) $session['email'] : null;

        $ins = $pdo->prepare(
            "INSERT INTO `rewards_item`
               (`consumer_id`, `sub_id`, `name`, `location`,
                `points_allocated`, `money_value_per_point`, `currency`,
                `max_redemptions_per_person`,
                `qr_token`, `theme_primary_hex`, `logo_url`, `redeem_image_url`,
                `enforce_account`, `is_active`, `created_by_email`)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        $ins->execute([
            $f['consumer_id'],
            $f['sub_id'],
            $f['name'] ?? '',
            $f['location'] ?? null,
            (int)   ($f['points_allocated']      ?? 0),
            (float) ($f['money_value_per_point'] ?? 0),
                    ($f['currency']              ?? 'AUD'),
                    ($f['max_redemptions_per_person'] ?? null),
            $token,
                    ($f['theme_primary_hex'] ?? null),
                    ($f['logo_url']          ?? null),
                    ($f['redeem_image_url']  ?? null),
            isset($f['enforce_account']) ? $f['enforce_account'] : 0,
            isset($f['is_active']) ? $f['is_active'] : 1,
            $by,
        ]);
        $id = (int) $pdo->lastInsertId();

        $r = $pdo->prepare(
            "SELECT i.*, c.`name` AS `consumer_name`, 0 AS `redemption_count`
               FROM `rewards_item` i
               JOIN `rewards_consumer` c ON c.`id` = i.`consumer_id`
              WHERE i.`id` = ?"
        );
        $r->execute([$id]);
        $row = $r->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        rewards_safe_error_response($e, 'item create failed');
    }
    http_response_code(201);
    rewards_json_ok(['item' => $decorate($row)], 201);
}

/* ── UPDATE ───────────────────────────────────────────────────── */
if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) rewards_json_err('id required', 400);

    $b = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($b)) rewards_json_err('JSON body required', 400);
    $f = $validate_fields($b, false);

    if (!$f) rewards_json_err('no updatable fields supplied', 400);

    /* Build SET clause from supplied fields. consumer_id, sub_id,
       qr_token are NOT updatable -- the validator never returns them
       for an UPDATE call. */
    $cols = []; $args = [];
    foreach (['name','location','points_allocated','money_value_per_point','currency',
              'max_redemptions_per_person','theme_primary_hex','logo_url','redeem_image_url','enforce_account','is_active'] as $k) {
        if (array_key_exists($k, $f)) {
            $cols[] = '`' . $k . '` = ?';
            $args[] = $f[$k];
        }
    }
    if (!$cols) rewards_json_err('no updatable fields supplied', 400);
    $args[] = $id;

    try {
        $upd = $pdo->prepare(
            "UPDATE `rewards_item` SET " . implode(', ', $cols)
            . " WHERE `id` = ?"
        );
        $upd->execute($args);
        if ($upd->rowCount() === 0) rewards_json_err('item not found', 404);

        $r = $pdo->prepare(
            "SELECT i.*, c.`name` AS `consumer_name`,
                    (SELECT COUNT(*) FROM `rewards_redemption` rr
                       WHERE rr.`rewards_item_id` = i.`id`) AS `redemption_count`
               FROM `rewards_item` i
               JOIN `rewards_consumer` c ON c.`id` = i.`consumer_id`
              WHERE i.`id` = ?"
        );
        $r->execute([$id]);
        $row = $r->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        rewards_safe_error_response($e, 'item update failed');
    }
    rewards_json_ok(['item' => $decorate($row)]);
}

/* ── DELETE (soft) ────────────────────────────────────────────── */
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) rewards_json_err('id required', 400);
    try {
        $upd = $pdo->prepare(
            "UPDATE `rewards_item` SET `is_active` = 0 WHERE `id` = ?"
        );
        $upd->execute([$id]);
        if ($upd->rowCount() === 0) rewards_json_err('item not found', 404);
    } catch (Throwable $e) {
        rewards_safe_error_response($e, 'item delete failed');
    }
    rewards_json_ok(['item_id' => $id, 'is_active' => 0]);
}

rewards_json_err('unknown action: ' . $action, 400);
