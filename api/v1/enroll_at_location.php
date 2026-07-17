<?php
/**
 * /v1/enroll_at_location.php — self-enrolment at the rewards redemption page.
 *
 *   POST { token, first_name, last_name, email, email_confirm }   (OR token via ?t=)
 *
 * When a sub turns on "allow enrolment" (item.allow_enrollment = 1, stamped
 * from the WBM power-up), the public redeem page shows a "Not enrolled yet?
 * Enrol now" affordance. This endpoint enrols the visitor against the sub
 * (source='location', displayed "At Rewards Location") AND awards them the
 * points for the reward whose QR they scanned — enrol + claim in one action.
 *
 * TRUST MODEL: like /v1/redeem.php, the qr_token IS the credential — but
 * UNLIKE redeem.php this path deliberately does NOT run the WBM membership
 * hard gate, because its whole purpose is to enrol people who are NOT yet
 * members. It's therefore gated by: the per-item allow_enrollment opt-in,
 * the per-IP rate limit, and the item's max_redemptions_per_person cap.
 * A suspended / unenrolled email is still blocked (no self-reactivation).
 *
 * Double-entry email is enforced server-side (also in the page UI).
 */

declare(strict_types=1);

require_once __DIR__ . '/../rewards_bootstrap.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/enrollment.php';

header('Content-Type: application/json; charset=utf-8');
rewards_send_cors_origin();
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') rewards_json_err('POST required', 405);

$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body)) rewards_json_err('JSON body required', 400);

$token  = trim((string) ($body['token'] ?? $_GET['t'] ?? ''));
$first  = trim((string) ($body['first_name'] ?? ''));
$last   = trim((string) ($body['last_name'] ?? ''));
$email  = strtolower(trim((string) ($body['email'] ?? '')));
$email2 = strtolower(trim((string) ($body['email_confirm'] ?? '')));

if ($token === '' || !preg_match('/^[A-Za-z0-9_\-]{16,64}$/', $token)) rewards_json_err('valid token required', 400);
if ($first === '' || $last === '') rewards_json_err('first and last name are required', 400);
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) rewards_json_err('a valid email is required', 400);
if ($email2 === '' || $email !== $email2) rewards_json_err('the two email addresses don\'t match', 422);

$ipHash  = rewards_anonymise_ip();
$today   = gmdate('Y-m-d');
$rateMax = (int) (getenv('REWARDS_REDEEM_RATE_PER_DAY') ?: 20);

try {
    $pdo = rewards_db();

    /* ── Rate limit (per ip_hash + day) — same envelope as redeem.php ── */
    if ($ipHash !== '') {
        $pdo->prepare(
            "INSERT INTO `rewards_rate_limit` (`ip_hash`, `day_bucket`, `count`, `last_at`)
             VALUES (?, ?, 1, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE `count` = `count` + 1, `last_at` = UTC_TIMESTAMP()"
        )->execute([$ipHash, $today]);
        $r = $pdo->prepare("SELECT `count` FROM `rewards_rate_limit` WHERE `ip_hash` = ? AND `day_bucket` = ?");
        $r->execute([$ipHash, $today]);
        if ((int) $r->fetchColumn() > $rateMax) {
            rewards_json_err('rate limit exceeded — try again tomorrow', 429, ['rate' => ['limit' => $rateMax]]);
        }
    }

    /* allow_enrollment lands in migration 015 — a clean 409 if not migrated. */
    $hasAE = false;
    try {
        $hasAE = ((int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rewards_item'
                AND COLUMN_NAME = 'allow_enrollment'"
        )->fetchColumn()) > 0;
    } catch (Throwable $e) { $hasAE = false; }
    if (!$hasAE) rewards_json_err('self-enrolment isn\'t set up yet — run migration 015', 409, ['setup' => true, 'migration' => '015_enrol_at_location']);

    /* ── Item lookup — must exist, be active, and allow enrolment ── */
    $st = $pdo->prepare(
        "SELECT `id`, `consumer_id`, `sub_id`, `name`,
                `points_allocated`, `money_value_per_point`, `currency`,
                `max_redemptions_per_person`, `is_active`, `allow_enrollment`
           FROM `rewards_item` WHERE `qr_token` = ? LIMIT 1"
    );
    $st->execute([$token]);
    $item = $st->fetch(PDO::FETCH_ASSOC);
    if (!$item) rewards_json_err('reward not found', 404);
    if ((int) $item['is_active'] !== 1) rewards_json_err('reward no longer active', 410);
    if ((int) ($item['allow_enrollment'] ?? 0) !== 1) rewards_json_err('self-enrolment is not enabled for this reward', 403);

    $subId = (string) $item['sub_id'];
    if (!preg_match('/^SUB-[A-Za-z0-9]{1,32}$/', $subId)) rewards_json_err('this reward is not linked to a subscription', 409);

    /* ── Enrol (source='location') ─────────────────────────────────
       Creates an ACTIVE row if new. If an enrolment already exists we
       DON'T re-create it; a suspended/unenrolled person is blocked
       (no self-reactivation), an active one proceeds to the award. */
    $enr = rewards_enrollment_resolve($pdo, $subId, $email, $first, $last, (int) $item['consumer_id'], 'location');
    if (!rewards_enrollment_may_redeem($enr['status'])) {
        $m = ($enr['status'] === 'suspended')
            ? 'Your rewards enrolment is currently suspended, so points can\'t be awarded. Please contact your programme administrator.'
            : 'You\'re no longer enrolled in this programme, so points can\'t be awarded. Please contact your programme administrator.';
        rewards_json_err('enrolment_' . $enr['status'], 403, ['message' => $m]);
    }

    /* ── Per-person cap on the reward (the award is a redemption) ── */
    $cap = $item['max_redemptions_per_person'];
    if ($cap !== null && (int) $cap > 0) {
        $cs = $pdo->prepare("SELECT COUNT(*) FROM `rewards_redemption` WHERE `rewards_item_id` = ? AND `redeemer_email` = ?");
        $cs->execute([(int) $item['id'], $email]);
        if ((int) $cs->fetchColumn() >= (int) $cap) {
            rewards_json_err("you've already claimed this reward the maximum number of times ($cap per person)", 409,
                ['already_enrolled' => !$enr['created'], 'cap' => (int) $cap]);
        }
    }

    /* ── Award the reward's points (insert a redemption) ── */
    $points   = (int) $item['points_allocated'];
    $money    = round($points * (float) $item['money_value_per_point'], 4);
    $currency = (string) $item['currency'];
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 512) : null;
    $ins = $pdo->prepare(
        "INSERT INTO `rewards_redemption`
           (`consumer_id`, `rewards_item_id`, `sub_id`, `redeemer_email`,
            `points_awarded`, `money_value`, `currency`, `ip_hash`, `user_agent`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $ins->execute([
        (int) $item['consumer_id'], (int) $item['id'], $subId, $email,
        $points, $money, $currency, ($ipHash !== '' ? $ipHash : null), $ua,
    ]);
    $redemptionId = (int) $pdo->lastInsertId();

    /* Notification stub — same deferred pattern as redeem.php. */
    try {
        $pdo->prepare("INSERT INTO `rewards_redemption_notification` (`redemption_id`, `recipient_email`, `status`) VALUES (?, ?, 'pending')")
            ->execute([$redemptionId, $email]);
    } catch (Throwable $_eNotif) { /* non-fatal */ }

} catch (Throwable $e) {
    rewards_safe_error_response($e, 'enrolment failed');
}

rewards_json_ok([
    'enrolled'         => $enr['created'],
    'already_enrolled' => !$enr['created'],
    'redemption_id'    => $redemptionId,
    'item_name'        => (string) $item['name'],
    'points_awarded'   => $points,
    'money_value'      => round($money, 4),
    'currency'         => $currency,
    'message'          => $enr['created']
        ? 'You\'re enrolled — and your reward is recorded. Thanks for joining!'
        : 'Welcome back — your reward is recorded.',
]);
