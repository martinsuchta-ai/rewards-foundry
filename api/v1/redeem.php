<?php
/**
 * /v1/redeem.php — public redemption endpoint.
 *
 *   POST body JSON: { token, email?, key? }
 *     - token required (must match a rewards_item.qr_token)
 *     - exactly ONE of email / key must be non-empty
 *
 * No consumer auth -- the token is the access credential, same as
 * /v1/qr.php. Anyone with the printed QR can redeem; that IS the
 * intended UX.
 *
 * Validations:
 *   - token format
 *   - email format (when present)
 *   - key format (when present)
 *   - max_redemptions_per_person not exceeded (when set on the item)
 *   - rate-limit by ip_hash (default 20/day; configurable via
 *     REWARDS_REDEEM_RATE_PER_DAY env var)
 *
 * NOTE on notification email: per carve-out decision 8 (address
 * later), this endpoint records the redemption but does NOT send
 * notification email yet. A row is INSERT-IGNORE'd into
 * rewards_redemption_notification with status='pending' so a future
 * cron / direct admin trigger can pick them up once we wire Zoho SMTP
 * (or whatever provider we land on).
 *
 * Returns:
 *   { ok, redemption_id, item_name, points_awarded, money_value, currency }
 */

declare(strict_types=1);

require_once __DIR__ . '/../rewards_bootstrap.php';
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');
rewards_send_cors_origin();
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

/* ── GET ?t=<token> -- return public-safe item view so the redemption
   page can render before the user submits. Mirrors the WBM dual-
   purpose pattern in rewards_redeem.php (GET = render, POST = act).
   Public-safe means: nothing about the consumer, no internal ids,
   no redemption history. */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $token = trim((string) ($_GET['t'] ?? ''));
    if ($token === '' || !preg_match('/^[A-Za-z0-9_\-]{16,64}$/', $token)) {
        rewards_json_err('valid token required', 400);
    }
    try {
        $pdo = rewards_db();
        $st = $pdo->prepare(
            "SELECT i.`name`, i.`location`,
                    i.`points_allocated`, i.`money_value_per_point`, i.`currency`,
                    i.`max_redemptions_per_person`,
                    i.`theme_primary_hex`, i.`logo_url`,
                    i.`is_active`
               FROM `rewards_item` i
              WHERE i.`qr_token` = ? LIMIT 1"
        );
        $st->execute([$token]);
        $item = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        rewards_safe_error_response($e, 'item lookup failed');
    }
    if (!$item) rewards_json_err('reward not found', 404);
    if ((int) $item['is_active'] !== 1) rewards_json_err('reward no longer active', 410);
    /* client = denormalised brand surface from the item row. The
       redeem.html page's applyClientBrand() takes theme_primary +
       logo_url + name; we don't carry an org name on the item (yet)
       so client.name is left empty -- the page falls back gracefully. */
    rewards_json_ok([
        'item' => [
            'name'                       => (string) $item['name'],
            'location'                   => (string) ($item['location'] ?? ''),
            'points_allocated'           => (int)    $item['points_allocated'],
            'money_value_per_point'      => (float)  $item['money_value_per_point'],
            'currency'                   => (string) $item['currency'],
            'max_redemptions_per_person' => $item['max_redemptions_per_person'] !== null
                                              ? (int) $item['max_redemptions_per_person']
                                              : null,
        ],
        'client' => [
            'theme_primary' => (string) ($item['theme_primary_hex'] ?? ''),
            'logo_url'      => (string) ($item['logo_url']          ?? ''),
            'name'          => '',
            'theme_key'     => '',
        ],
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') rewards_json_err('GET or POST required', 405);

$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body)) rewards_json_err('JSON body required', 400);

/* 2026-06-22 — Marty: "When I go to /redeem?t=<token>, put in an
   email, I get 'valid token required'". The public redeem.html
   passes the token in the URL query string (?t=<token>) and the
   POST body carries only email/key. Server was reading token ONLY
   from $body['token'] which is empty in that flow. Accept token
   from either source (body wins if both present, for explicit
   server-to-server callers). */
$token = trim((string) ($body['token'] ?? $_GET['t'] ?? ''));
$email = strtolower(trim((string) ($body['email'] ?? '')));
$key   =            trim((string) ($body['key']   ?? ''));

if ($token === '' || !preg_match('/^[A-Za-z0-9_\-]{16,64}$/', $token)) {
    rewards_json_err('valid token required', 400);
}
if ($email === '' && $key === '') {
    rewards_json_err('email or key required', 400);
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    rewards_json_err('email invalid', 400);
}
if ($key !== '' && !preg_match('/^[A-Za-z0-9_\-]{1,32}$/', $key)) {
    rewards_json_err('key invalid (1-32 alphanumeric / dash / underscore)', 400);
}

/* ── Rate limit (per ip_hash + day-bucket) ─────────────────────── */
$ipHash = rewards_anonymise_ip();   /* sha256(ip + REWARDS_SESSION_SECRET) */
$today  = gmdate('Y-m-d');
$rateMax = (int) (getenv('REWARDS_REDEEM_RATE_PER_DAY') ?: 20);

try {
    $pdo = rewards_db();

    if ($ipHash !== '') {
        $pdo->prepare(
            "INSERT INTO `rewards_rate_limit` (`ip_hash`, `day_bucket`, `count`, `last_at`)
             VALUES (?, ?, 1, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE `count` = `count` + 1, `last_at` = UTC_TIMESTAMP()"
        )->execute([$ipHash, $today]);

        $r = $pdo->prepare("SELECT `count` FROM `rewards_rate_limit` WHERE `ip_hash` = ? AND `day_bucket` = ?");
        $r->execute([$ipHash, $today]);
        $count = (int) $r->fetchColumn();
        if ($count > $rateMax) {
            rewards_json_err('rate limit exceeded — try again tomorrow', 429,
                ['rate' => ['limit' => $rateMax, 'count' => $count]]);
        }
    }

    /* ── Item lookup ───────────────────────────────────────────── */
    $st = $pdo->prepare(
        "SELECT i.`id`, i.`consumer_id`, i.`sub_id`, i.`name`,
                i.`points_allocated`, i.`money_value_per_point`, i.`currency`,
                i.`max_redemptions_per_person`, i.`is_active`
           FROM `rewards_item` i
          WHERE i.`qr_token` = ? LIMIT 1"
    );
    $st->execute([$token]);
    $item = $st->fetch(PDO::FETCH_ASSOC);
    if (!$item) rewards_json_err('reward not found', 404);
    if ((int) $item['is_active'] !== 1) rewards_json_err('reward no longer active', 410);

    /* ── Per-person cap (when set) ─────────────────────────────── */
    $cap = $item['max_redemptions_per_person'];
    if ($cap !== null && (int) $cap > 0) {
        $sqlCap = "SELECT COUNT(*) FROM `rewards_redemption`
                    WHERE `rewards_item_id` = ?";
        $params = [(int) $item['id']];
        if ($email !== '') { $sqlCap .= ' AND `redeemer_email` = ?'; $params[] = $email; }
        else               { $sqlCap .= ' AND `redeemer_key`   = ?'; $params[] = $key;   }
        $cs = $pdo->prepare($sqlCap);
        $cs->execute($params);
        $already = (int) $cs->fetchColumn();
        if ($already >= (int) $cap) {
            rewards_json_err(
                "redemption limit reached for this reward ($cap per person)",
                409,
                ['cap' => (int) $cap, 'already_redeemed' => $already]
            );
        }
    }

    /* ── Compute awarded points + money (snapshot of current item values) ── */
    $pointsAwarded = (int) $item['points_allocated'];
    $valuePer      = (float) $item['money_value_per_point'];
    $moneyValue    = $pointsAwarded * $valuePer;
    $currency      = (string) $item['currency'];

    /* ── Insert audit row ──────────────────────────────────────── */
    $userAgent = isset($_SERVER['HTTP_USER_AGENT'])
        ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 512)
        : null;
    $ins = $pdo->prepare(
        "INSERT INTO `rewards_redemption`
           (`consumer_id`, `rewards_item_id`, `sub_id`,
            `redeemer_email`, `redeemer_key`,
            `points_awarded`, `money_value`, `currency`,
            `ip_hash`, `user_agent`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $ins->execute([
        (int) $item['consumer_id'],
        (int) $item['id'],
        (string) $item['sub_id'],
        $email !== '' ? $email : null,
        $key   !== '' ? $key   : null,
        $pointsAwarded,
        $moneyValue,
        $currency,
        $ipHash !== '' ? $ipHash : null,
        $userAgent,
    ]);
    $redemptionId = (int) $pdo->lastInsertId();

    /* ── Notification stub ─────────────────────────────────────── */
    /* Decision 8 deferred -- record a pending notification so a
       future cron can pick it up. Recipient is the redeemer's email
       if they gave one, else a placeholder. No send attempt fires
       from this endpoint. */
    if ($email !== '') {
        try {
            $pdo->prepare(
                "INSERT INTO `rewards_redemption_notification`
                   (`redemption_id`, `recipient_email`, `status`)
                 VALUES (?, ?, 'pending')"
            )->execute([$redemptionId, $email]);
        } catch (Throwable $_eNotif) { /* non-fatal */ }
    }
} catch (Throwable $e) {
    rewards_safe_error_response($e, 'redemption failed');
}

rewards_json_ok([
    'redemption_id'  => $redemptionId,
    'item_name'      => (string) $item['name'],
    'points_awarded' => $pointsAwarded,
    'money_value'    => round($moneyValue, 4),
    'currency'       => $currency,
    'message'        => 'Thanks — your redemption is recorded.',
]);
