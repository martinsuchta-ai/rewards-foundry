<?php
/**
 * api/bootstrap_consumer_key.php — one-off: mint a fresh API key for
 * a given consumer name, store its sha256, return the PLAINTEXT
 * exactly once. Pair it with WBM's wm_secrets.php → WM_REWARDS_FOUNDRY_KEY.
 *
 * POST ?token=<REWARDS_MIGRATE_TOKEN>
 *   body JSON: { "consumer_name": "WBM-prod" }
 *
 * Returns:
 *   { ok: true, consumer_name: "...", api_key: "<plaintext>",
 *     hash_stored: "<sha256>" }
 *
 * The plaintext is in the response body ONCE — paste it into
 * wm_secrets.php immediately. Never logged server-side. Re-running
 * this endpoint MINTS A NEW KEY and overwrites the prior hash; any
 * caller still using the old key is locked out.
 *
 * Also sets the consumer active=1 once the key is paired (paranoid
 * default at scaffold time: consumer rows start active=0).
 */

declare(strict_types=1);

require_once __DIR__ . '/rewards_bootstrap.php';
require_once __DIR__ . '/rewards_cron_auth.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

rewards_cron_auth_check();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') rewards_json_err('POST required', 405);

$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body)) rewards_json_err('JSON body required', 400);

$name = trim((string) ($body['consumer_name'] ?? ''));
if ($name === '') rewards_json_err('consumer_name required', 400);

/* Generate 32 bytes → 64 hex chars. Same shape as every other
   token-style secret on the platform. */
$plain = bin2hex(random_bytes(32));
$hash  = hash('sha256', $plain);

try {
    $pdo = rewards_db();
    $stmt = $pdo->prepare(
        "UPDATE `rewards_consumer`
            SET `api_key_hash` = ?, `active` = 1
          WHERE `name` = ?"
    );
    $stmt->execute([$hash, $name]);
    if ($stmt->rowCount() === 0) {
        rewards_json_err('consumer not found: ' . $name . '. Run the migration first to seed the row.', 404);
    }
} catch (Throwable $e) {
    rewards_safe_error_response($e, 'consumer key update failed');
}

rewards_json_ok([
    'consumer_name' => $name,
    'api_key'       => $plain,
    'hash_stored'   => $hash,
    'message'       => 'Paste api_key into the consumer'."'".'s secrets file immediately. It is not retrievable again — the server only kept the hash.',
]);
