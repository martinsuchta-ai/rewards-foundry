-- 004_behaviour_catalogue.sql — Schools Pilot + variant-ready
--                                behaviour-recognition foundation.
--
-- 2026-06-26. Three coordinated schema changes:
--
--   1) CREATE rewards_behaviour_catalogue — per-edition config
--      (label dictionary, default point bands, trusted role,
--      terminology, self-scan policy, theme primary). One row per
--      (consumer_id, sub_id). Future variants (WPA Workplace, WPS
--      Sport, WAW Aging Well) get their own catalogue rows with
--      different scope/dimension labels — no schema churn.
--
--   2) CREATE rewards_behaviour_activity — one row per scannable
--      behaviour. Mirrors rewards_item.qr_token scheme so the
--      existing QR + redeem infrastructure picks them up.
--
--   3) ALTER rewards_point_award — five new columns so a single
--      ledger absorbs trusted-person / self-scan / PENDING flows
--      alongside the existing wearables AUTO/CONFIRMED writes.
--      Gated via information_schema probe per the standing
--      "no ADD COLUMN IF NOT EXISTS on SG MySQL 8" rule.
--
-- Idempotent: CREATE-IF-NOT-EXISTS for the two new tables; gated
-- ALTERs for every column / index / FK addition. Safe to re-run on
-- a clean or partially-applied state.
--
-- See docs/rewards-foundry-variant-ready-architecture.md for the
-- full design rationale + the 5 design decisions Marty locked in
-- on 2026-06-26.


-- ─────────────────────────────────────────────────────────────────
-- 1) rewards_behaviour_catalogue
--
-- One row per (consumer_id, sub_id). Carries the LABELS + DEFAULTS
-- for one edition's behaviour vocabulary. Activities reference
-- back via catalogue_id so any rendered surface looks up its
-- edition-correct label from this row at render time.
--
-- self_scan_policy:
--   'allow'        — per-activity self_scan_enabled flag wins
--   'never'        — disables self-scan for every activity in the catalogue
--   'spin_up_only' — Spin Up self-scannable, Spin Down trusted-person-only
--                    regardless of per-activity flag. DEFAULT.
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `rewards_behaviour_catalogue` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `consumer_id`         BIGINT UNSIGNED NOT NULL,
    `sub_id`              VARCHAR(64)     NOT NULL,
    `edition_key`         VARCHAR(16)     NOT NULL,
    `scope_labels`        JSON            NOT NULL,
    `dimension_labels`    JSON            NOT NULL,
    `default_point_bands` JSON            NOT NULL,
    `trusted_role_label`  VARCHAR(40)     NOT NULL,
    `terminology`         JSON            NULL,
    `self_scan_policy`    ENUM('allow','never','spin_up_only') NOT NULL DEFAULT 'spin_up_only',
    `theme_primary_hex`   CHAR(7)         NULL,
    `active`              TINYINT(1)      NOT NULL DEFAULT 1,
    `created_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_catalogue_consumer_sub` (`consumer_id`, `sub_id`),
    KEY `idx_catalogue_edition` (`edition_key`),
    KEY `idx_catalogue_active`  (`active`),
    CONSTRAINT `fk_catalogue_consumer`
        FOREIGN KEY (`consumer_id`)
        REFERENCES `rewards_consumer`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─────────────────────────────────────────────────────────────────
-- 2) rewards_behaviour_activity
--
-- One row per scannable behaviour. Pilot expectation: 122 rows
-- per catalogue (6 scope/direction sets × 12 PERMA-H + 25 generic
-- up + 25 generic down). Seeded by api/migrate/import_school_behaviours.php
-- from a JSON library (see docs/schools_behaviour_seed_wpk.json).
--
-- qr_token mirrors rewards_item.qr_token verbatim — once minted,
-- NEVER changes (cards are printed in the wild). The /redeem
-- endpoint resolves the token against EITHER rewards_item OR
-- rewards_behaviour_activity (see api/v1/redeem.php enhancement
-- in Phase C).
--
-- points is the MAGNITUDE; sign comes from direction at
-- rewards_point_award INSERT time.
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `rewards_behaviour_activity` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `consumer_id`       BIGINT UNSIGNED NOT NULL,
    `sub_id`            VARCHAR(64)     NOT NULL,
    `catalogue_id`      BIGINT UNSIGNED NOT NULL,
    `qr_token`          VARCHAR(64)     NOT NULL,
    `scope`             ENUM('ME','WE','US','GENERIC') NOT NULL,
    `direction`         ENUM('UP','DOWN') NOT NULL,
    `dimension_key`     VARCHAR(40)     NULL,
    `title`             VARCHAR(255)    NOT NULL,
    `points`            INT             NOT NULL,
    `self_scan_enabled` TINYINT(1)      NOT NULL DEFAULT 0,
    `theme_primary_hex` CHAR(7)         NULL,
    `active`            TINYINT(1)      NOT NULL DEFAULT 1,
    `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by_email`  VARCHAR(255)    NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_activity_token`        (`qr_token`),
    KEY `idx_activity_consumer_sub`       (`consumer_id`, `sub_id`),
    KEY `idx_activity_catalogue`          (`catalogue_id`),
    KEY `idx_activity_scope_dir`          (`scope`, `direction`),
    KEY `idx_activity_dimension`          (`dimension_key`),
    KEY `idx_activity_active`             (`active`),
    CONSTRAINT `fk_activity_consumer`
        FOREIGN KEY (`consumer_id`)
        REFERENCES `rewards_consumer`(`id`),
    CONSTRAINT `fk_activity_catalogue`
        FOREIGN KEY (`catalogue_id`)
        REFERENCES `rewards_behaviour_catalogue`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─────────────────────────────────────────────────────────────────
-- 3) Extend rewards_point_award — 5 new columns + 4 indexes + 1 FK.
--
-- The wearables flow (which writes to this table today) stays
-- unaffected: every new column defaults to a value matching the
-- AUTO/CONFIRMED semantics it already uses.
--
-- Each ALTER gated via information_schema probe — pattern lifted
-- from WBM api/migrate/008_user_names.sql.
-- ─────────────────────────────────────────────────────────────────

SET @has_awarded_by_email := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'rewards_point_award'
    AND COLUMN_NAME  = 'awarded_by_email'
);
SET @sql := IF(@has_awarded_by_email = 0,
  'ALTER TABLE `rewards_point_award` ADD COLUMN `awarded_by_email` VARCHAR(255) NULL AFTER `participant_email`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_participant_key := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'rewards_point_award'
    AND COLUMN_NAME  = 'participant_key'
);
SET @sql := IF(@has_participant_key = 0,
  'ALTER TABLE `rewards_point_award` ADD COLUMN `participant_key` VARCHAR(32) NULL AFTER `awarded_by_email`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_source_type := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'rewards_point_award'
    AND COLUMN_NAME  = 'source_type'
);
SET @sql := IF(@has_source_type = 0,
  'ALTER TABLE `rewards_point_award` ADD COLUMN `source_type` ENUM(\'AUTO\',\'SELF_SCAN\',\'TRUSTED_PERSON\') NOT NULL DEFAULT \'AUTO\' AFTER `source`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_status := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'rewards_point_award'
    AND COLUMN_NAME  = 'status'
);
SET @sql := IF(@has_status = 0,
  'ALTER TABLE `rewards_point_award` ADD COLUMN `status` ENUM(\'CONFIRMED\',\'PENDING\',\'REJECTED\') NOT NULL DEFAULT \'CONFIRMED\' AFTER `source_type`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_behaviour_activity_id := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'rewards_point_award'
    AND COLUMN_NAME  = 'behaviour_activity_id'
);
SET @sql := IF(@has_behaviour_activity_id = 0,
  'ALTER TABLE `rewards_point_award` ADD COLUMN `behaviour_activity_id` BIGINT UNSIGNED NULL AFTER `rule_key`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- Indexes for the new query patterns. Each gated via information_schema.STATISTICS.

SET @has_idx_status := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'rewards_point_award'
    AND INDEX_NAME   = 'idx_award_status'
);
SET @sql := IF(@has_idx_status = 0,
  'ALTER TABLE `rewards_point_award` ADD KEY `idx_award_status` (`status`, `awarded_at`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_idx_email_status := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'rewards_point_award'
    AND INDEX_NAME   = 'idx_award_email_status'
);
SET @sql := IF(@has_idx_email_status = 0,
  'ALTER TABLE `rewards_point_award` ADD KEY `idx_award_email_status` (`participant_email`, `status`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_idx_awarded_by := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'rewards_point_award'
    AND INDEX_NAME   = 'idx_award_awarded_by'
);
SET @sql := IF(@has_idx_awarded_by = 0,
  'ALTER TABLE `rewards_point_award` ADD KEY `idx_award_awarded_by` (`awarded_by_email`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_idx_behaviour := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'rewards_point_award'
    AND INDEX_NAME   = 'idx_award_behaviour_activity'
);
SET @sql := IF(@has_idx_behaviour = 0,
  'ALTER TABLE `rewards_point_award` ADD KEY `idx_award_behaviour_activity` (`behaviour_activity_id`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- FK to behaviour activity. ON DELETE SET NULL so deleting an activity
-- doesn't blow away historical award rows — they retain the points
-- but lose the activity reference. Gated via REFERENTIAL_CONSTRAINTS.

SET @has_fk_behaviour := (
  SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME        = 'rewards_point_award'
    AND CONSTRAINT_NAME   = 'fk_award_behaviour_activity'
);
SET @sql := IF(@has_fk_behaviour = 0,
  'ALTER TABLE `rewards_point_award` ADD CONSTRAINT `fk_award_behaviour_activity` FOREIGN KEY (`behaviour_activity_id`) REFERENCES `rewards_behaviour_activity`(`id`) ON DELETE SET NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ─────────────────────────────────────────────────────────────────
-- 4) Seed the two pilot catalogue rows.
--
--   Kids pilot:     SUB-MOVWPL0B (WPK)
--   Students pilot: SUB-MOVXLAHI (WPY)
--
-- theme_primary_hex left NULL here — the importer
-- (api/migrate/import_school_behaviours.php) reads each sub's
-- WBM theme config at activity-seed time and stamps it onto
-- this catalogue row + every behaviour_activity row created.
-- ─────────────────────────────────────────────────────────────────

INSERT IGNORE INTO `rewards_behaviour_catalogue`
    (`consumer_id`, `sub_id`, `edition_key`, `scope_labels`, `dimension_labels`,
     `default_point_bands`, `trusted_role_label`, `terminology`, `self_scan_policy`)
VALUES (
    (SELECT `id` FROM `rewards_consumer` WHERE `name` = 'WBM-prod' LIMIT 1),
    'SUB-MOVWPL0B',
    'WPK',
    JSON_OBJECT('ME','ME','WE','WE','US','US'),
    JSON_OBJECT(
        'POSITIVE_EMOTIONS','Positive Emotions',
        'ENGAGEMENT','Engagement',
        'RELATIONSHIPS','Relationships',
        'MEANING','Meaning',
        'ACCOMPLISHMENT','Accomplishment',
        'HEALTH','Health'
    ),
    JSON_OBJECT('ME',5,'WE',10,'US',20,'GENERIC',5),
    'Teacher',
    JSON_OBJECT('spin_up','Spin Up','spin_down','Spin Down'),
    'spin_up_only'
);

INSERT IGNORE INTO `rewards_behaviour_catalogue`
    (`consumer_id`, `sub_id`, `edition_key`, `scope_labels`, `dimension_labels`,
     `default_point_bands`, `trusted_role_label`, `terminology`, `self_scan_policy`)
VALUES (
    (SELECT `id` FROM `rewards_consumer` WHERE `name` = 'WBM-prod' LIMIT 1),
    'SUB-MOVXLAHI',
    'WPY',
    JSON_OBJECT('ME','ME','WE','WE','US','US'),
    JSON_OBJECT(
        'POSITIVE_EMOTIONS','Positive Emotions',
        'ENGAGEMENT','Engagement',
        'RELATIONSHIPS','Relationships',
        'MEANING','Meaning',
        'ACCOMPLISHMENT','Accomplishment',
        'HEALTH','Health'
    ),
    JSON_OBJECT('ME',5,'WE',10,'US',20,'GENERIC',5),
    'Teacher',
    JSON_OBJECT('spin_up','Spin Up','spin_down','Spin Down'),
    'spin_up_only'
);
