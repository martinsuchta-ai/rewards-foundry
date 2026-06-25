<?php
/**
 * /v1/wearable_balance.php — return a participant's running
 * wearable-rewards balance + recent awards.
 *
 * Phase 2 close-out (2026-06-26): wires the WBM vault wearable
 * card to the real point-award ledger written by
 * /v1/wearable_reward_credit.php.
 *
 * Method  : GET
 * Auth    : X-Signature header = HMAC-SHA256(email, WBM_REWARDS_SHARED_SECRET)
 *           Same shared secret as wearable_reward_credit. Replay
 *           risk on a GET-balance call is low — anyone who
 *           intercepts a signed request can only read that
 *           email's balance, not write.
 * Params  : ?email=...&recent=<int, default 10>
 *
 * Returns:
 *   {
 *     ok: true,
 *     email: '...',
 *     total_points: <int>,
 *     awards_count: <int>,
 *     recent_awards: [
 *       { rule_key, points, reason, metric_date, awarded_at }, ...
 *     ]
 *   }
 *
 * 2026-06-26 — Phase 2 ship.
 */

declare(strict_types=1);
@set_time_limit(10);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

set_exception_handler(function ($e) {
    if (!headers_sent()) http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'uncaught: ' . $e->getMessage()]);
});

require_once __DIR__ . '/../rewards_bootstrap.php';
require_once __DIR__ . '/../db.php';

$email  = strtolower(trim((string) ($_GET['email'] ?? '')));
$recent = max(0, min(50, (int) ($_GET['recent'] ?? 10)));
$sig    = trim((string) ($_SERVER['HTTP_X_SIGNATURE'] ?? ''));
$secret = (string) (getenv('WBM_REWARDS_SHARED_SECRET') ?: '');

if ($secret === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server misconfigured — WBM_REWARDS_SHARED_SECRET not set']);
    exit;
}
if ($email === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'email required']);
    exit;
}
if ($sig === '') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'missing X-Signature header']);
    exit;
}
$expectedSig = hash_hmac('sha256', $email, $secret);
if (!hash_equals($expectedSig, $sig)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'X-Signature mismatch']);
    exit;
}

$pdo = rewards_db();

/* Total balance — SUM of points for this email. */
$st = $pdo->prepare("SELECT COALESCE(SUM(`points`), 0) AS `total`,
                            COUNT(*)                 AS `awards_count`
                       FROM `rewards_point_award`
                      WHERE `participant_email` = ?");
$st->execute([$email]);
$row = $st->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'awards_count' => 0];

$recentRows = [];
if ($recent > 0) {
    $st = $pdo->prepare("SELECT `rule_key`, `points`, `reason`,
                                `metric_date`, `awarded_at`
                           FROM `rewards_point_award`
                          WHERE `participant_email` = ?
                          ORDER BY `awarded_at` DESC
                          LIMIT " . $recent);
    $st->execute([$email]);
    $recentRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

echo json_encode([
    'ok'            => true,
    'email'         => $email,
    'total_points'  => (int) $row['total'],
    'awards_count'  => (int) $row['awards_count'],
    'recent_awards' => $recentRows,
], JSON_UNESCAPED_SLASHES);
