-- 001_schema.sql — initial Rewards Foundry schema (2026-06-21)
--
-- 8 tables, all idempotent CREATE-IF-NOT-EXISTS per CLAUDE.md.
-- No transactions around DDL (MySQL implicitly commits CREATE/ALTER).
--
-- Naming: every table is `rewards_*` to match the REWARDS_* env-var
-- prefix and rewards_* PHP helper convention. Single source of
-- truth for grepping.
--
-- See ../README.md for table purposes and the carve-out plan at
-- 00 WM Development/docs/RewardsFoundry/rewards_foundry_carve_out_2026-06-21.md
-- for the full design rationale.


-- ─────────────────────────────────────────────────────────────────
-- rewards_consumer — API consumers (WBM is the first; future TGF /
-- GMI / partner integrations will land here too).
--
-- api_key_hash: sha256 of the plaintext API key. Plaintext is shown
-- ONCE on consumer creation in the admin UI; never retrievable after.
--
-- cors_origin: comma-separated allowlist (overrides the default set
-- in rewards_bootstrap.php for this specific consumer).
--
-- For WBM specifically the seed row at the end of this migration
-- carries cors_origin = 'https://smart-tools-foundry.com'.
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `rewards_consumer` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`          VARCHAR(160)    NOT NULL,
    `api_key_hash`  CHAR(64)        NOT NULL,
    `cors_origin`   VARCHAR(512)    NULL,
    `active`        TINYINT(1)      NOT NULL DEFAULT 1,
    `notes`         TEXT            NULL,
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_rewards_consumer_key`    (`api_key_hash`),
    UNIQUE KEY `uk_rewards_consumer_name`   (`name`),
    KEY        `idx_rewards_consumer_active`(`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─────────────────────────────────────────────────────────────────
-- rewards_admin_user — admin login accounts (bcrypt password).
--
-- password_hash: stored via PHP password_hash($pw, PASSWORD_DEFAULT).
-- Verified via password_verify(). Never store plaintext.
--
-- Marty is the first admin (seeded at the bottom of this file with a
-- placeholder hash — Marty resets it via the admin UI on first login,
-- or we re-seed here on Phase 0 setup with a known bcrypt).
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `rewards_admin_user` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email`          VARCHAR(255)    NOT NULL,
    `password_hash`  VARCHAR(255)    NOT NULL,
    `name`           VARCHAR(160)    NULL,
    `active`         TINYINT(1)      NOT NULL DEFAULT 1,
    `created_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_login_at`  DATETIME        NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_rewards_admin_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─────────────────────────────────────────────────────────────────
-- rewards_admin_session — admin session tokens (separate from
-- consumer API auth above).
--
-- token: 64-char random; cookie value is the plaintext; lookups are
-- via constant-time hash_equals on the row's token. Sessions expire
-- in 30 days (configurable per-issue) and are wiped on logout.
-- ip_hash: sha256(ip + REWARDS_SESSION_SECRET) — never raw IP.
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `rewards_admin_session` (
    `token`          CHAR(64)        NOT NULL,
    `admin_user_id`  BIGINT UNSIGNED NOT NULL,
    `created_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at`     DATETIME        NOT NULL,
    `ip_hash`        CHAR(64)        NULL,
    `user_agent`     VARCHAR(512)    NULL,
    PRIMARY KEY (`token`),
    KEY `idx_rewards_session_user`     (`admin_user_id`),
    KEY `idx_rewards_session_expires`  (`expires_at`),
    CONSTRAINT `fk_rewards_session_user`
        FOREIGN KEY (`admin_user_id`)
        REFERENCES `rewards_admin_user`(`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─────────────────────────────────────────────────────────────────
-- rewards_item — catalogue of redeemable reward items, one row per
-- item, scoped per consumer + per the consumer's own scope key
-- (sub_id for WBM, campaign_id for future TGF, etc.).
--
-- Direct port of WBM `reward_items` (migration 163) with two extra
-- columns:
--   - consumer_id  → which consumer created/owns this item
--   - theme_primary_hex → denormalised theme primary so the QR
--     compositor can paint the brand-coloured padding box behind the
--     centre logo without round-tripping to WBM (per carve-out
--     decision 5)
--
-- qr_token is the public surface — encoded in printed QRs, looked up
-- on every redemption. Once minted it NEVER changes (printed in the
-- field). Migration-import from WBM copies the existing tokens
-- verbatim so existing printed QRs keep resolving.
--
-- logo_url: optional per-item override. When NULL the consumer-level
-- default (TBD: lives elsewhere or passed in by the consumer at item
-- create) is used.
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `rewards_item` (
    `id`                          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `consumer_id`                 BIGINT UNSIGNED NOT NULL,
    `sub_id`                      VARCHAR(64)     NOT NULL,
    `name`                        VARCHAR(160)    NOT NULL,
    `location`                    VARCHAR(160)    NULL,
    `points_allocated`            INT             NOT NULL DEFAULT 0,
    `money_value_per_point`       DECIMAL(10,4)   NOT NULL DEFAULT 0.0000,
    `currency`                    CHAR(3)         NOT NULL DEFAULT 'AUD',
    `max_redemptions_per_person`  INT             NULL,
    `qr_token`                    VARCHAR(64)     NOT NULL,
    `theme_primary_hex`           CHAR(7)         NULL,
    `logo_url`                    VARCHAR(1024)   NULL,
    `is_active`                   TINYINT(1)      NOT NULL DEFAULT 1,
    `created_at`                  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by_email`            VARCHAR(255)    NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_rewards_item_token`    (`qr_token`),
    KEY `idx_rewards_item_consumer_sub`   (`consumer_id`, `sub_id`),
    KEY `idx_rewards_item_active`         (`is_active`),
    CONSTRAINT `fk_rewards_item_consumer`
        FOREIGN KEY (`consumer_id`)
        REFERENCES `rewards_consumer`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─────────────────────────────────────────────────────────────────
-- rewards_redemption — immutable audit log of every redemption.
--
-- Direct port of WBM `reward_redemptions` (migration 163) with an
-- extra consumer_id column so cross-consumer reporting works without
-- a JOIN.
--
-- redeemer_email OR redeemer_key carries the user's identity. Public
-- form validates "one of the two is non-empty" before insert.
--
-- ip_hash: sha256(ip + REWARDS_SESSION_SECRET) — never raw IP.
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `rewards_redemption` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `consumer_id`      BIGINT UNSIGNED NOT NULL,
    `rewards_item_id`  BIGINT UNSIGNED NOT NULL,
    `sub_id`           VARCHAR(64)     NOT NULL,
    `redeemer_email`   VARCHAR(255)    NULL,
    `redeemer_key`     VARCHAR(32)     NULL,
    `points_awarded`   INT             NULL,
    `money_value`      DECIMAL(10,4)   NULL,
    `currency`         CHAR(3)         NULL,
    `ip_hash`          CHAR(64)        NULL,
    `user_agent`       VARCHAR(512)    NULL,
    `redeemed_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_rewards_redemption_consumer` (`consumer_id`),
    KEY `idx_rewards_redemption_item`     (`rewards_item_id`),
    KEY `idx_rewards_redemption_sub`      (`sub_id`),
    KEY `idx_rewards_redemption_email`    (`redeemer_email`),
    KEY `idx_rewards_redemption_key`      (`redeemer_key`),
    KEY `idx_rewards_redemption_at`       (`redeemed_at`),
    CONSTRAINT `fk_rewards_redemption_consumer`
        FOREIGN KEY (`consumer_id`)
        REFERENCES `rewards_consumer`(`id`),
    CONSTRAINT `fk_rewards_redemption_item`
        FOREIGN KEY (`rewards_item_id`)
        REFERENCES `rewards_item`(`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─────────────────────────────────────────────────────────────────
-- rewards_redemption_notification — email-send log per redemption.
--
-- Today WBM stamps `notified_at` directly on the redemption row. In
-- the carve-out the notification gets its own row so we can record
-- delivery status + retry count + final outcome. Allows a chase-
-- failures cron to re-send the N rows whose status is 'failed'.
--
-- Notification provider is TBD per carve-out decision 8 (deferred);
-- Phase A skips email entirely. This table exists so the schema is
-- stable when we wire it.
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `rewards_redemption_notification` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `redemption_id`     BIGINT UNSIGNED NOT NULL,
    `recipient_email`   VARCHAR(255)    NOT NULL,
    `status`            ENUM('pending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
    `attempts`          INT             NOT NULL DEFAULT 0,
    `last_attempt_at`   DATETIME        NULL,
    `last_error`        VARCHAR(512)    NULL,
    `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_rewards_notif_redemption` (`redemption_id`),
    KEY `idx_rewards_notif_status`     (`status`, `last_attempt_at`),
    CONSTRAINT `fk_rewards_notif_redemption`
        FOREIGN KEY (`redemption_id`)
        REFERENCES `rewards_redemption`(`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─────────────────────────────────────────────────────────────────
-- rewards_audit — admin-action audit. Standard who-did-what for any
-- admin-UI write (item create / update / delete, consumer create
-- with the plaintext-key reveal, password change, etc.).
--
-- actor_admin_user_id: NULL when the action was system-initiated
-- (e.g. cron prune of expired sessions, future webhook from a
-- consumer).
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `rewards_audit` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `actor_admin_user_id` BIGINT UNSIGNED NULL,
    `action`              VARCHAR(80)     NOT NULL,
    `entity_type`         VARCHAR(40)     NULL,
    `entity_id`           VARCHAR(64)     NULL,
    `details`             JSON            NULL,
    `ip_hash`             CHAR(64)        NULL,
    `created_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_rewards_audit_actor` (`actor_admin_user_id`),
    KEY `idx_rewards_audit_at`    (`created_at`),
    KEY `idx_rewards_audit_action`(`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─────────────────────────────────────────────────────────────────
-- rewards_rate_limit — minimal IP-bucket counter for the
-- /v1/redeem endpoint. Per carve-out decision 7: rate-limit only for
-- Phase A; captcha later if abuse appears.
--
-- One row per (ip_hash, day-bucket). Each /v1/redeem POST increments
-- the count for the current day-bucket; the endpoint refuses (429) at
-- a configurable threshold (default 20 per day per IP).
--
-- Cleanup: a daily cron deletes rows where day_bucket < today - 7.
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `rewards_rate_limit` (
    `ip_hash`     CHAR(64)        NOT NULL,
    `day_bucket`  DATE            NOT NULL,
    `count`       INT             NOT NULL DEFAULT 0,
    `last_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`ip_hash`, `day_bucket`),
    KEY `idx_rewards_rate_bucket` (`day_bucket`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─────────────────────────────────────────────────────────────────
-- Seed: WBM as the first consumer (inactive until the real key is
-- paired). Admin user + consumer key get bootstrapped via the
-- separate `api/bootstrap_admin.php` + `api/bootstrap_consumer_key.php`
-- one-off scripts (gated on REWARDS_MIGRATE_TOKEN) rather than seeded
-- here — keeps real bcrypt hashes + plaintext API keys out of the
-- migration file (which is in git).
-- ─────────────────────────────────────────────────────────────────

INSERT IGNORE INTO `rewards_consumer`
    (`name`, `api_key_hash`, `cors_origin`, `active`, `notes`)
VALUES
    ('WBM-prod',
     -- Placeholder hash. Replace via bootstrap_consumer_key.php after
     -- Marty pastes the generated plaintext into WBM's wm_secrets.php
     -- as WM_REWARDS_FOUNDRY_KEY. Until then this consumer cannot
     -- authenticate (active=0 below is the actual gate; this hash
     -- just keeps the UNIQUE-key constraint satisfied).
     'pending_replace_at_phase_0_wrap_pending_replace_at_phase_0_wrap1',
     'https://smart-tools-foundry.com',
     0,
     'WBM bank super proxies through this consumer. Key lives in WBM wm_secrets.php as WM_REWARDS_FOUNDRY_KEY. Set active=1 after key pairing.');
