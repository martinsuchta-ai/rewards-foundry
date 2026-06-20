<?php
/**
 * rewards_bootstrap.php — request-level setup for every endpoint.
 *
 *   - Forces UTC timezone (every stored timestamp is UTC, display
 *     conversion happens client-side only)
 *   - Loads rewards_secrets.php from above the webroot
 *   - Exposes rewards_send_cors_origin() for the strict-origin allowlist
 *   - Exposes rewards_anonymise_ip() for sha256(ip + REWARDS_SESSION_SECRET)
 *
 * Mirrors the WBM wm_bootstrap.php + the affiliates aff_bootstrap.php
 * conventions. Every endpoint MUST require_once this file before doing
 * anything else.
 */

declare(strict_types=1);

date_default_timezone_set('UTC');

/* ── Locate and load rewards_secrets.php ────────────────────────────
   Lives ABOVE the webroot at /home/customer/www/rewards-foundry.com/
   rewards_secrets.php. Try a few candidate paths so local-dev /
   different SG account layouts both work. */
$_rewards_secrets_candidates = [
    /* SG addon-domain layout (webroot = /rewards-foundry.com/public_html/) */
    __DIR__ . '/../../../rewards_secrets.php',
    /* SG primary-domain layout (webroot = /public_html/) */
    __DIR__ . '/../../rewards_secrets.php',
    /* Explicit SG path (belt-and-braces) */
    '/home/customer/www/rewards-foundry.com/rewards_secrets.php',
];
foreach ($_rewards_secrets_candidates as $_path) {
    if (is_file($_path) && is_readable($_path)) {
        require_once $_path;
        break;
    }
}
unset($_rewards_secrets_candidates, $_path);

/* ── CORS helper ────────────────────────────────────────────────────
   Strict-origin allowlist. The public consumer API at /v1/* is called
   cross-origin from WBM (smart-tools-foundry.com) + future consumers.
   Anything in REWARDS_CORS_ORIGINS (comma-separated) is allowed; the
   default also includes localhost for dev. */
if (!function_exists('rewards_send_cors_origin')) {
    function rewards_send_cors_origin(): void {
        static $sent = false;
        if ($sent || headers_sent()) return;
        $sent = true;
        $origin = isset($_SERVER['HTTP_ORIGIN']) ? trim((string) $_SERVER['HTTP_ORIGIN']) : '';
        if ($origin === '') return;

        $defaults = [
            'https://smart-tools-foundry.com',
            'https://www.smart-tools-foundry.com',
            'https://www.the-good-foundry.com',
            'https://the-good-foundry.com',
            'https://www.rewards-foundry.com',
            'https://rewards-foundry.com',
            'http://localhost',
            'http://localhost:5500',
            'http://localhost:8000',
            'http://127.0.0.1',
            'http://127.0.0.1:5500',
            'http://127.0.0.1:8000',
        ];
        $env = (string) getenv('REWARDS_CORS_ORIGINS');
        if ($env !== '') {
            foreach (explode(',', $env) as $extra) {
                $extra = trim($extra);
                if ($extra !== '' && !in_array($extra, $defaults, true)) $defaults[] = $extra;
            }
        }
        if (in_array($origin, $defaults, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Credentials: true');
            header('Vary: Origin');
        }
        /* Unknown origin → no header → browser blocks. */
    }
}

/* ── IP anonymisation ──────────────────────────────────────────────
   Never store raw IPs. sha256(ip + REWARDS_SESSION_SECRET) is the
   audit handle on every redemption / admin action / etc. Same
   privacy convention every other GoodFoundry service uses. */
if (!function_exists('rewards_anonymise_ip')) {
    function rewards_anonymise_ip(?string $ip = null): string {
        $ip   = $ip ?? (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $salt = (string) getenv('REWARDS_SESSION_SECRET');
        if ($ip === '' || $salt === '') return '';
        return hash('sha256', $ip . $salt);
    }
}

/* ── JSON response helpers ─────────────────────────────────────────
   Tight conventions so every endpoint returns the same envelope shape.
   Callers do:
     rewards_json_ok(['items' => $items]);
     rewards_json_err('Item not found', 404);
*/
if (!function_exists('rewards_json_ok')) {
    function rewards_json_ok(array $payload = [], int $status = 200): void {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['ok' => true] + $payload);
        exit;
    }
}
if (!function_exists('rewards_json_err')) {
    function rewards_json_err(string $error, int $status = 400, array $extra = []): void {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['ok' => false, 'error' => $error] + $extra);
        exit;
    }
}

/* ── Safe error helper ────────────────────────────────────────────
   Logs the full exception, returns a generic message to the client.
   SQL exception messages leak schema info — never propagate them. */
if (!function_exists('rewards_safe_error_response')) {
    function rewards_safe_error_response(Throwable $e, string $publicMessage = 'Internal error', int $status = 500): void {
        error_log('[rewards_safe_error_response] ' . $publicMessage . ': ' . $e->getMessage());
        rewards_json_err($publicMessage, $status);
    }
}
