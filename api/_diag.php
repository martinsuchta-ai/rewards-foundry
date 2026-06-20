<?php
/**
 * api/_diag.php — minimal health probe.
 *
 * GET ?token=<REWARDS_MIGRATE_TOKEN>
 *   Returns a tight JSON dump of:
 *     - which secrets are loaded (presence boolean only, no values)
 *     - DB connectivity
 *     - migration table presence + applied count
 *     - rewards_consumer + rewards_admin_user row counts
 *
 * Used at scaffold time + on every deploy to confirm the wire is up
 * before anything else runs. Mirrors WBM api/admin/_diag.php pattern.
 *
 * NEVER print secret values — only the YES/NO presence flag.
 */

declare(strict_types=1);

require_once __DIR__ . '/rewards_bootstrap.php';
require_once __DIR__ . '/rewards_cron_auth.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

/* 2026-06-21 -- before the cron-auth check (which 403s on token
   mismatch / missing-token), expose which secrets-file candidate
   paths exist on disk + whether any matched what rewards_bootstrap
   tried to load. Without this, a misplaced rewards_secrets.php is
   invisible: every endpoint just 403s on a "missing token" that's
   actually "secret never loaded".

   This block runs WITHOUT auth so a fresh deploy can self-diagnose
   the secrets path even before the token is valid. It only prints
   boolean presence + path strings -- no secret values. */
if (isset($_GET['secrets_probe']) && $_GET['secrets_probe'] === '1') {
    $candidates = [
        '__DIR__/../../../rewards_secrets.php'                          => __DIR__ . '/../../../rewards_secrets.php',
        '__DIR__/../../rewards_secrets.php'                             => __DIR__ . '/../../rewards_secrets.php',
        '/home/customer/www/rewards-foundry.com/rewards_secrets.php'    => '/home/customer/www/rewards-foundry.com/rewards_secrets.php',
        '/home/customer/www/smart-tools-foundry.com/rewards-foundry.com/rewards_secrets.php'
                                                                        => '/home/customer/www/smart-tools-foundry.com/rewards-foundry.com/rewards_secrets.php',
        '/home/customer/www/smart-tools-foundry.com/rewards_secrets.php'
                                                                        => '/home/customer/www/smart-tools-foundry.com/rewards_secrets.php',
    ];
    $probe = [];
    foreach ($candidates as $label => $path) {
        $real = @realpath($path);
        $probe[] = [
            'candidate'    => $label,
            'resolved_to'  => $real ?: $path,
            'exists'       => is_file($path),
            'readable'     => is_file($path) && is_readable($path),
        ];
    }
    echo json_encode([
        'ok'                  => true,
        'secrets_probe'       => true,
        'time_utc'            => gmdate('c'),
        '__DIR__'             => __DIR__,
        'candidates'          => $probe,
        'any_env_loaded'      => (getenv('REWARDS_DB_HOST') !== false && getenv('REWARDS_DB_HOST') !== ''),
        'note'                => 'Authentication bypassed for this probe-only mode. Remove ?secrets_probe=1 once you have located the file path.',
    ], JSON_PRETTY_PRINT);
    exit;
}

rewards_cron_auth_check();

$out = [
    'ok'        => true,
    'time_utc'  => gmdate('c'),
    'php'       => PHP_VERSION,
    'sapi'      => PHP_SAPI,
    'secrets'   => [
        'REWARDS_DB_HOST'         => getenv('REWARDS_DB_HOST')         !== false && getenv('REWARDS_DB_HOST')         !== '',
        'REWARDS_DB_NAME'         => getenv('REWARDS_DB_NAME')         !== false && getenv('REWARDS_DB_NAME')         !== '',
        'REWARDS_DB_USER'         => getenv('REWARDS_DB_USER')         !== false && getenv('REWARDS_DB_USER')         !== '',
        'REWARDS_DB_PASS'         => getenv('REWARDS_DB_PASS')         !== false && getenv('REWARDS_DB_PASS')         !== '',
        'REWARDS_MIGRATE_TOKEN'   => getenv('REWARDS_MIGRATE_TOKEN')   !== false && getenv('REWARDS_MIGRATE_TOKEN')   !== '',
        'REWARDS_CRON_SECRET'     => getenv('REWARDS_CRON_SECRET')     !== false && getenv('REWARDS_CRON_SECRET')     !== '',
        'REWARDS_SESSION_SECRET'  => getenv('REWARDS_SESSION_SECRET')  !== false && getenv('REWARDS_SESSION_SECRET')  !== '',
    ],
];

try {
    $pdo = rewards_db();
    $out['db'] = ['connected' => true];

    /* Migration tracker presence + applied list */
    try {
        $r = $pdo->query("SELECT `filename`, `applied_at` FROM `rewards_schema_migrations` ORDER BY `filename`");
        $rows = $r->fetchAll();
        $out['db']['migrations_applied'] = count($rows);
        $out['db']['migrations'] = $rows;
    } catch (Throwable $e) {
        $out['db']['migrations_applied'] = 0;
        $out['db']['migrations_note']    = 'tracker table missing — run api/migrate/run.php';
    }

    /* Row counts on the two seed tables (only if they exist) */
    foreach (['rewards_consumer', 'rewards_admin_user'] as $tbl) {
        try {
            $c = (int) $pdo->query("SELECT COUNT(*) FROM `$tbl`")->fetchColumn();
            $out['db'][$tbl . '_rows'] = $c;
        } catch (Throwable $_e) {
            $out['db'][$tbl . '_rows'] = 'missing';
        }
    }
} catch (Throwable $e) {
    $out['db'] = ['connected' => false, 'error' => $e->getMessage()];
    $out['ok'] = false;
    http_response_code(500);
}

echo json_encode($out, JSON_PRETTY_PRINT);
