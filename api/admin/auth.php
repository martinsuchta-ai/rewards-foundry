<?php
/**
 * api/admin/auth.php — admin login / logout / session probe.
 *
 *   POST ?action=login
 *     body: { email, password }
 *     → 200 { ok, admin_user_id, email, name, session_token, expires_at }
 *        + sets HttpOnly + Secure + SameSite=Strict cookie
 *     → 401 on bad creds / inactive user
 *
 *   POST ?action=logout
 *     (X-Admin-Session header or cookie picks the session to revoke)
 *     → 200 { ok }
 *
 *   GET ?action=probe
 *     → 200 { ok, admin_user_id, email, name } if session valid
 *     → 401 { ok:false, ... } otherwise
 *
 * Login throttle: 10 attempts per IP per minute (rewards_rate_limit
 * with a synthetic 'admin_login:' prefix on the ip_hash so it doesn't
 * collide with the /v1/redeem rate-limit bucket).
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

$action = (string) ($_GET['action'] ?? '');

if ($action === 'probe' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $row = rewards_admin_session_resolve();
    if ($row === null) rewards_json_err('no valid session', 401);
    rewards_json_ok([
        'admin_user_id' => (int)    $row['id'],
        'email'         => (string) $row['email'],
        'name'          => (string) ($row['name'] ?? ''),
        'expires_at'    => (string) $row['expires_at'],
    ]);
}

if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($body)) rewards_json_err('JSON body required', 400);

    $email = strtolower(trim((string) ($body['email'] ?? '')));
    $pw    =            (string) ($body['password'] ?? '');
    if ($email === '' || $pw === '') rewards_json_err('email + password required', 400);

    /* Per-IP throttle on login attempts (10/min). Uses the
       rewards_rate_limit table with a synthetic 'admin_login:'
       prefix on the ip_hash so it shares the table but not the
       bucket. */
    $bucketKey = 'admin_login:' . rewards_anonymise_ip();
    $today     = gmdate('Y-m-d H:i');   /* per-minute bucket */
    try {
        $pdo = rewards_db();
        $pdo->prepare(
            "INSERT INTO `rewards_rate_limit` (`ip_hash`, `day_bucket`, `count`)
             VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE `count` = `count` + 1, `last_at` = UTC_TIMESTAMP()"
        )->execute([$bucketKey, $today]);
        $r = $pdo->prepare(
            "SELECT `count` FROM `rewards_rate_limit`
              WHERE `ip_hash` = ? AND `day_bucket` = ?"
        );
        $r->execute([$bucketKey, $today]);
        $c = (int) $r->fetchColumn();
        if ($c > 10) rewards_json_err('too many login attempts — try again in a minute', 429);

        /* Pull the admin row. Use bcrypt password_verify. */
        $u = $pdo->prepare(
            "SELECT `id`, `email`, `name`, `password_hash`, `active`
               FROM `rewards_admin_user`
              WHERE LOWER(`email`) = ? LIMIT 1"
        );
        $u->execute([$email]);
        $row = $u->fetch(PDO::FETCH_ASSOC);

        if (!$row || (int) $row['active'] !== 1) {
            /* Same error message whether the email exists or not so
               an attacker can't enumerate valid emails by response
               difference. */
            rewards_json_err('invalid credentials', 401);
        }
        if (!password_verify($pw, (string) $row['password_hash'])) {
            rewards_json_err('invalid credentials', 401);
        }

        /* Issue session + set cookie. */
        $sess = rewards_admin_session_issue((int) $row['id']);
        $maxAge = strtotime($sess['expires_at']) - time();
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie('rewards_admin_session', $sess['token'], [
            'expires'  => time() + $maxAge,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        rewards_json_ok([
            'admin_user_id' => (int) $row['id'],
            'email'         => (string) $row['email'],
            'name'          => (string) ($row['name'] ?? ''),
            'session_token' => $sess['token'],
            'expires_at'    => $sess['expires_at'],
        ]);
    } catch (Throwable $e) {
        rewards_safe_error_response($e, 'login failed');
    }
}

if ($action === 'logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $row = rewards_admin_session_resolve();
    if ($row !== null) rewards_admin_session_revoke((string) $row['token']);
    /* Clear the cookie either way so the browser drops a stale value. */
    setcookie('rewards_admin_session', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    rewards_json_ok();
}

rewards_json_err('unknown action: ' . $action, 400);
