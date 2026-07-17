<?php
/**
 * /v1/wearable_reward_credit.php — receives wearable reward
 * awards from WBM and persists them in rewards_point_award.
 *
 * Phase 2 wearables ship 2026-06-26. Companion to WBM commit
 * a2f9f26a (wearable_reward_rules.php + sync.php extension).
 *
 * Auth model:
 *   X-Signature header = HMAC-SHA256(body, WBM_REWARDS_SHARED_SECRET)
 *   The shared secret is the same one used by the redemption
 *   HARD GATE — bidirectional now. WBM signs outbound, this
 *   endpoint verifies inbound.
 *
 * Request body (JSON):
 *   {
 *     "source":    "wbm_wearable_sync",
 *     "email":     "user@example.com",
 *     "awards":    [
 *       {
 *         "rule_key":     "daily_steps_goal",
 *         "points":       5,
 *         "reason":       "Hit 8421 steps on 2026-06-26",
 *         "fired_at_iso": "2026-06-26T08:21:00Z",
 *         "metric_date":  "2026-06-26"
 *       },
 *       ...
 *     ],
 *     "timestamp": "2026-06-26T08:21:00Z"
 *   }
 *
 * Returns:
 *   { ok: true, awards_credited: <int>, awards_duplicate: <int>,
 *     total_points: <int> }
 *
 * Idempotency: the unique index on
 *   (participant_email, source, rule_key, metric_date)
 * silently ignores duplicate POSTs. So if WBM ever resends (e.g.
 * a sync retry or network hiccup), no double-credit happens.
 *
 * 2026-06-26 — Phase 2 ship.
 */

declare(strict_types=1);
@set_time_limit(15);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

set_exception_handler(function ($e) {
    if (!headers_sent()) http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'uncaught: ' . $e->getMessage(),
    ]);
});

require_once __DIR__ . '/../rewards_bootstrap.php';
require_once __DIR__ . '/../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

/* ── 1. Read body + verify HMAC ─────────────────────────────── */
$rawBody = file_get_contents('php://input') ?: '';
$sigGiven = trim((string) ($_SERVER['HTTP_X_SIGNATURE'] ?? ''));
$secret   = (string) (getenv('WBM_REWARDS_SHARED_SECRET') ?: '');

if ($secret === '') {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'server misconfigured — WBM_REWARDS_SHARED_SECRET not set',
    ]);
    exit;
}
if ($sigGiven === '') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'missing X-Signature header']);
    exit;
}
$sigExpected = hash_hmac('sha256', $rawBody, $secret);
if (!hash_equals($sigExpected, $sigGiven)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'X-Signature mismatch']);
    exit;
}

/* ── 2. Parse + validate ────────────────────────────────────── */
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'body is not valid JSON']);
    exit;
}
$source = (string) ($payload['source'] ?? '');
$email  = strtolower(trim((string) ($payload['email'] ?? '')));
$awards = is_array($payload['awards'] ?? null) ? $payload['awards'] : [];

if ($source === '' || $email === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'source + email required']);
    exit;
}
if (empty($awards)) {
    /* Not an error — WBM occasionally fires the webhook even when
       no new awards if the rules engine returned an empty list
       (defensive coding on its side). Return ok with zero
       credited so the WBM log message is clear. */
    echo json_encode([
        'ok'                => true,
        'awards_credited'   => 0,
        'awards_duplicate'  => 0,
        'total_points'      => 0,
        'note'              => 'no awards in payload',
    ]);
    exit;
}

/* ── 3. Insert each award ───────────────────────────────────── */
$pdo = rewards_db();

$credited  = 0;
$duplicate = 0;
$totalPts  = 0;
$failures  = [];

$ins = $pdo->prepare("
    INSERT IGNORE INTO `rewards_point_award`
        (`participant_email`, `source`, `rule_key`, `points`,
         `reason`, `metric_date`, `awarded_at`)
    VALUES (?, ?, ?, ?, ?, ?, ?)
");

foreach ($awards as $a) {
    if (!is_array($a)) continue;
    $ruleKey    = (string) ($a['rule_key'] ?? '');
    $points     = (int)    ($a['points']   ?? 0);
    $reason     = (string) ($a['reason']   ?? '');
    $metricDate = (string) ($a['metric_date'] ?? '') ?: null;
    $firedAtIso = (string) ($a['fired_at_iso'] ?? '');
    if ($ruleKey === '' || $points === 0) {
        $failures[] = ['award' => $a, 'reason' => 'missing rule_key or zero points'];
        continue;
    }
    /* Convert ISO timestamp to MySQL DATETIME UTC. */
    $awardedAt = gmdate('Y-m-d H:i:s');
    if ($firedAtIso !== '') {
        try {
            $dt = new DateTimeImmutable($firedAtIso);
            $dt = $dt->setTimezone(new DateTimeZone('UTC'));
            $awardedAt = $dt->format('Y-m-d H:i:s');
        } catch (Throwable $_e) { /* fall back to now */ }
    }
    try {
        $ins->execute([$email, $source, $ruleKey, $points, $reason, $metricDate, $awardedAt]);
        $affected = $ins->rowCount();
        if ($affected === 1) {
            $credited++;
            $totalPts += $points;
        } else {
            $duplicate++;
        }
    } catch (Throwable $e) {
        $failures[] = ['award' => $a, 'reason' => 'insert_failed: ' . $e->getMessage()];
    }
}

/* ── 4. Auto-enrol the participant (migration 014) ──────────────────
   A wearable earn IS a transaction, so ensure the person has an
   enrolment on their rewards sub. sub_id + name come from the WBM sync
   payload (WBM knows the rule→sub scope and the respondent's name — RF
   holds neither). Touch only: earning is never gated (only redemption
   is), so the returned status is ignored. Fail-open — no sub_id / table
   not migrated / any error → skip silently, never fail the credit. */
$enrSub = trim((string) ($payload['sub_id'] ?? ''));
if ($enrSub !== '' && $email !== '') {
    try {
        require_once __DIR__ . '/../lib/enrollment.php';
        rewards_enrollment_resolve(
            $pdo, $enrSub, $email,
            (trim((string) ($payload['first_name'] ?? '')) ?: null),
            (trim((string) ($payload['last_name'] ?? '')) ?: null)
        );
    } catch (Throwable $_eEnr) { /* never fail the credit on enrolment */ }
}

echo json_encode([
    'ok'               => true,
    'awards_credited'  => $credited,
    'awards_duplicate' => $duplicate,
    'total_points'     => $totalPts,
    'failures'         => $failures,
], JSON_UNESCAPED_SLASHES);
