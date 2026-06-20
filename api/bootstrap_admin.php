<?php
/**
 * api/bootstrap_admin.php — one-off: insert / replace the first
 * admin user. Used at Phase 0 wrap-up so the real bcrypt hash never
 * sits in the migration file (which is in git).
 *
 * POST ?token=<REWARDS_MIGRATE_TOKEN>
 *   body JSON: { "email": "marty@the-good-foundry.com",
 *                "name":  "Marty Suchta",
 *                "password": "<chosen strong password>" }
 *
 *   - Computes password_hash($pw, PASSWORD_DEFAULT) server-side
 *   - INSERTs the admin (or UPDATEs if email already exists)
 *   - Sets active=1
 *   - Returns the admin id + a confirmation message
 *   - NEVER logs the plaintext password
 *
 * Idempotent: re-running with the same email replaces the hash.
 * Delete this file or comment-out the action after Marty's first
 * login if you want to be paranoid (the token gate is the actual
 * security boundary; the file just shouldn't loiter).
 */

declare(strict_types=1);

require_once __DIR__ . '/rewards_bootstrap.php';
require_once __DIR__ . '/rewards_cron_auth.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

rewards_cron_auth_check();   /* gated on REWARDS_MIGRATE_TOKEN */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rewards_json_err('POST required', 405);
}

$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body)) {
    rewards_json_err('JSON body required', 400);
}

$email = strtolower(trim((string) ($body['email']    ?? '')));
$name  =            trim((string) ($body['name']     ?? ''));
$pw    =                  (string) ($body['password'] ?? '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) rewards_json_err('email invalid', 400);
if (strlen($pw) < 12)                            rewards_json_err('password must be >= 12 chars', 400);

$hash = password_hash($pw, PASSWORD_DEFAULT);
if (!$hash) rewards_json_err('password_hash failed', 500);

try {
    $pdo = rewards_db();
    $stmt = $pdo->prepare(
        "INSERT INTO `rewards_admin_user` (`email`, `password_hash`, `name`, `active`)
         VALUES (?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE
             `password_hash` = VALUES(`password_hash`),
             `name`          = VALUES(`name`),
             `active`        = 1"
    );
    $stmt->execute([$email, $hash, $name !== '' ? $name : null]);
    $id = (int) $pdo->lastInsertId();
    /* lastInsertId returns 0 on ON DUPLICATE KEY UPDATE — re-query. */
    if ($id === 0) {
        $q = $pdo->prepare("SELECT `id` FROM `rewards_admin_user` WHERE `email` = ? LIMIT 1");
        $q->execute([$email]);
        $id = (int) $q->fetchColumn();
    }
} catch (Throwable $e) {
    rewards_safe_error_response($e, 'admin upsert failed');
}

rewards_json_ok([
    'admin_user_id' => $id,
    'email'         => $email,
    'message'       => 'admin upserted; active=1; can log in now',
]);
