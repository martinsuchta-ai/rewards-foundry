-- 007_item_enforce_account.sql — per-item "enforce account" flag (2026-07-13)
--
-- When the WBM sub/client has Require Credentials ("enforce account") on,
-- redemptions must use an EMAIL — the "WBM key" path is hidden on the
-- redemption page and rejected server-side. Denormalised onto the item
-- (like theme_primary_hex / logo_url per the carve-out), stamped by the
-- WBM proxy from subscriptions.require_credentials on every item
-- create/update. Default 0 = key redemption allowed (current behaviour).
--
-- INFORMATION_SCHEMA-gated (MySQL 8 has no ADD COLUMN IF NOT EXISTS).

SET @sql := IF(
  EXISTS(
    SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'rewards_item'
       AND COLUMN_NAME  = 'enforce_account'
  ),
  'SELECT 1 AS _noop',
  'ALTER TABLE `rewards_item` ADD COLUMN `enforce_account` TINYINT(1) NOT NULL DEFAULT 0 AFTER `redeem_image_url`'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
