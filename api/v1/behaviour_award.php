<?php
/**
 * /v1/behaviour_award.php — Schools Power-Up award endpoint.
 *
 * 2026-06-26. Phase C of the Schools Pilot. Pairs with the existing
 * /v1/redeem.php item-redemption endpoint:
 *
 *   /v1/redeem.php           — for rewards_item tokens (existing)
 *   /v1/behaviour_award.php  — for rewards_behaviour_activity tokens (this file)
 *
 * The public redeem.html page resolves the token via
 * GET /v1/redeem.php?t=<token> first; the response's `kind` field
 * routes it to either flow. Behaviour POSTs land here.
 *
 * AUTH MODEL
 *   No consumer key (same as /v1/redeem.php) — the qr_token IS the
 *   access credential. The participant's identity is verified via
 *   the WBM membership check (same fail-closed gate as redeem.php
 *   line 217+). The awarder's identity (for TRUSTED_PERSON flow) is
 *   recorded as a free-text email — Phase D management UI can later
 *   add an awarder-allowlist gate via WBM if abuse appears.
 *
 * REQUEST — POST application/json
 *   {
 *     "token":              "<qr_token>",          // OR via ?t=
 *     "source_type":        "TRUSTED_PERSON" | "SELF_SCAN",
 *     "participant_email":  "<student email>",     // OR participant_key
 *     "participant_key":    "<student KEY>",
 *     "awarded_by_email":   "<teacher email>"      // required for TRUSTED_PERSON
 *   }
 *
 * RESPONSE — application/json
 *   {
 *     "ok":             true,
 *     "award_id":       <id>,
 *     "status":         "CONFIRMED" | "PENDING",
 *     "points_applied": <signed int — POSITIVE for UP, NEGATIVE for DOWN>,
 *     "behaviour": { title, scope_label, direction, dimension_label },
 *     "balance":        <signed int — participant's current CONFIRMED-only balance>
 *   }
 *
 * Status mapping:
 *   - TRUSTED_PERSON  → CONFIRMED  (wallet moves immediately)
 *   - SELF_SCAN       → PENDING    (wallet stays until /v1/behaviour_approve.php confirms)
 *
 * IDEMPOTENCY
 *   rewards_point_award has a unique key on
 *   (participant_email, source, rule_key, metric_date). source is
 *   'schools_behaviour'; rule_key is the qr_token. So a TRUSTED_PERSON
 *   double-tap on the same student+behaviour on the same day returns
 *   409 with the existing row's award_id (idempotent — wallet not
 *   double-moved). For participant_key flows where email is NULL the
 *   unique key doesn't fire (NULL ≠ NULL in MySQL) — we accept that
 *   as a known edge case for the pilot.
 */

declare(strict_types=1);

require_once __DIR__ . '/../rewards_bootstrap.php';
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');
rewards_send_cors_origin();
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') rewards_json_err('POST required', 405);

$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body)) rewards_json_err('JSON body required', 400);

$token = trim((string) ($body['token'] ?? $_GET['t'] ?? ''));
$sourceType = strtoupper(trim((string) ($body['source_type'] ?? '')));
$pEmail = strtolower(trim((string) ($body['participant_email'] ?? '')));
$pKey   =            trim((string) ($body['participant_key']   ?? ''));
$aEmail = strtolower(trim((string) ($body['awarded_by_email']  ?? '')));

/* ── Validate inputs ───────────────────────────────────────────── */
if ($token === '' || !preg_match('/^[A-Za-z0-9_\-]{16,64}$/', $token)) {
    rewards_json_err('valid token required', 400);
}
if (!in_array($sourceType, ['TRUSTED_PERSON', 'SELF_SCAN'], true)) {
    rewards_json_err('source_type must be TRUSTED_PERSON or SELF_SCAN', 400);
}
if ($pEmail === '' && $pKey === '') {
    rewards_json_err('participant_email or participant_key required', 400);
}
if ($pEmail !== '' && !filter_var($pEmail, FILTER_VALIDATE_EMAIL)) {
    rewards_json_err('participant_email invalid', 400);
}
if ($pKey !== '' && !preg_match('/^[A-Za-z0-9_\-]{1,32}$/', $pKey)) {
    rewards_json_err('participant_key invalid (1-32 alphanumeric / dash / underscore)', 400);
}
if ($sourceType === 'TRUSTED_PERSON') {
    if ($aEmail === '') {
        rewards_json_err('awarded_by_email required for TRUSTED_PERSON source', 400);
    }
    if (!filter_var($aEmail, FILTER_VALIDATE_EMAIL)) {
        rewards_json_err('awarded_by_email invalid', 400);
    }
}

/* ── Rate limit (per ip_hash + day-bucket) — same envelope as redeem.php ── */
$ipHash = rewards_anonymise_ip();
$today  = gmdate('Y-m-d');
$rateMax = (int) (getenv('REWARDS_REDEEM_RATE_PER_DAY') ?: 20);

try {
    $pdo = rewards_db();

    if ($ipHash !== '') {
        $pdo->prepare(
            "INSERT INTO `rewards_rate_limit` (`ip_hash`, `day_bucket`, `count`, `last_at`)
             VALUES (?, ?, 1, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE `count` = `count` + 1, `last_at` = UTC_TIMESTAMP()"
        )->execute([$ipHash, $today]);

        $r = $pdo->prepare("SELECT `count` FROM `rewards_rate_limit` WHERE `ip_hash` = ? AND `day_bucket` = ?");
        $r->execute([$ipHash, $today]);
        $count = (int) $r->fetchColumn();
        if ($count > $rateMax) {
            rewards_json_err('rate limit exceeded — try again tomorrow', 429,
                ['rate' => ['limit' => $rateMax, 'count' => $count]]);
        }
    }

    /* ── Activity + catalogue lookup ───────────────────────────── */
    $st = $pdo->prepare(
        "SELECT a.`id`, a.`consumer_id`, a.`sub_id`, a.`catalogue_id`,
                a.`scope`, a.`direction`, a.`dimension_key`,
                a.`title`, a.`points`, a.`self_scan_enabled`, a.`active`,
                c.`edition_key`, c.`scope_labels`, c.`dimension_labels`,
                c.`trusted_role_label`, c.`self_scan_policy`
           FROM `rewards_behaviour_activity` a
           JOIN `rewards_behaviour_catalogue` c ON c.`id` = a.`catalogue_id`
          WHERE a.`qr_token` = ? LIMIT 1"
    );
    $st->execute([$token]);
    $bx = $st->fetch(PDO::FETCH_ASSOC);
    if (!$bx) rewards_json_err('behaviour not found', 404);
    if ((int) $bx['active'] !== 1) rewards_json_err('behaviour no longer active', 410);

    $direction = (string) $bx['direction'];
    $scope     = (string) $bx['scope'];
    $dim       = (string) ($bx['dimension_key'] ?? '');
    $policy    = (string) $bx['self_scan_policy'];
    $rowAllowsSelf = ((int) $bx['self_scan_enabled']) === 1;

    /* ── Self-scan policy gate ────────────────────────────────── */
    if ($sourceType === 'SELF_SCAN') {
        $allowed = false;
        if ($policy === 'allow')        $allowed = $rowAllowsSelf;
        elseif ($policy === 'spin_up_only') $allowed = $rowAllowsSelf && ($direction === 'UP');
        /* policy='never' → allowed stays false */

        if (!$allowed) {
            rewards_json_err(
                'self_scan_not_permitted',
                403,
                ['message' => 'This behaviour can only be recorded by a trusted person ('
                              . (string) $bx['trusted_role_label'] . ').']
            );
        }
    }

    /* ── WBM membership HARD GATE — verify the PARTICIPANT (student)
       is on the linked sub. Same fail-closed pattern as redeem.php
       line 217+. ─────────────────────────────────────────────── */
    $subId = (string) $bx['sub_id'];
    $wbmRespondent = [];
    $wbmOrgName    = '';
    if (preg_match('/^SUB-[A-Za-z0-9]{1,32}$/', $subId)) {
        $wbmSecret = (string) getenv('WBM_REWARDS_SHARED_SECRET');
        if ($wbmSecret === '') {
            rewards_json_err(
                'membership_check_misconfigured', 500,
                ['message' => 'This behaviour is linked to a Wellbeing Matters subscription, but the membership check is not configured. Please contact the admin.']
            );
        }
        $checkBase = (string) (getenv('WBM_MEMBERSHIP_CHECK_URL') ?: 'https://smart-tools-foundry.com/WBM/api/wbm_membership_check.php');
        $checkQs   = 'secret=' . urlencode($wbmSecret)
                   . '&sub_id=' . urlencode($subId)
                   . ($pEmail !== '' ? ('&email=' . urlencode($pEmail)) : '')
                   . ($pKey   !== '' ? ('&key='   . urlencode($pKey))   : '');
        $checkRes  = null;
        $checkErr  = '';
        try {
            $ch = curl_init($checkBase . '?' . $checkQs);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT        => 6,
                CURLOPT_USERAGENT      => 'rewards-foundry/behaviour_award',
            ]);
            $rs = curl_exec($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($http === 200 && $rs !== false) {
                $j = json_decode((string) $rs, true);
                if (is_array($j) && !empty($j['ok'])) {
                    $checkRes = $j;
                } else {
                    $checkErr = 'invalid response shape';
                }
            } else {
                $checkErr = 'HTTP ' . $http;
            }
        } catch (Throwable $_eCheck) {
            $checkErr = $_eCheck->getMessage();
            error_log('[behaviour_award] WBM membership check threw: ' . $checkErr);
        }
        if ($checkRes === null) {
            error_log('[behaviour_award] WBM membership check failed for sub ' . $subId . ': ' . $checkErr);
            rewards_json_err(
                'membership_check_failed', 503,
                ['message' => 'We couldn\'t verify the student\'s Wellbeing Matters membership right now — please try again in a moment.']
            );
        }
        if (empty($checkRes['in_wbm'])) {
            $orgName  = trim((string) (($checkRes['org']['name'] ?? '')));
            $identHint = $pEmail !== '' ? ('the email entered (' . $pEmail . ')') : ('the access code entered');
            $msg = 'We couldn\'t find ' . $identHint . ' on the '
                 . ($orgName !== '' ? ($orgName . ' ') : '')
                 . 'Wellbeing Matters membership linked to this behaviour. '
                 . 'Only students enrolled on that membership can have wallet points recorded.';
            rewards_json_err('not_a_member', 403, ['message' => $msg]);
        }
        $wbmRespondent = is_array($checkRes['respondent'] ?? null) ? $checkRes['respondent'] : [];
        $wbmOrgName    = trim((string) (($checkRes['org']['name'] ?? '')));
    }

    /* ── Resolve signed points + status + INSERT ──────────────── */
    $pointsMagnitude = (int) $bx['points'];
    $pointsApplied   = ($direction === 'UP') ? $pointsMagnitude : -$pointsMagnitude;
    $status = ($sourceType === 'TRUSTED_PERSON') ? 'CONFIRMED' : 'PENDING';
    $awarderForDb = ($sourceType === 'TRUSTED_PERSON') ? $aEmail : null;

    try {
        $ins = $pdo->prepare(
            "INSERT INTO `rewards_point_award`
                (`participant_email`, `awarded_by_email`, `participant_key`,
                 `source`, `source_type`, `status`,
                 `rule_key`, `behaviour_activity_id`,
                 `points`, `reason`, `metric_date`)
             VALUES (?, ?, ?, 'schools_behaviour', ?, ?, ?, ?, ?, ?, ?)"
        );
        $ins->execute([
            $pEmail !== '' ? $pEmail : null,
            $awarderForDb,
            $pKey !== '' ? $pKey : null,
            $sourceType,
            $status,
            $token,                          /* rule_key */
            (int) $bx['id'],                 /* behaviour_activity_id */
            $pointsApplied,
            (string) $bx['title'],           /* reason */
            $today,
        ]);
        $awardId = (int) $pdo->lastInsertId();
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        /* MySQL duplicate-key on the (email, source, rule_key, metric_date)
           unique index → idempotent reply (return the existing row instead
           of failing). */
        if (stripos($msg, 'duplicate') !== false || stripos($msg, '1062') !== false) {
            $dq = $pdo->prepare(
                "SELECT `id`, `status`, `points` FROM `rewards_point_award`
                  WHERE `participant_email` = ? AND `source` = 'schools_behaviour'
                    AND `rule_key` = ? AND `metric_date` = ? LIMIT 1");
            $dq->execute([$pEmail, $token, $today]);
            $existing = $dq->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                rewards_json_ok([
                    'ok'             => true,
                    'idempotent'     => true,
                    'award_id'       => (int) $existing['id'],
                    'status'         => (string) $existing['status'],
                    'points_applied' => (int) $existing['points'],
                    'message'        => 'Already recorded for this student today — no change.',
                ]);
            }
        }
        rewards_safe_error_response($e, 'award insert failed');
    }

    /* ── Auto-enrol the participant (migration 014) ──────────────────
       A behaviour award IS a transaction, so ensure the student has an
       enrolment on the sub. Email + name come from the participant email
       or the WBM membership response (which resolves both even on
       key-only scans). Touch only — earning is never gated (only
       redemption is). Fail-open: no email / table not migrated / any
       error → skip silently, never fail the award. */
    $enrEmail = $pEmail;
    $enrFirst = null; $enrLast = null;
    if (is_array($wbmRespondent) && $wbmRespondent) {
        if ($enrEmail === '') $enrEmail = strtolower(trim((string) ($wbmRespondent['email'] ?? '')));
        $enrFirst = trim((string) ($wbmRespondent['first_name'] ?? $wbmRespondent['firstName'] ?? '')) ?: null;
        $enrLast  = trim((string) ($wbmRespondent['last_name']  ?? $wbmRespondent['lastName']  ?? '')) ?: null;
    }
    if ($enrEmail !== '' && preg_match('/^SUB-[A-Za-z0-9]{1,32}$/', $subId)) {
        try {
            require_once __DIR__ . '/../lib/enrollment.php';
            rewards_enrollment_resolve($pdo, $subId, $enrEmail, $enrFirst, $enrLast, (int) $bx['consumer_id']);
        } catch (Throwable $_eEnr) { /* never fail the award on enrolment */ }
    }

    /* ── Compute the participant's current CONFIRMED-only balance.
       Only meaningful when we have an email; KEY-only flows skip. */
    $balance = null;
    if ($pEmail !== '') {
        try {
            $bq = $pdo->prepare(
                "SELECT COALESCE(SUM(`points`), 0) FROM `rewards_point_award`
                  WHERE `participant_email` = ? AND `status` = 'CONFIRMED'");
            $bq->execute([$pEmail]);
            $balance = (int) $bq->fetchColumn();
        } catch (Throwable $_eBal) { /* non-fatal */ }
    }

    /* ── Build behaviour summary for the response ───────────────── */
    $scopeLabels = json_decode((string) $bx['scope_labels'],     true) ?: [];
    $dimLabels   = json_decode((string) $bx['dimension_labels'], true) ?: [];
    $behaviourSummary = [
        'title'           => (string) $bx['title'],
        'scope'           => $scope,
        'scope_label'     => (string) ($scopeLabels[$scope] ?? $scope),
        'direction'       => $direction,
        'dimension_key'   => $dim !== '' ? $dim : null,
        'dimension_label' => $dim !== '' ? (string) ($dimLabels[$dim] ?? $dim) : null,
    ];

    rewards_json_ok([
        'award_id'       => $awardId,
        'status'         => $status,
        'points_applied' => $pointsApplied,
        'behaviour'      => $behaviourSummary,
        'balance'        => $balance,
        'student'        => $wbmRespondent ? [
            'first_name' => (string) ($wbmRespondent['first_name'] ?? ''),
        ] : [],
        'org'            => ['name' => $wbmOrgName],
        'message'        => $status === 'CONFIRMED'
                              ? 'Wallet updated.'
                              : 'Recorded — awaiting teacher confirmation.',
    ]);
} catch (Throwable $e) {
    rewards_safe_error_response($e, 'behaviour award failed');
}
