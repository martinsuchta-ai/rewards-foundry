<?php
/**
 * api/lib/enrollment.php — rewards enrolment roster helper.
 *
 * 2026-07-18. Backs the Enrolments feature (migration 014,
 * `rewards_enrollment`). Two concerns:
 *
 *   - rewards_enrollment_resolve() — called from the transaction paths
 *     (/v1/redeem.php, earns). Auto-creates an ACTIVE `system` row the
 *     first time an email transacts on a sub, and returns the current
 *     status so the caller can gate. Redemption blocks when the status
 *     is not 'active'; earns just touch (never block).
 *
 *   - rewards_enrollment_tables_ready() — cheap probe so admin surfaces
 *     can show a "run migration 014" setup panel instead of a raw
 *     SQLSTATE[42S02] when the table hasn't been created yet.
 *
 * FAIL-OPEN by design: if the table is missing or any probe throws, we
 * return status=null and the redeem path treats that as "allow" — so
 * deploying this code BEFORE running migration 014 can never break
 * redemptions. Gating only ever kicks in once the table exists and a
 * row is explicitly suspended/unenrolled.
 */

if (!function_exists('rewards_enrollment_tables_ready')) :

/** True when the rewards_enrollment table exists. Cached per request. */
function rewards_enrollment_tables_ready(PDO $pdo): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $n = (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rewards_enrollment'"
        )->fetchColumn();
        $ready = ($n === 1);
    } catch (Throwable $e) {
        error_log('[rewards_enrollment] table probe failed: ' . $e->getMessage());
        $ready = false;
    }
    return $ready;
}

/**
 * Resolve (and lazily create) the enrolment for an email on a sub.
 *
 * @return array{status: ?string, created: bool, email: string, id: int}
 *   status: 'active' | 'suspended' | 'unenrolled', or NULL when it
 *   couldn't be determined (no email / table missing / error) — callers
 *   MUST treat null as "don't gate" (fail-open).
 */
function rewards_enrollment_resolve(PDO $pdo, string $subId, string $email,
                                    ?string $first = null, ?string $last = null,
                                    ?int $consumerId = null, string $source = 'system'): array {
    $email = strtolower(trim($email));
    if (!in_array($source, ['system', 'manual', 'location'], true)) $source = 'system';
    $out = ['status' => null, 'created' => false, 'email' => $email, 'id' => 0];
    if ($subId === '' || $email === '') return $out;
    if (!rewards_enrollment_tables_ready($pdo)) return $out;   /* fail-open pre-migration */

    $first = ($first !== null && trim($first) !== '') ? trim($first) : null;
    $last  = ($last  !== null && trim($last)  !== '') ? trim($last)  : null;

    try {
        $sel = $pdo->prepare(
            "SELECT `id`, `status`, `first_name`, `last_name`
               FROM `rewards_enrollment` WHERE `sub_id` = ? AND `email` = ? LIMIT 1"
        );
        $sel->execute([$subId, $email]);
        $row = $sel->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            /* Backfill the name when we now know it and the row was blank
               (a system row created from a key-only redemption before the
               membership check resolved the person's name). */
            if (($first !== null || $last !== null)
                && (string) ($row['first_name'] ?? '') === ''
                && (string) ($row['last_name']  ?? '') === '') {
                $pdo->prepare("UPDATE `rewards_enrollment` SET `first_name` = ?, `last_name` = ? WHERE `id` = ?")
                    ->execute([$first, $last, (int) $row['id']]);
            }
            return ['status' => (string) $row['status'], 'created' => false, 'email' => $email, 'id' => (int) $row['id']];
        }

        /* No row → auto-create an ACTIVE enrolment with the caller's source
           ('system' on a transaction, 'location' on a self-enrol). */
        $ins = $pdo->prepare(
            "INSERT INTO `rewards_enrollment`
               (`consumer_id`, `sub_id`, `email`, `first_name`, `last_name`, `source`, `status`)
             VALUES (?, ?, ?, ?, ?, ?, 'active')"
        );
        $ins->execute([$consumerId, $subId, $email, $first, $last, $source]);
        return ['status' => 'active', 'created' => true, 'email' => $email, 'id' => (int) $pdo->lastInsertId()];
    } catch (Throwable $e) {
        /* Unique-key race (another request just created it) → re-read.
           Any other error → fail-open (status null). */
        try {
            $sel->execute([$subId, $email]);
            $row = $sel->fetch(PDO::FETCH_ASSOC);
            if ($row) return ['status' => (string) $row['status'], 'created' => false, 'email' => $email, 'id' => (int) $row['id']];
        } catch (Throwable $_) {}
        error_log('[rewards_enrollment] resolve failed for ' . $subId . '/' . $email . ': ' . $e->getMessage());
        return $out;
    }
}

/** True when the status permits redemption. NULL status → allowed (fail-open). */
function rewards_enrollment_may_redeem(?string $status): bool {
    return ($status === null || $status === 'active');
}

endif;
