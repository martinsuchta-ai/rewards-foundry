<?php
/**
 * rewards_consumer_auth.php — consumer API-key resolution.
 *
 * Picks the API key from (in order):
 *   1. X-Consumer-Key header (preferred — doesn't appear in access
 *      logs / browser referrers)
 *   2. ?consumer_key=… query parameter (fallback for static-HTML
 *      callers that can't set headers)
 *
 * Validates against rewards_consumer.api_key_hash (sha256 of plaintext).
 * Returns the consumer row on success, or 401-JSON-and-exits otherwise.
 *
 * Constant-time hash compare via hash_equals.
 *
 * Public surface:
 *   $consumer = rewards_require_consumer();
 *     // returns assoc-array row from rewards_consumer
 *     // OR exits 401 with JSON {ok:false,error:...}
 */

declare(strict_types=1);

require_once __DIR__ . '/rewards_bootstrap.php';
require_once __DIR__ . '/db.php';

if (!function_exists('rewards_require_consumer')) {
    function rewards_require_consumer(): array {
        /* Header preferred. Some SAPIs uppercase + dash→underscore the
           name in $_SERVER (HTTP_X_CONSUMER_KEY) — check both shapes. */
        $key = '';
        if (isset($_SERVER['HTTP_X_CONSUMER_KEY'])) {
            $key = trim((string) $_SERVER['HTTP_X_CONSUMER_KEY']);
        }
        if ($key === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $h => $v) {
                if (strcasecmp($h, 'X-Consumer-Key') === 0) {
                    $key = trim((string) $v);
                    break;
                }
            }
        }
        /* Query-string fallback. */
        if ($key === '' && isset($_GET['consumer_key'])) {
            $key = trim((string) $_GET['consumer_key']);
        }

        if ($key === '') {
            rewards_json_err('consumer key required (X-Consumer-Key header or ?consumer_key=)', 401);
        }

        $hash = hash('sha256', $key);

        try {
            $pdo = rewards_db();
            $stmt = $pdo->prepare(
                "SELECT `id`, `name`, `api_key_hash`, `cors_origin`, `active`, `created_at`
                   FROM `rewards_consumer`
                  WHERE `active` = 1
                  LIMIT 100"
            );
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            rewards_safe_error_response($e, 'consumer lookup failed');
        }

        /* Constant-time compare against every active consumer's stored
           hash so a malicious caller can't time-attack a partial match.
           Hash itself is sha256 of plaintext — both sides are 64-hex.
           At our scale (single-digit active consumers) the linear scan
           is fine; if the consumer count grows we add an indexed
           api_key_hash lookup. */
        foreach ($rows as $row) {
            if (hash_equals((string) $row['api_key_hash'], $hash)) {
                /* Strip the stored hash from the returned row so caller
                   never accidentally surfaces it. */
                unset($row['api_key_hash']);
                return $row;
            }
        }

        rewards_json_err('consumer key invalid or consumer inactive', 401);
    }
}
