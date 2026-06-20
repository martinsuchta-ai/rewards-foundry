<?php
/**
 * rewards_cron_auth.php — auth helper for cron + admin-only endpoints
 * that aren't gated by an admin session.
 *
 * Mirrors WBM api/wm_cron_auth.php + affiliates api/aff_cron_auth.php.
 *
 * Behaviour:
 *   - CLI invocation (php api/cron_X.php) → returns immediately,
 *     trusted by filesystem perms (SG's cron runs as the SG user).
 *   - HTTP invocation → accepts ?token=<REWARDS_CRON_SECRET> first,
 *     falls back to ?token=<REWARDS_MIGRATE_TOKEN>. Otherwise 403.
 *
 * Two secrets, two purposes:
 *   - REWARDS_MIGRATE_TOKEN — admin-paste secret. Used interactively
 *     for the migration runner, probes, admin URLs. Rotate
 *     independently.
 *   - REWARDS_CRON_SECRET — dedicated cron auth. Rotates rarely.
 */

declare(strict_types=1);

require_once __DIR__ . '/rewards_bootstrap.php';

if (!function_exists('rewards_cron_auth_check')) {
    function rewards_cron_auth_check(): void {
        /* CLI bypass — SG's cron runs PHP CLI as the account user, no
           HTTP layer, no token needed. */
        if (PHP_SAPI === 'cli') return;

        $presented = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
        $cronTok   = (string) getenv('REWARDS_CRON_SECRET');
        $migTok    = (string) getenv('REWARDS_MIGRATE_TOKEN');

        $ok = false;
        if ($presented !== '') {
            if ($cronTok !== '' && hash_equals($cronTok, $presented)) $ok = true;
            elseif ($migTok !== '' && hash_equals($migTok, $presented)) $ok = true;
        }

        if (!$ok) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'forbidden';
            exit;
        }
    }
}
