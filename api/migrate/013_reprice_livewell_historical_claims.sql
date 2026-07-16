-- 013_reprice_livewell_historical_claims.sql — re-price LiveWell claims to $2.50/pt.
--
-- 2026-07-17 (Marty: "Re-price historical claims").
--
-- WHY: redeem.php computes and STORES money_value at claim time
-- (`points_awarded * item.money_value_per_point`). Migration 011 re-priced the
-- CATALOGUE to the client's 7/15 rate ($2.50/pt, down from $5.00) but could not
-- touch money already recorded on claims made before it ran. The export reads
-- that stored money, so a quarter export today would hand the third party the
-- OLD rate and credit people at double what the client now agrees:
--
--     hgil@livewell.org     8 pts  ->  $40.00   (i.e. $5.00/pt)
--     amccarty@livewell.org 4 pts  ->  $20.00   (i.e. $5.00/pt)
--
-- These points become a REAL HSA credit downstream, so the stored figure must
-- match the client's agreed rate before any export is handed over.
--
-- SCOPE: SUB-MQ4KQLLF only. Every other sub keeps its own economics.
--
-- RATE: a flat $2.50/pt across the sub, NOT `points * item.money_value_per_point`.
-- The old items still carry 5.0000 (011 archived them but left their rate), so
-- recomputing from the item would be a no-op. $2.50 is the client's rate for
-- the whole LiveWell program, so it is the right constant here. The items
-- themselves are normalised below too, so any future recompute agrees.
--
-- AUDIT FIRST: the original money_value is written to rewards_audit before it
-- is overwritten. Silently discarding what a person was promised at scan time
-- would destroy the only evidence of the change — and this is money.
--
-- Voided claims are included: a void can be reversed, and an un-voided claim
-- must not resurrect the old rate.
--
-- Re-runnable: the UPDATE is idempotent (already-$2.50 rows recompute to
-- $2.50). Re-running WILL append a second audit row showing old == new, which
-- is harmless and honest.
-- ─────────────────────────────────────────────────────────────────

-- ── 1. Preserve what each claim was worth BEFORE we touch it ────────────
INSERT INTO `rewards_audit`
    (`actor_admin_user_id`, `action`, `entity_type`, `entity_id`, `details`)
SELECT
    NULL,
    'redemption_reprice',
    'rewards_redemption',
    CAST(r.`id` AS CHAR),
    JSON_OBJECT(
        'reason',          'LiveWell 7/15 Playbook re-price: HSA $5.00/pt -> $2.50/pt',
        'sub_id',          r.`sub_id`,
        'redeemer_email',  r.`redeemer_email`,
        'points_awarded',  r.`points_awarded`,
        'old_money_value', r.`money_value`,
        'new_money_value', ROUND(r.`points_awarded` * 2.50, 4),
        'migration',       '013_reprice_livewell_historical_claims'
    )
FROM `rewards_redemption` r
WHERE r.`sub_id` = 'SUB-MQ4KQLLF';

-- ── 2. Re-price the claims ──────────────────────────────────────────────
UPDATE `rewards_redemption`
   SET `money_value` = ROUND(`points_awarded` * 2.50, 4)
 WHERE `sub_id` = 'SUB-MQ4KQLLF';

-- ── 3. Normalise the archived items' rate so nothing still says $5.00 ────
-- The live items seeded by 011 are already 2.5000. This catches the 9 archived
-- ones from seed 005, so a future "recompute from the item" can never
-- resurrect the old rate.
UPDATE `rewards_item`
   SET `money_value_per_point` = 2.5000,
       `updated_at` = UTC_TIMESTAMP()
 WHERE `sub_id` = 'SUB-MQ4KQLLF'
   AND `money_value_per_point` <> 2.5000;
