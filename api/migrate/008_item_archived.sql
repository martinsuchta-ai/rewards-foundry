-- 008_item_archived.sql — archive / unarchive reward items (2026-07-14)
--
-- Marty wants to ARCHIVE older rewards to declutter the main list, kept
-- distinct from disable (is_active). Archived items are hidden from the
-- default list (and from the public QR/redeem paths via is_active), but
-- can be UNARCHIVED. This adds the `archived` flag; the bank Rewards
-- client (api/wm_bank_rewards_client.js) hides archived by default and
-- exposes a "Show archived" toggle + Archive/Unarchive per card.
--
-- MySQL 8 rejects `ADD COLUMN IF NOT EXISTS` (MariaDB-only) — gate the
-- ADD via INFORMATION_SCHEMA dynamic SQL so re-runs are clean no-ops
-- (same pattern as 006_item_redeem_image.sql).

SET @sql := IF(
  EXISTS(
    SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'rewards_item'
       AND COLUMN_NAME  = 'archived'
  ),
  'SELECT 1 AS _noop',
  'ALTER TABLE `rewards_item` ADD COLUMN `archived` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_active`'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
