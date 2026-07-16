-- 012_redemption_manual_award.sql — manual award provenance + audit.
--
-- 2026-07-17 (Marty). The client is retiring the paper "Rewards Plus
-- Attendance Sheet" in favour of QR scanning, which leaves NO path to award a
-- person their points when a scan fails (bad phone, no signal, forgotten,
-- instructor-run class). Until now there was none anywhere in either repo —
-- the only route was direct DB access.
--
-- A Manual Award re-uses the existing reward definition (same item, same
-- points, same money) and simply records that a named admin awarded it to an
-- email, rather than the person having scanned the QR themselves.
--
-- Because a manual award is an admin creating money out of band — these points
-- become a real HSA credit downstream — WHO did it must be on the row itself,
-- not only in a side log that could drift or be pruned.
--
--   award_source     'QR'     = the participant scanned (the default path)
--                    'MANUAL' = an admin awarded it on their behalf
--   awarded_by_email the admin who did it. NULL for QR — nobody awards a scan.
--
-- Existing rows are all scans, so the DEFAULT 'QR' backfills them correctly.
-- ─────────────────────────────────────────────────────────────────

ALTER TABLE `rewards_redemption`
    ADD COLUMN `award_source` ENUM('QR','MANUAL') NOT NULL DEFAULT 'QR'
    COMMENT 'How the claim was made — QR scan by the participant, or a manual admin award';

ALTER TABLE `rewards_redemption`
    ADD COLUMN `awarded_by_email` VARCHAR(190) NULL
    COMMENT 'Admin who made a MANUAL award. NULL for QR scans.';

CREATE INDEX `idx_rewards_redemption_source`
    ON `rewards_redemption` (`consumer_id`, `sub_id`, `award_source`);
