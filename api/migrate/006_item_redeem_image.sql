-- 006_item_redeem_image.sql — dedicated redemption-page image (2026-07-13)
--
-- Reward items already carry `logo_url` (the QR-page image — composited
-- into the QR PNG by api/v1/qr.php as the watermark/centre logo). Marty
-- wants a SECOND, distinct image for the public redemption page
-- (public/redeem.html) plus upload-backed storage for both (see
-- api/v1/upload.php). This adds the redemption-page image column; the
-- redeem page falls back to `logo_url` when it's NULL, so existing items
-- keep their current look until an admin sets a redemption image.
--
-- MySQL 8 rejects `ADD COLUMN IF NOT EXISTS` (MariaDB-only) — gate the
-- ADD via INFORMATION_SCHEMA dynamic SQL so re-runs are clean no-ops
-- (same pattern as 002_legacy_wbm_id.sql).

SET @sql := IF(
  EXISTS(
    SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'rewards_item'
       AND COLUMN_NAME  = 'redeem_image_url'
  ),
  'SELECT 1 AS _noop',
  'ALTER TABLE `rewards_item` ADD COLUMN `redeem_image_url` VARCHAR(1024) NULL AFTER `logo_url`'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
