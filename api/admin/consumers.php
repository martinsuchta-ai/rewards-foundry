<?php
/**
 * api/admin/consumers.php — admin read-only list of consumers.
 *
 *   GET ?action=list
 *     Returns every rewards_consumer row (active + inactive). Used
 *     to populate the filter dropdowns in the admin UI.
 *
 * Consumer CREATE / key-mint stays on the one-shot
 * bootstrap_consumer_key.php endpoint for now -- spinning up a new
 * consumer is a rare ops event, not a routine admin task. Promote
 * it into here when there's a second consumer to manage.
 */

declare(strict_types=1);

require_once __DIR__ . '/../rewards_bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/admin_session.php';

header('Content-Type: application/json; charset=utf-8');
rewards_send_cors_origin();
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Admin-Session');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
rewards_admin_require_session();

$action = (string) ($_GET['action'] ?? 'list');

if ($action === 'list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $st = rewards_db()->query(
            "SELECT `id`, `name`, `cors_origin`, `active`, `notes`,
                    `created_at`, `updated_at`,
                    (SELECT COUNT(*) FROM `rewards_item`       i WHERE i.`consumer_id` = c.`id`) AS `item_count`,
                    (SELECT COUNT(*) FROM `rewards_redemption` r WHERE r.`consumer_id` = c.`id`) AS `redemption_count`
               FROM `rewards_consumer` c
              ORDER BY `name` ASC"
        );
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        rewards_safe_error_response($e, 'consumer list failed');
    }
    rewards_json_ok(['consumer_count' => count($rows), 'consumers' => $rows]);
}

rewards_json_err('unknown action: ' . $action, 400);
