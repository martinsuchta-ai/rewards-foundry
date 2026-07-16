-- 010_item_hsa_eligible.sql — HSA eligibility flag on reward items.
--
-- 2026-07-17 (Marty). Phase 1 of LiveWell Rewards is EARN + EXPORT: we credit
-- points, export them as CSV (email, first name, last name, points, amount),
-- and a third-party system applies the real $$ credit. We never spend, cap or
-- expire points.
--
-- HSA-eligible items are the ones whose points carry over to that external
-- system for a real credit into the participant's HSA. The client's rails:
--   HSA-enrolled     -> $2.50 / point  (into the LiveWell HSA)
--   non-HSA-enrolled -> $1.00 / point  (rail still unnamed by the client)
--
-- Why a per-ITEM flag rather than a per-PERSON one: the client's split is
-- ultimately about the person's HSA enrolment, but we hold no member record —
-- identity here is a bare email string. Flagging the ITEM lets the export
-- segment (All / HSA-Eligible / HSA-Non-Eligible) without inventing a member
-- table we would then have to keep in sync with their benefits system. Revisit
-- if the client ever sends us enrolment data.
--
-- Default 0: an item is only HSA-eligible when someone says so. The LiveWell
-- re-seed (011) sets it to 1 explicitly.
-- ─────────────────────────────────────────────────────────────────

ALTER TABLE `rewards_item`
    ADD COLUMN `hsa_eligible` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Points from this item carry to the external system for a real HSA credit';

CREATE INDEX `idx_rewards_item_hsa` ON `rewards_item` (`consumer_id`, `sub_id`, `hsa_eligible`);
