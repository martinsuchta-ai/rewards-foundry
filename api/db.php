<?php
/**
 * db.php — PDO connection helper for rewards-foundry.
 *
 * Mirrors WBM api/db.php + affiliates api/db.php conventions:
 *   - Caches one PDO per request
 *   - EMULATE_PREPARES = false (real prepared statements)
 *   - utf8mb4 charset
 *   - Time-zone forced to '+00:00' on the session so MySQL stores UTC
 *
 * Reads credentials from REWARDS_DB_* env vars (set by rewards_secrets.php
 * which rewards_bootstrap.php loads from above the webroot).
 *
 * Public surface:
 *   $pdo = rewards_db();   // throws RuntimeException on connect failure
 */

declare(strict_types=1);

require_once __DIR__ . '/rewards_bootstrap.php';

if (!function_exists('rewards_db')) {
    function rewards_db(): PDO {
        static $pdo = null;
        if ($pdo instanceof PDO) return $pdo;

        $host = (string) getenv('REWARDS_DB_HOST');
        $name = (string) getenv('REWARDS_DB_NAME');
        $user = (string) getenv('REWARDS_DB_USER');
        $pass = (string) getenv('REWARDS_DB_PASS');

        if ($host === '' || $name === '' || $user === '') {
            throw new RuntimeException('rewards_db: REWARDS_DB_* env vars missing — is rewards_secrets.php loaded?');
        }

        $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";
        try {
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                /* Force UTC on the session — every timestamp written by
                   this codebase is UTC; relying on the server default
                   timezone is asking for "logged at 09:32" / "stored as
                   23:32" off-by-N-hours bugs after a SG infra migration. */
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4, time_zone = '+00:00'",
            ]);
        } catch (Throwable $e) {
            /* Log the DSN host/db (NOT the password) for diagnosis. */
            error_log('[rewards_db] connect failed for ' . $user . '@' . $host . '/' . $name . ': ' . $e->getMessage());
            throw new RuntimeException('rewards_db: connect failed');
        }
        return $pdo;
    }
}
