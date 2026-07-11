-- 005_seed_livewell_rewards_MQ4KQLLF.sql — LiveWell "Rewards Plus" offerings.
--
-- Seeds the claimable reward items for SUB-MQ4KQLLF (EVAL-MQ4KQLLF, WORK IN
-- PROG) under the WBM-prod consumer, transcribed from
-- "Rewards Offerings 2026.docx" (docs/LiveWell in the WBM repo).
--
-- Points model (from the doc): each point earned = $5.00 in the participant's
-- LiveWell HSA (or $2.50 in the Café for non-HSA-enrolled staff); the headline
-- $5.00/point value is stored in money_value_per_point. Max 400 pts/quarter is
-- a program-level cap enforced outside the item catalogue, not per-item.
-- Currency USD (LiveWell is US-based). "per 15 min" / "per week" cadences are
-- carried in the item name — the catalogue stores a single points_allocated
-- base unit per claim.
--
-- Idempotent: qr_token = MD5(stable slug) + INSERT IGNORE on the UNIQUE token,
-- so re-running is a no-op and never disturbs later admin-UI edits. Depends on
-- the WBM-prod consumer seeded in 001_schema.sql.
-- ─────────────────────────────────────────────────────────────────

SET @cid := (SELECT `id` FROM `rewards_consumer` WHERE `name` = 'WBM-prod' LIMIT 1);

INSERT IGNORE INTO `rewards_item`
    (`consumer_id`, `sub_id`, `name`, `location`, `points_allocated`,
     `money_value_per_point`, `currency`, `qr_token`, `is_active`, `created_by_email`)
VALUES
    (@cid, 'SUB-MQ4KQLLF', 'Morning Yoga',                                   'Movement Studio',  4, 5.0000, 'USD', MD5('rf-MQ4KQLLF-morning-yoga'),        1, 'marty@the-good-foundry.com'),
    (@cid, 'SUB-MQ4KQLLF', 'Weight Management Program',                      'Fitness Studio',   1, 5.0000, 'USD', MD5('rf-MQ4KQLLF-weight-management'),   1, 'marty@the-good-foundry.com'),
    (@cid, 'SUB-MQ4KQLLF', 'Open Gym (1 pt / 15 min)',                       'Fitness Studio',   1, 5.0000, 'USD', MD5('rf-MQ4KQLLF-open-gym'),            1, 'marty@the-good-foundry.com'),
    (@cid, 'SUB-MQ4KQLLF', 'Open Yoga / Mindfulness Studio (1 pt / 15 min)', 'Movement Studio',  1, 5.0000, 'USD', MD5('rf-MQ4KQLLF-open-yoga'),           1, 'marty@the-good-foundry.com'),
    (@cid, 'SUB-MQ4KQLLF', 'Wellbeing Coaching (1 pt / 15 min)',             'By Appointment',   1, 5.0000, 'USD', MD5('rf-MQ4KQLLF-wellbeing-coaching'),  1, 'marty@the-good-foundry.com'),
    (@cid, 'SUB-MQ4KQLLF', 'HIINT Class',                                    'Fitness Studio',   2, 5.0000, 'USD', MD5('rf-MQ4KQLLF-hiint'),               1, 'marty@the-good-foundry.com'),
    (@cid, 'SUB-MQ4KQLLF', 'Personal Training (30 min, Mon-Fri)',            'Fitness Studio',   2, 5.0000, 'USD', MD5('rf-MQ4KQLLF-personal-training'),   1, 'marty@the-good-foundry.com'),
    (@cid, 'SUB-MQ4KQLLF', 'Hydromassage Beds (1 pt / week, use 3+ times)',  'Reflections Room', 1, 5.0000, 'USD', MD5('rf-MQ4KQLLF-hydromassage'),        1, 'marty@the-good-foundry.com'),
    (@cid, 'SUB-MQ4KQLLF', 'Smart Start Session (intro)',                    'By Appointment',   0, 5.0000, 'USD', MD5('rf-MQ4KQLLF-smart-start'),         1, 'marty@the-good-foundry.com');
