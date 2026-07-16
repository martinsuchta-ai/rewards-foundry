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

/* ── Balance = the point-award ledger + QR claims ──────────────────────────
   2026-07-17 — TWO fixes.

   1. QR CLAIMS WERE INVISIBLE. This summed `rewards_point_award` only, which
      is written by the wearables webhook and the schools behaviour scans.
      QR claims land in `rewards_redemption` (redeem.php CREDITS
      item.points_allocated — it never debits), and the two tables were never
      joined. LiveWell's Rewards Plus program is 100% QR scans, so a member
      could scan all quarter and still see a zero balance. Both count now.

   2. REJECTED AWARDS COUNTED. There was no `status` filter, so a schools
      award a teacher had rejected still added points. CONFIRMED only
      (NULL = pre-004 rows, treated as confirmed).

   Voided claims drop out via `voided` (mig009). We deliberately do NOT write
   a ledger row on claim: `rewards_redemption` already carries points +
   money_value + the item link the export needs, and mirroring it into the
   ledger would duplicate the money semantics and force void to reverse two
   rows. One read, two sources. */

$hasVoided = false;
try {
    $chk = $pdo->query("SELECT COUNT(*) FROM `information_schema`.`COLUMNS`
                         WHERE `TABLE_SCHEMA` = DATABASE()
                           AND `TABLE_NAME`   = 'rewards_redemption'
                           AND `COLUMN_NAME`  = 'voided'");
    $hasVoided = ((int) $chk->fetchColumn()) > 0;
} catch (Throwable $_e) { $hasVoided = false; }
$voidSql = $hasVoided ? " AND (`voided` IS NULL OR `voided` = 0)" : '';

/* Ledger side — wearables + behaviour scans. CONFIRMED only. */
$st = $pdo->prepare("SELECT COALESCE(SUM(`points`), 0) AS `total`,
                            COUNT(*)                   AS `cnt`
                       FROM `rewards_point_award`
                      WHERE `participant_email` = ?
                        AND (`status` IS NULL OR `status` = 'CONFIRMED')");
$st->execute([$email]);
$ledger = $st->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'cnt' => 0];

/* Claim side — QR scans / manual awards. Non-voided only. Carries money. */
$st = $pdo->prepare("SELECT COALESCE(SUM(`points_awarded`), 0) AS `total`,
                            COALESCE(SUM(`money_value`), 0)    AS `money`,
                            COUNT(*)                           AS `cnt`
                       FROM `rewards_redemption`
                      WHERE `redeemer_email` = ?" . $voidSql);
$st->execute([$email]);
$claims = $st->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'money' => 0, 'cnt' => 0];

$totalPoints = (int) $ledger['total'] + (int) $claims['total'];
$awardsCount = (int) $ledger['cnt']   + (int) $claims['cnt'];

/* Recent activity — merged across both sources, newest first. Rows carry
   `source_table` so a caller can tell a device sync from a scan. */
$recentRows = [];
if ($recent > 0) {
    $st = $pdo->prepare("SELECT `rule_key`, `points`, `reason`, `metric_date`,
                                `awarded_at`, 'ledger' AS `source_table`
                           FROM `rewards_point_award`
                          WHERE `participant_email` = ?
                            AND (`status` IS NULL OR `status` = 'CONFIRMED')
                          ORDER BY `awarded_at` DESC
                          LIMIT " . $recent);
    $st->execute([$email]);
    $merged = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $st = $pdo->prepare("SELECT r.`id`, r.`points_awarded`, r.`money_value`,
                                r.`redeemed_at`, i.`name` AS `item_name`
                           FROM `rewards_redemption` r
                      LEFT JOIN `rewards_item` i ON i.`id` = r.`rewards_item_id`
                          WHERE r.`redeemer_email` = ?"
                          . ($hasVoided ? " AND (r.`voided` IS NULL OR r.`voided` = 0)" : '') . "
                          ORDER BY r.`redeemed_at` DESC
                          LIMIT " . $recent);
    $st->execute([$email]);
    foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $c) {
        $merged[] = [
            'rule_key'     => 'claim:' . (int) $c['id'],
            'points'       => (int) $c['points_awarded'],
            'reason'       => (string) ($c['item_name'] ?? 'Reward claim'),
            'metric_date'  => substr((string) $c['redeemed_at'], 0, 10),
            'awarded_at'   => $c['redeemed_at'],
            'money_value'  => $c['money_value'] !== null ? (float) $c['money_value'] : null,
            'source_table' => 'claim',
        ];
    }
    usort($merged, function ($x, $y) {
        return strcmp((string) $y['awarded_at'], (string) $x['awarded_at']);
    });
    $recentRows = array_slice($merged, 0, $recent);
}

echo json_encode([
    'ok'            => true,
    'email'         => $email,
    'total_points'  => $totalPoints,
    'awards_count'  => $awardsCount,
    /* Split by source so a zero claims_points on a QR-only program (LiveWell)
       is immediately diagnosable rather than looking like "no activity". */
    'ledger_points' => (int) $ledger['total'],
    'claims_points' => (int) $claims['total'],
    'claims_money'  => (float) $claims['money'],
    'recent_awards' => $recentRows,
], JSON_UNESCAPED_SLASHES);
