-- 002_legacy_wbm_id.sql — add legacy WBM row-id columns (2026-06-21)
--
-- Used exclusively by the Phase C one-shot import from WBM
-- (api/v1/import_bulk.php on this side, called by
-- 00 WM Development/api/admin/export_to_rewards_foundry.php on
-- the WBM side).
--
-- Why a separate UNIQUE column instead of dedup'ing on qr_token /
-- composite key:
--   - reward_items dedups well on qr_token (UNIQUE on both sides),
--     but adding legacy_wbm_id alongside makes re-imports
--     unambiguous + lets us audit which rows came from where.
--   - reward_redemptions has NO natural unique key — same item +
--     same email + same minute = ambiguous. Pinning on the WBM
--     row's auto-increment id is the only clean dedup signal.
--
-- After Phase F (WBM cleanup) the column becomes purely historical
-- audit data. Not dropped — small + useful for "where did this row
-- come from" questions years later.

ALTER TABLE `rewards_item`
    ADD COLUMN IF NOT EXISTS `legacy_wbm_id` BIGINT UNSIGNED NULL AFTER `created_by_email`,
    ADD UNIQUE INDEX IF NOT EXISTS `uk_rewards_item_legacy_wbm`        (`legacy_wbm_id`);

ALTER TABLE `rewards_redemption`
    ADD COLUMN IF NOT EXISTS `legacy_wbm_id` BIGINT UNSIGNED NULL AFTER `user_agent`,
    ADD UNIQUE INDEX IF NOT EXISTS `uk_rewards_redemption_legacy_wbm`  (`legacy_wbm_id`);
