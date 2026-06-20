<?php
/**
 * api/admin/admin_session.php — admin session issue / validate /
 * require helpers.
 *
 * Sessions live in `rewards_admin_session` (token + admin_user_id +
 * expires_at + ip_hash + user_agent). 30-day TTL by default. Token
 * = 64-char random; cookie value is plaintext, server stores it
 * verbatim (lookup is direct, no hashing — same trade-off WBM makes
 * since the cookie is HttpOnly + Secure + SameSite=Strict).
 *
 * Three public functions:
 *   rewards_admin_session_issue(int $userId): array  // returns [token, expires_iso]
 *   rewards_admin_session_resolve(): ?array          // returns admin_user row or null
 *   rewards_admin_require_session(): array           // returns row or 401-JSON-and-exit
 *
 * Token transport: X-Admin-Session header preferred, cookie
 * (rewards_admin_session) as fallback so the same session works
 * across static-HTML pages + fetch calls.
 */

declare(strict_types=1);

require_once __DIR__ . '/../rewards_bootstrap.php';
require_once __DIR__ . '/../db.php';

if (!function_exists('rewards_admin_session_issue')) {
    function rewards_admin_session_issue(int $userId, int $ttlSeconds = 2592000): array {
        $pdo = rewards_db();
        $token = bin2hex(random_bytes(32));   /* 64 hex chars */
        $expIso = gmdate('c', time() + $ttlSeconds);
        $expSql = gmdate('Y-m-d H:i:s', time() + $ttlSeconds);
        $ipHash = rewards_anonymise_ip();
        $ua     = isset($_SERVER['HTTP_USER_AGENT'])
                    ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 512)
                    : null;

        $pdo->prepare(
            "INSERT INTO `rewards_admin_session`
               (`token`, `admin_user_id`, `expires_at`, `ip_hash`, `user_agent`)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([$token, $userId, $expSql, $ipHash !== '' ? $ipHash : null, $ua]);

        /* Opportunistic cleanup of expired rows — cheap with the
           idx_rewards_session_expires index. */
        $pdo->exec(
            "DELETE FROM `rewards_admin_session`
              WHERE `expires_at` < (UTC_TIMESTAMP() - INTERVAL 1 DAY)"
        );

        /* Stamp last_login on the admin row so the dashboard can show
           "you last signed in 2 days ago" without an extra column lookup. */
        $pdo->prepare(
            "UPDATE `rewards_admin_user` SET `last_login_at` = UTC_TIMESTAMP() WHERE `id` = ?"
        )->execute([$userId]);

        return ['token' => $token, 'expires_at' => $expIso];
    }
}

if (!function_exists('rewards_admin_session_resolve')) {
    function rewards_admin_session_resolve(): ?array {
        $token = '';
        if (isset($_SERVER['HTTP_X_ADMIN_SESSION'])) {
            $token = trim((string) $_SERVER['HTTP_X_ADMIN_SESSION']);
        }
        if ($token === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $h => $v) {
                if (strcasecmp($h, 'X-Admin-Session') === 0) {
                    $token = trim((string) $v);
                    break;
                }
            }
        }
        if ($token === '' && isset($_COOKIE['rewards_admin_session'])) {
            $token = trim((string) $_COOKIE['rewards_admin_session']);
        }
        if ($token === '') return null;

        try {
            $pdo = rewards_db();
            $st = $pdo->prepare(
                "SELECT s.`token`, s.`expires_at`,
                        u.`id`, u.`email`, u.`name`, u.`active`
                   FROM `rewards_admin_session` s
                   JOIN `rewards_admin_user`    u ON u.`id` = s.`admin_user_id`
                  WHERE s.`token`     = ?
                    AND s.`expires_at` > UTC_TIMESTAMP()
                    AND u.`active`     = 1
                  LIMIT 1"
            );
            $st->execute([$token]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $_e) {
            return null;
        }
    }
}

if (!function_exists('rewards_admin_require_session')) {
    function rewards_admin_require_session(): array {
        $row = rewards_admin_session_resolve();
        if ($row === null) {
            rewards_json_err('admin session required', 401);
        }
        return $row;
    }
}

if (!function_exists('rewards_admin_session_revoke')) {
    function rewards_admin_session_revoke(string $token): void {
        if ($token === '') return;
        try {
            rewards_db()->prepare(
                "DELETE FROM `rewards_admin_session` WHERE `token` = ?"
            )->execute([$token]);
        } catch (Throwable $_e) { /* best-effort */ }
    }
}
