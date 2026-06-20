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
-- IDEMPOTENCE NOTE — first version used `ADD COLUMN IF NOT EXISTS`
-- which is MariaDB-only; MySQL 8 rejects it with a syntax error.
-- Rewritten 2026-06-21 to use the standard MySQL 8 pattern:
-- gate each ADD via dynamic SQL keyed on INFORMATION_SCHEMA so
-- re-running this migration on a state where the column or index
-- already exists is a clean no-op.

-- ── rewards_item.legacy_wbm_id column ───────────────────────────
SET @sql := IF(
  EXISTS(
    SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'rewards_item'
       AND COLUMN_NAME  = 'legacy_wbm_id'
  ),
  'SELECT 1 AS _noop',
  'ALTER TABLE `rewards_item` ADD COLUMN `legacy_wbm_id` BIGINT UNSIGNED NULL AFTER `created_by_email`'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── rewards_item UNIQUE index on legacy_wbm_id ─────────────────
SET @sql := IF(
  EXISTS(
    SELECT 1 FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'rewards_item'
       AND INDEX_NAME   = 'uk_rewards_item_legacy_wbm'
  ),
  'SELECT 1 AS _noop',
  'ALTER TABLE `rewards_item` ADD UNIQUE INDEX `uk_rewards_item_legacy_wbm` (`legacy_wbm_id`)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── rewards_redemption.legacy_wbm_id column ────────────────────
SET @sql := IF(
  EXISTS(
    SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'rewards_redemption'
       AND COLUMN_NAME  = 'legacy_wbm_id'
  ),
  'SELECT 1 AS _noop',
  'ALTER TABLE `rewards_redemption` ADD COLUMN `legacy_wbm_id` BIGINT UNSIGNED NULL AFTER `user_agent`'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── rewards_redemption UNIQUE index on legacy_wbm_id ───────────
SET @sql := IF(
  EXISTS(
    SELECT 1 FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'rewards_redemption'
       AND INDEX_NAME   = 'uk_rewards_redemption_legacy_wbm'
  ),
  'SELECT 1 AS _noop',
  'ALTER TABLE `rewards_redemption` ADD UNIQUE INDEX `uk_rewards_redemption_legacy_wbm` (`legacy_wbm_id`)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
