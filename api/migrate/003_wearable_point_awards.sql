-- 003_wearable_point_awards.sql — append-only ledger for wearable
-- reward point awards.
--
-- 2026-06-26: Phase 2 of the Wellbeing Wearables Power-Up. WBM
-- evaluates reward rules after each successful wearable sync and
-- POSTs the award batch to api/v1/wearable_reward_credit.php on
-- this server. Each award lands as one row here.
--
-- Per-participant balance is derived as SUM(points) for that
-- email (Phase 2 MVP keeps it simple — no separate balance
-- column to drift out of sync with the award log; aggregation is
-- cheap thanks to the email + awarded_at index).
--
-- Idempotency at the application layer: WBM stamps the same
-- (email, source, rule_key, metric_date) only once per its
-- rewards_fired_json row. This table accepts duplicates silently
-- if WBM ever re-posts (the unique index on those four columns
-- below catches it).
--
-- Conventions:
--   - utf8mb4_unicode_ci to match the rest of the schema.
--   - InnoDB.

CREATE TABLE IF NOT EXISTS `rewards_point_award` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `participant_email` VARCHAR(255)    NOT NULL,
    `source`            VARCHAR(64)     NOT NULL,
    `rule_key`          VARCHAR(64)     NOT NULL,
    `points`            INT             NOT NULL,
    `reason`            TEXT            NULL,
    `metric_date`       DATE            NULL,
    `awarded_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_event` (`participant_email`, `source`, `rule_key`, `metric_date`),
    KEY        `idx_email_awarded` (`participant_email`, `awarded_at`),
    KEY        `idx_source`        (`source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
