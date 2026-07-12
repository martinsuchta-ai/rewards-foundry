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
   no redemption history.

   2026-06-26 — Schools Pilot Phase C. The token now resolves to
   EITHER a rewards_item OR a rewards_behaviour_activity. The
   response carries a `kind` discriminator so the redeem.html page
   knows which UI to render:
     - kind="item"      → existing reward flow (POST to /v1/redeem.php)
     - kind="behaviour" → schools behaviour flow (POST to /v1/behaviour_award.php)
   We try items FIRST (more common path); falls through to
   behaviour_activity if no item matches. */
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
                    i.`theme_primary_hex`, i.`logo_url`, i.`redeem_image_url`,
                    i.`is_active`
               FROM `rewards_item` i
              WHERE i.`qr_token` = ? LIMIT 1"
        );
        $st->execute([$token]);
        $item = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        rewards_safe_error_response($e, 'item lookup failed');
    }

    if ($item) {
        if ((int) $item['is_active'] !== 1) rewards_json_err('reward no longer active', 410);
        rewards_json_ok([
            'kind' => 'item',
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
                'theme_primary'    => (string) ($item['theme_primary_hex'] ?? ''),
                'logo_url'         => (string) ($item['logo_url']          ?? ''),
                /* Dedicated redemption-page image (migration 006). The
                   page prefers this; when empty it falls back to logo_url
                   so existing items keep their current look. */
                'redeem_image_url' => (string) ($item['redeem_image_url']  ?? ''),
                'name'             => '',
                'theme_key'        => '',
            ],
        ]);
    }

    /* ── Token didn't match an item. Try behaviour activity. ────── */
    try {
        $st2 = $pdo->prepare(
            "SELECT a.`id`, a.`sub_id`, a.`catalogue_id`,
                    a.`scope`, a.`direction`, a.`dimension_key`,
                    a.`title`, a.`points`, a.`self_scan_enabled`,
                    a.`theme_primary_hex`, a.`active`,
                    c.`edition_key`, c.`scope_labels`, c.`dimension_labels`,
                    c.`trusted_role_label`, c.`terminology`,
                    c.`self_scan_policy`
               FROM `rewards_behaviour_activity` a
               JOIN `rewards_behaviour_catalogue` c ON c.`id` = a.`catalogue_id`
              WHERE a.`qr_token` = ? LIMIT 1"
        );
        $st2->execute([$token]);
        $bx = $st2->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        rewards_safe_error_response($e, 'behaviour lookup failed');
    }
    if (!$bx) rewards_json_err('reward not found', 404);
    if ((int) $bx['active'] !== 1) rewards_json_err('behaviour no longer active', 410);

    $scopeLabels = json_decode((string) $bx['scope_labels'],     true) ?: [];
    $dimLabels   = json_decode((string) $bx['dimension_labels'], true) ?: [];
    $terms       = json_decode((string) $bx['terminology'],      true) ?: [];

    $dim = (string) ($bx['dimension_key'] ?? '');
    $scope = (string) $bx['scope'];
    $direction = (string) $bx['direction'];

    /* Resolve whether self-scan is ACTUALLY allowed for this row,
       folding in the catalogue policy. Logic:
         policy=never        → false
         policy=spin_up_only → row's self_scan_enabled AND direction=UP
         policy=allow        → row's self_scan_enabled
       The redeem.html UI uses this to decide which mode picker to render. */
    $policy = (string) $bx['self_scan_policy'];
    $rowAllows = ((int) $bx['self_scan_enabled']) === 1;
    if ($policy === 'never') {
        $selfScanAllowed = false;
    } elseif ($policy === 'spin_up_only') {
        $selfScanAllowed = $rowAllows && ($direction === 'UP');
    } else {
        $selfScanAllowed = $rowAllows;
    }

    rewards_json_ok([
        'kind' => 'behaviour',
        'behaviour' => [
            'scope'              => $scope,
            'scope_label'        => (string) ($scopeLabels[$scope] ?? $scope),
            'direction'          => $direction,
            'dimension_key'      => $dim !== '' ? $dim : null,
            'dimension_label'    => $dim !== '' ? (string) ($dimLabels[$dim] ?? $dim) : null,
            'title'              => (string) $bx['title'],
            'points'             => (int) $bx['points'],     /* MAGNITUDE — sign comes from direction */
            'self_scan_allowed'  => $selfScanAllowed,
            'trusted_role_label' => (string) $bx['trusted_role_label'],
            'terminology'        => [
                'spin_up'   => (string) ($terms['spin_up']   ?? 'Spin Up'),
                'spin_down' => (string) ($terms['spin_down'] ?? 'Spin Down'),
            ],
        ],
        'catalogue' => [
            'sub_id'           => (string) $bx['sub_id'],
            'edition_key'      => (string) $bx['edition_key'],
            'self_scan_policy' => $policy,
        ],
        'client' => [
            'theme_primary' => (string) ($bx['theme_primary_hex'] ?? ''),
            'logo_url'      => '',
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

    /* ── WBM membership HARD GATE (2026-06-25 — Marty inverted) ────
       When the reward is linked to a WBM subscription (sub_id starts
       SUB-), the entered email or key MUST match a respondent on
       that sub's evaluation. WBM-linked rewards are member benefits,
       not public rewards — only people inside the linked community
       can redeem.

       Original 2026-06-23 logic was the opposite: in_wbm=true was a
       SOFT BLOCK ("you're already a member, benefit included") and
       non-members could still redeem. Marty 2026-06-25: "It should
       not allow not linked emails or keys to redeem. Previously
       asked to implement guard validation."

       Behaviour matrix now:
         in_wbm = true    → PROCEED to redemption insert (member)
         in_wbm = false   → REJECT with friendly "we couldn't find
                             you on this membership" message
         check unreachable → REJECT (fail-CLOSED — can't validate
                             so don't honour. Was fail-open under
                             the soft-block spec; now we'd rather
                             show a temporary error than let a
                             stranger redeem during a network blip)
         secret not set   → REJECT (config error; fail-closed)

       The WBM endpoint contract is unchanged:
         GET ?secret=...&sub_id=SUB-XXX&email=foo  (or &key=YZQ...)
         → { ok:true, in_wbm:bool, respondent?, org? } */
    $itemSubId = trim((string) ($item['sub_id'] ?? ''));
    if (preg_match('/^SUB-[A-Za-z0-9]{1,32}$/', $itemSubId)) {
        $wbmSecret = (string) getenv('WBM_REWARDS_SHARED_SECRET');
        if ($wbmSecret === '') {
            /* Fail-closed: a WBM-linked reward can't be validated
               without the shared secret, so reject rather than
               silently allow strangers in. Surface a clear error
               so an admin can fix the rewards_secrets.php config. */
            rewards_json_err(
                'membership_check_misconfigured',
                500,
                ['message' => 'This reward is linked to a Wellbeing Matters subscription, but the membership check is not configured on this server. Please contact the admin (membership_check secret missing).']
            );
        }
        $checkBase = (string) (getenv('WBM_MEMBERSHIP_CHECK_URL') ?: 'https://smart-tools-foundry.com/WBM/api/wbm_membership_check.php');
        $checkQs   = 'secret=' . urlencode($wbmSecret)
                   . '&sub_id=' . urlencode($itemSubId)
                   . ($email !== '' ? ('&email=' . urlencode($email)) : '')
                   . ($key   !== '' ? ('&key='   . urlencode($key))   : '');
        $checkRes  = null;
        $checkErr  = '';
        try {
            $ch = curl_init($checkBase . '?' . $checkQs);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT        => 6,
                CURLOPT_USERAGENT      => 'rewards-foundry/redeem',
            ]);
            $body = curl_exec($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($http === 200 && $body !== false) {
                $j = json_decode((string) $body, true);
                if (is_array($j) && !empty($j['ok'])) {
                    $checkRes = $j;
                } else {
                    $checkErr = 'invalid response shape';
                }
            } else {
                $checkErr = 'HTTP ' . $http;
            }
        } catch (Throwable $_eCheck) {
            $checkErr = $_eCheck->getMessage();
            error_log('[redeem] WBM membership check threw: ' . $checkErr);
        }
        if ($checkRes === null) {
            /* Check infrastructure failed (network, JSON parse,
               unexpected status). Fail-closed per the new spec
               so a network blip can't let a stranger redeem. */
            error_log('[redeem] WBM membership check failed for sub ' . $itemSubId . ': ' . $checkErr);
            rewards_json_err(
                'membership_check_failed',
                503,
                ['message' => 'We couldn\'t verify your Wellbeing Matters membership right now — please try again in a moment. (If this keeps happening, contact your account holder.)']
            );
        }
        if (empty($checkRes['in_wbm'])) {
            /* HARD GATE: not a member of the linked sub → reject. */
            $orgName  = trim((string) (($checkRes['org']['name'] ?? '')));
            $identHint = $email !== ''
                ? ('the email you entered (' . $email . ')')
                : ('the access code you entered');
            $msg = 'We couldn\'t find ' . $identHint . ' on the '
                 . ($orgName !== '' ? ($orgName . ' ') : '')
                 . 'Wellbeing Matters membership linked to this reward. '
                 . 'This benefit is only available to people enrolled on that membership. '
                 . 'Please use the same email address or access code you received when you took the wellbeing evaluation.';
            rewards_json_err('not_a_member', 403, ['message' => $msg]);
            /* rewards_json_err exits — control doesn't return. */
        }
        /* in_wbm = true → fall through to normal redemption insert.
           Stash respondent + org names on the response so the
           success screen can read "Thanks, Audrey!" instead of
           a generic "redeemed". */
        $wbmRespondent = is_array($checkRes['respondent'] ?? null) ? $checkRes['respondent'] : [];
        $wbmOrgName    = trim((string) (($checkRes['org']['name'] ?? '')));
    }

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
