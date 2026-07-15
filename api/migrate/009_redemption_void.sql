-- 009_redemption_void.sql — void / un-void redemptions (2026-07-16)
--
-- rewards_redemption was designed as an immutable append-only audit log
-- (001_schema.sql). Marty needs the bank to VOID a redemption (e.g. a
-- mistaken or fraudulent claim) with a recorded reason, and to UN-VOID an
-- accidental void. Rather than delete rows (which would break the audit
-- trail + legacy dedup), we add a soft-void flag + reason + who/when.
--
-- Voided rows are EXCLUDED from the default list, the summary KPIs, and the
-- per-item redemption_count; the bank exposes a "Show voided" filter and an
-- Un-void action. The void event itself is also recorded in rewards_audit.
--
-- MySQL 8 rejects `ADD COLUMN IF NOT EXISTS` (MariaDB-only) — gate each ADD
-- via INFORMATION_SCHEMA dynamic SQL so re-runs are clean no-ops (same
-- pattern as 006/008).

-- voided flag
SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rewards_redemption' AND COLUMN_NAME = 'voided'),
  'SELECT 1 AS _noop',
  'ALTER TABLE `rewards_redemption` ADD COLUMN `voided` TINYINT(1) NOT NULL DEFAULT 0 AFTER `legacy_wbm_id`'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- void reason (free text supplied by the voider)
SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rewards_redemption' AND COLUMN_NAME = 'void_reason'),
  'SELECT 1 AS _noop',
  'ALTER TABLE `rewards_redemption` ADD COLUMN `void_reason` VARCHAR(500) NULL AFTER `voided`'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- when voided
SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rewards_redemption' AND COLUMN_NAME = 'voided_at'),
  'SELECT 1 AS _noop',
  'ALTER TABLE `rewards_redemption` ADD COLUMN `voided_at` DATETIME NULL AFTER `void_reason`'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- who voided (WM-side bank admin email, or 'bank-admin' fallback)
SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rewards_redemption' AND COLUMN_NAME = 'voided_by'),
  'SELECT 1 AS _noop',
  'ALTER TABLE `rewards_redemption` ADD COLUMN `voided_by` VARCHAR(255) NULL AFTER `voided_at`'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- index so "exclude voided" default filter stays cheap on big subs
SET @sql := IF(
  EXISTS(SELECT 1 FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rewards_redemption' AND INDEX_NAME = 'idx_rewards_redemption_voided'),
  'SELECT 1 AS _noop',
  'ALTER TABLE `rewards_redemption` ADD INDEX `idx_rewards_redemption_voided` (`voided`)'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
