<?php
/**
 * api/migrate/run.php — apply pending schema migrations.
 *
 * GET ?token=<REWARDS_MIGRATE_TOKEN>
 *   Runs every NNN_*.sql file in this directory that hasn't already
 *   been applied (per the rewards_schema_migrations table). Returns
 *   JSON with applied + skipped counts + per-file status.
 *
 * Mirrors WBM api/migrate/run.php conventions:
 *   - Lexical-order application (file names start with a 3-digit prefix)
 *   - Tracked in rewards_schema_migrations (filename + applied_at)
 *   - NO transactions around DDL (MySQL implicitly commits CREATE/
 *     ALTER, wrapping in BEGIN/COMMIT throws "no active transaction")
 *   - Statements split on semicolons at top level (no nested transactions
 *     to worry about for our migration shape)
 *   - Idempotent: re-running on a clean state returns 0 applied.
 */

declare(strict_types=1);

require_once __DIR__ . '/../rewards_bootstrap.php';
require_once __DIR__ . '/../rewards_cron_auth.php';
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');

/* 2026-07-17 — this response MUST NOT be cached. SiteGround's nginx proxy
   (visible as `X-Proxy-Cache-Info: DT:1` on the response) was caching this GET
   and replaying a stale result: three consecutive runs returned a
   byte-identical {"applied":["010","011"]} while migrations 012 and 013 never
   executed at all. Dangerous for a migration runner — it reports success for
   work it did not do. The only tell was that a genuinely re-applied 010 would
   have failed on a duplicate column, and nothing failed. */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

rewards_cron_auth_check();   /* 403 unless ?token=REWARDS_MIGRATE_TOKEN */

try {
    $pdo = rewards_db();
} catch (Throwable $e) {
    rewards_safe_error_response($e, 'db connect failed');
}

/* Ensure tracker table exists. NOT IN a migration file — this table
   IS the migration tracker, can't bootstrap itself from a migration. */
try {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `rewards_schema_migrations` (
            `filename`   VARCHAR(255) NOT NULL,
            `applied_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`filename`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
} catch (Throwable $e) {
    rewards_safe_error_response($e, 'tracker table create failed');
}

/* Pull already-applied filenames. */
$applied = [];
try {
    $r = $pdo->query("SELECT `filename` FROM `rewards_schema_migrations`");
    while ($row = $r->fetch(PDO::FETCH_NUM)) $applied[(string) $row[0]] = true;
} catch (Throwable $e) {
    rewards_safe_error_response($e, 'tracker read failed');
}

/* Lexical-order list every NNN_*.sql in this directory. */
$dir   = __DIR__;
$files = [];
foreach (scandir($dir) as $fn) {
    if (!preg_match('/^\d{3}_.+\.sql$/', $fn)) continue;
    $files[] = $fn;
}
sort($files);   /* lexical = numeric for 3-digit prefix */

$report = ['applied' => [], 'skipped' => [], 'failed' => null];

foreach ($files as $fn) {
    if (isset($applied[$fn])) {
        $report['skipped'][] = $fn;
        continue;
    }
    $sql = @file_get_contents($dir . '/' . $fn);
    if ($sql === false) {
        $report['failed'] = ['filename' => $fn, 'reason' => 'read failed'];
        break;
    }
    /* Strip /* ... block comments and -- line comments BEFORE the split
       so semicolons in comments don't fool the splitter. */
    $sql = preg_replace('!/\*.*?\*/!s',   '', $sql);
    $sql = preg_replace('/^\s*--.*$/m',   '', $sql);

    /* Split into statements on top-level semicolons. Our migrations
       don't use procedures / triggers (which would need DELIMITER
       handling), so a naive split is safe. */
    $stmts = array_filter(array_map('trim', explode(';', $sql)));

    try {
        foreach ($stmts as $stmt) {
            if ($stmt === '') continue;
            $pdo->exec($stmt);
        }
        $ins = $pdo->prepare("INSERT INTO `rewards_schema_migrations` (`filename`) VALUES (?)");
        $ins->execute([$fn]);
        $report['applied'][] = $fn;
    } catch (Throwable $e) {
        error_log('[rewards.migrate] ' . $fn . ' failed: ' . $e->getMessage());
        $report['failed'] = ['filename' => $fn, 'reason' => $e->getMessage()];
        break;   /* stop at first failure — fix + re-run */
    }
}

rewards_json_ok($report);
