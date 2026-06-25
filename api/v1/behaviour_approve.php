<?php
/**
 * /v1/behaviour_approve.php — confirm or reject a PENDING behaviour award.
 *
 * 2026-06-26. Phase C of the Schools Pilot. Pairs with
 * /v1/behaviour_award.php:
 *
 *   /v1/behaviour_award.php   — public, token-auth, INSERTs PENDING|CONFIRMED
 *   /v1/behaviour_approve.php — consumer-key auth, flips PENDING → CONFIRMED|REJECTED
 *
 * AUTH MODEL
 *   Consumer-key auth via X-Consumer-Key header (the same key WBM
 *   already uses for the wearable_reward_credit endpoint). The
 *   approver_email is recorded as a free-text field but trusted
 *   because the calling consumer is authenticated.
 *
 *   Phase C's rationale: WBM proxies the approval call from a
 *   teacher-authenticated surface (the management UI in Phase D).
 *   WBM is the source of truth for "who is a teacher on which sub";
 *   rewards-foundry trusts the consumer to have done that check.
 *
 * REQUEST — POST application/json
 *   Headers:
 *     X-Consumer-Key: <consumer api key>
 *   Body:
 *     {
 *       "award_id":       <id of the PENDING rewards_point_award row>,
 *       "action":         "CONFIRM" | "REJECT",
 *       "approver_email": "<teacher email>"
 *     }
 *
 * RESPONSE — application/json
 *   {
 *     "ok":             true,
 *     "award_id":       <id>,
 *     "previous_status":"PENDING",
 *     "status":         "CONFIRMED" | "REJECTED",
 *     "points_applied": <signed int>,
 *     "balance":        <participant balance after the flip — CONFIRMED-only>
 *   }
 *
 * GUARDS
 *   - Award row must exist + must currently be PENDING (idempotency:
 *     if already CONFIRMED or REJECTED, returns 409 with current state)
 *   - Award row must belong to the calling consumer (cross-tenant
 *     attempts fail with 403)
 *   - Action must be CONFIRM or REJECT
 *
 * LEDGER MATH
 *   - Award row's `points` value was set at INSERT time (signed,
 *     direction-aware). On CONFIRM the row's STATUS flips and the
 *     balance derivation (SUM WHERE status=CONFIRMED) picks it up
 *     automatically. On REJECT the row stays in place for audit
 *     but never counts toward balance.
 */

declare(strict_types=1);

require_once __DIR__ . '/../rewards_bootstrap.php';
require_once __DIR__ . '/../rewards_consumer_auth.php';
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');
rewards_send_cors_origin();
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Consumer-Key');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') rewards_json_err('POST required', 405);

/* ── Consumer-key auth (mirrors wearable_reward_credit.php) ────── */
$consumer = rewards_consumer_auth_check();   /* exits 401/403 on failure */
$consumerId = (int) $consumer['id'];

$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body)) rewards_json_err('JSON body required', 400);

$awardId  = (int)    ($body['award_id'] ?? 0);
$action   = strtoupper(trim((string) ($body['action'] ?? '')));
$approver = strtolower(trim((string) ($body['approver_email'] ?? '')));

if ($awardId <= 0)                           rewards_json_err('award_id required', 400);
if (!in_array($action, ['CONFIRM','REJECT'], true)) rewards_json_err('action must be CONFIRM or REJECT', 400);
if ($approver === '')                        rewards_json_err('approver_email required', 400);
if (!filter_var($approver, FILTER_VALIDATE_EMAIL))  rewards_json_err('approver_email invalid', 400);

try {
    $pdo = rewards_db();

    /* ── Look up the award row + its activity (for consumer match) ── */
    $st = $pdo->prepare(
        "SELECT pa.`id`, pa.`participant_email`, pa.`status`,
                pa.`points`, pa.`source`, pa.`behaviour_activity_id`,
                ba.`consumer_id`
           FROM `rewards_point_award` pa
           LEFT JOIN `rewards_behaviour_activity` ba
                  ON ba.`id` = pa.`behaviour_activity_id`
          WHERE pa.`id` = ? LIMIT 1"
    );
    $st->execute([$awardId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) rewards_json_err('award not found', 404);

    /* Cross-tenant guard. behaviour_activity_id can be NULL for
       non-behaviour awards (wearables), but those shouldn't reach
       this endpoint — reject anyway. */
    $awardConsumer = isset($row['consumer_id']) ? (int) $row['consumer_id'] : 0;
    if ($awardConsumer === 0 || $awardConsumer !== $consumerId) {
        rewards_json_err('award does not belong to this consumer', 403);
    }

    /* Idempotency: if already finalised, return current state with 409. */
    $currentStatus = (string) $row['status'];
    if ($currentStatus !== 'PENDING') {
        rewards_json_err('award already finalised', 409, [
            'award_id'        => $awardId,
            'status'          => $currentStatus,
            'message'         => 'This award is no longer pending — current state is ' . $currentStatus . '.',
        ]);
    }

    $newStatus = $action === 'CONFIRM' ? 'CONFIRMED' : 'REJECTED';

    /* Flip the row. Stamp the approver. The reason field gets a
       suffix so the audit trail records who approved/rejected. */
    $upd = $pdo->prepare(
        "UPDATE `rewards_point_award`
            SET `status` = ?, `awarded_by_email` = ?
          WHERE `id` = ? AND `status` = 'PENDING' LIMIT 1");
    $upd->execute([$newStatus, $approver, $awardId]);
    if ($upd->rowCount() === 0) {
        /* Race condition — another caller flipped it in between.
           Re-read and return current state. */
        $st->execute([$awardId]);
        $row2 = $st->fetch(PDO::FETCH_ASSOC) ?: $row;
        rewards_json_err('award already finalised', 409, [
            'award_id'        => $awardId,
            'status'          => (string) $row2['status'],
            'message'         => 'A concurrent approver finalised this award first.',
        ]);
    }

    /* ── Audit log ───────────────────────────────────────────── */
    try {
        $aud = $pdo->prepare(
            "INSERT INTO `rewards_audit`
                (`actor_admin_user_id`, `action`, `entity_type`, `entity_id`, `details`)
             VALUES (NULL, 'behaviour_award_' . ? , 'rewards_point_award', ?, ?)");
        /* PHP can't string-concat in prepared SQL; rebuild the action
           value into the bind list. */
    } catch (Throwable $_) { /* ignore — non-fatal */ }
    /* Plain insert (the prepared version above with concat is awkward). */
    try {
        $auditAction = $action === 'CONFIRM' ? 'behaviour_award_confirmed' : 'behaviour_award_rejected';
        $pdo->prepare(
            "INSERT INTO `rewards_audit`
                (`actor_admin_user_id`, `action`, `entity_type`, `entity_id`, `details`)
             VALUES (NULL, ?, 'rewards_point_award', ?, ?)"
        )->execute([
            $auditAction,
            (string) $awardId,
            json_encode([
                'approver_email'  => $approver,
                'previous_status' => 'PENDING',
                'new_status'      => $newStatus,
                'consumer_id'     => $consumerId,
                'points'          => (int) $row['points'],
            ]),
        ]);
    } catch (Throwable $_e) { /* non-fatal */ }

    /* ── Compute participant's current CONFIRMED-only balance ──── */
    $balance = null;
    $pEmail = (string) ($row['participant_email'] ?? '');
    if ($pEmail !== '') {
        try {
            $bq = $pdo->prepare(
                "SELECT COALESCE(SUM(`points`), 0) FROM `rewards_point_award`
                  WHERE `participant_email` = ? AND `status` = 'CONFIRMED'");
            $bq->execute([$pEmail]);
            $balance = (int) $bq->fetchColumn();
        } catch (Throwable $_eBal) { /* non-fatal */ }
    }

    rewards_json_ok([
        'award_id'        => $awardId,
        'previous_status' => 'PENDING',
        'status'          => $newStatus,
        'points_applied'  => (int) $row['points'],
        'balance'         => $balance,
        'message'         => $newStatus === 'CONFIRMED'
                                ? 'Award confirmed; balance updated.'
                                : 'Award rejected; no points applied.',
    ]);
} catch (Throwable $e) {
    rewards_safe_error_response($e, 'approve failed');
}
