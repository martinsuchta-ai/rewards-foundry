-- 014_rewards_enrollment.sql — first-class enrolment roster for rewards subs.
--
-- 2026-07-18 (Marty). Until now rewards held NO member record — identity was a
-- bare email/key string and "who can redeem" was decided purely by the WBM
-- membership hard-gate in /v1/redeem.php (must match a respondent on the linked
-- sub). Migration 010 flagged this as the thing to "revisit if the client ever
-- sends us enrolment data" — this is that revisit.
--
-- The roster lets admins SEE who's enrolled and MANAGE them:
--   * source  — how they got here:
--       'system' = auto-created the first time they transacted (redeem/earn)
--       'manual' = added by an admin via the Enrolments drawer
--   * status  — redemption is GATED on this (Marty 2026-07-18):
--       'active'     = may redeem
--       'suspended'  = temporarily blocked from redeeming (reversible)
--       'unenrolled' = removed from the programme (soft-delete, kept for audit)
--
-- A person's first-ever transaction auto-creates an ACTIVE system row, so the
-- first redemption is never blocked. An admin suspend/unenrol blocks subsequent
-- ones. Email is the identity key (manual enrol collects first/last/email).
-- System rows resolve the email from the WBM membership check even on key-only
-- redemptions. Email is stored already-lowercased by the app, so a plain
-- UNIQUE(sub_id,email) enforces one row per person per sub.
-- ─────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `rewards_enrollment` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `consumer_id`   BIGINT UNSIGNED NULL,
    `sub_id`        VARCHAR(40)     NOT NULL,
    `email`         VARCHAR(255)    NOT NULL COMMENT 'stored lowercased by the app',
    `first_name`    VARCHAR(128)    NULL,
    `last_name`     VARCHAR(128)    NULL,
    `source`        ENUM('system','manual')                 NOT NULL DEFAULT 'system',
    `status`        ENUM('active','suspended','unenrolled') NOT NULL DEFAULT 'active',
    `created_by`    VARCHAR(255)    NULL COMMENT 'admin email for manual rows, NULL for system/auto',
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_rewards_enrollment_sub_email` (`sub_id`, `email`),
    KEY `idx_rewards_enrollment_sub_status` (`sub_id`, `status`),
    KEY `idx_rewards_enrollment_sub_source` (`sub_id`, `source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
