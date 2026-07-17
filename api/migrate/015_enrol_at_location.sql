-- 015_enrol_at_location.sql — self-enrolment at the rewards redemption page.
--
-- 2026-07-18 (Marty). When a sub turns on "allow enrolment", the public redeem
-- page shows a "Not enrolled yet? Enrol now" affordance. A visitor enters their
-- first/last/email (double-entry) and is enrolled against the sub AND awarded
-- the points for the reward they are standing in front of. Recorded as a third
-- enrolment source, displayed "At Rewards Location".
--
-- Two changes:
--   1. rewards_item.allow_enrollment — per-item flag, stamped from the WBM sub
--      power-up by rewards_proxy.php on create/update (same pattern as
--      enforce_account). /v1/redeem.php GET returns it so redeem.html can show
--      the enrol area.
--   2. rewards_enrollment.source — add 'location' to the enum (was system|manual).
-- ─────────────────────────────────────────────────────────────────

ALTER TABLE `rewards_item`
    ADD COLUMN `allow_enrollment` TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE `rewards_enrollment`
    MODIFY COLUMN `source` ENUM('system','manual','location') NOT NULL DEFAULT 'system';
