-- 011_reseed_livewell_rewards_7_15.sql — LiveWell "Rewards Plus"™ re-seed.
--
-- 2026-07-17 (Marty: "flush all of the ones we have and re-import/align
-- entirely to what they have provided").
--
-- WHY: seed 005 was transcribed from "Rewards Offerings 2026.docx", which the
-- client's "LiveWell Rewards Playbook 7 15.docx" (Heidi Gil, 2026-07-15) has
-- SUPERSEDED. The Playbook silently re-prices and re-scopes the program:
--
--   HSA rate            $5.00/pt      ->  $2.50/pt     (halved)
--   Non-HSA rate        $2.50/pt Cafe ->  $1.00/pt     (rail now unnamed)
--   Cap                 $400/qtr (80pts) -> $400/qtr (160 pts)
--   Tracking            paper sheet   ->  QR scan
--   Weight Management   1 pt          ->  2 pts
--   Hydromassage        1/wk if used 3+ times -> 1/wk (condition dropped)
--   Wellbeing Coaching  1 pt / 15 min ->  only as Smart Start 2 pts / 30 min
--   Open Yoga/Mindfulness Studio      ->  DROPPED (absent from the Playbook)
--   Assessment          n/a           ->  10 pts (new)
--   "HIINT"             ->  "HINT"    (their spelling changed)
--   Just Do It!         n/a           ->  new rail, 12 activities, 1 pt/15 min
--
-- money_value_per_point stores the HSA rate ($2.50). The $1.00 non-HSA rail is
-- NOT a second item — it is the same activity converted at a different rate for
-- a person who is not HSA-enrolled. We hold no enrolment data, so the export
-- segments on hsa_eligible and the external system applies the correct rate.
--
-- NOT seeded here: the two wearable rules (10k steps -> 1pt/day; 6-8h sleep ->
-- 1pt/night). Those are device-driven, not QR-claimable, and belong to the
-- Wearables-Foundry management area (Phase 2). The wearable rules currently
-- hard-coded in WBM contradict the client (8k steps -> +5, sleep score -> +10,
-- 7-day streak -> +20) and are re-pointed there, not here.
--
-- INCOMPLETE BY THE CLIENT'S OWN ADMISSION: their weekly list says "A few
-- examples include:" — the full activity catalogue does not exist in any
-- document. These 22 items are every activity they have actually named.
-- Marty 2026-07-17: seed the examples now, extend when they send the rest.
--
-- FLUSH STRATEGY — archive, do not DELETE. rewards_redemption.rewards_item_id
-- points at these rows; hard-deleting would orphan any claim already made and
-- destroy its audit trail. Setting is_active=0 removes them from the catalogue
-- AND makes redeem.php reject their QR codes (:252), and archived=1 hides them
-- from the admin list by default. Same user-visible outcome, no history loss.
-- A true hard-delete is coming as a bank super-admin action.
--
-- Idempotent: qr_token = MD5(stable slug) + INSERT IGNORE, so re-running is a
-- no-op and never disturbs later admin-UI edits. Depends on 010 (hsa_eligible).
-- ─────────────────────────────────────────────────────────────────

SET @cid := (SELECT `id` FROM `rewards_consumer` WHERE `name` = 'WBM-prod' LIMIT 1);

-- ── 1. Retire everything seeded from the superseded Offerings doc ────────
UPDATE `rewards_item`
   SET `is_active` = 0,
       `archived`  = 1,
       `updated_at` = UTC_TIMESTAMP()
 WHERE `consumer_id` = @cid
   AND `sub_id` = 'SUB-MQ4KQLLF'
   AND `qr_token` IN (
        MD5('rf-MQ4KQLLF-morning-yoga'),
        MD5('rf-MQ4KQLLF-weight-management'),
        MD5('rf-MQ4KQLLF-open-gym'),
        MD5('rf-MQ4KQLLF-open-yoga'),
        MD5('rf-MQ4KQLLF-wellbeing-coaching'),
        MD5('rf-MQ4KQLLF-hiint'),
        MD5('rf-MQ4KQLLF-personal-training'),
        MD5('rf-MQ4KQLLF-hydromassage'),
        MD5('rf-MQ4KQLLF-smart-start')
   );

-- ── 2. Seed the 7/15 Playbook schedule ──────────────────────────────────
-- All HSA-eligible (hsa_eligible=1) per Marty: the client's items all carry
-- to the external system for a real credit. Currency USD (LiveWell is US).
-- Cadence ("per 15 min", "per class") is carried in the NAME — the catalogue
-- stores one points_allocated per claim, and the cap is enforced off-platform.

INSERT IGNORE INTO `rewards_item`
    (`consumer_id`, `sub_id`, `name`, `location`, `points_allocated`,
     `money_value_per_point`, `currency`, `qr_token`, `is_active`,
     `hsa_eligible`, `created_by_email`)
VALUES
    -- Step 1 — Discover
    (@cid, 'SUB-MQ4KQLLF', 'Wellbeing & Brain Vitality Assessment',            'Online',                     10, 2.5000, 'USD', MD5('rf-MQ4KQLLF-v2-assessment'),       1, 1, 'marty@the-good-foundry.com'),

    -- Step 2 — Smart Starts (2 pts / 30-min session)
    (@cid, 'SUB-MQ4KQLLF', 'Smart Start — Wellbeing Coaching (30 min)',        'By Appointment',              2, 2.5000, 'USD', MD5('rf-MQ4KQLLF-v2-ss-coaching'),      1, 1, 'marty@the-good-foundry.com'),
    (@cid, 'SUB-MQ4KQLLF', 'Smart Start — Fitness (30 min)',                   'Fitness & Movement Studio',   2, 2.5000, 'USD', MD5('rf-MQ4KQLLF-v2-ss-fitness'),       1, 1, 'marty@the-good-foundry.com'),
    (@cid, 'SUB-MQ4KQLLF', 'Smart Start — Yoga & Mindfulness (30 min)',        'Fitness & Movement Studio',   2, 2.5000, 'USD', MD5('rf-MQ4KQLLF-v2-ss-yoga'),          1, 1, 'marty@the-good-foundry.com'),

    -- Weekly experiences
    (@cid, 'SUB-MQ4KQLLF', 'Open Gym (1 pt / 15 min)',                         'Fitness & Movement Studio',   1, 2.5000, 'USD', MD5('rf-MQ4KQLLF-v2-open-gym'),         1, 1, 'marty@the-good-foundry.com'),
    (@cid, 'SUB-MQ4KQLLF', 'Morning Yoga (Thu 7:30-8:30am)',                   'Fitness & Movement Studio',   4, 2.5000, 'USD', MD5('rf-MQ4KQLLF-v2-morning-yoga'),     1, 1, 'marty@the-good-foundry.com'),
    (@cid, 'SUB-MQ4KQLLF', 'Weight Management Program (per session)',          'Fitness & Movement Studio',   2, 2.5000, 'USD', MD5('rf-MQ4KQLLF-v2-weight-mgmt'),      1, 1, 'marty@the-good-foundry.com'),
    (@cid, 'SUB-MQ4KQLLF', 'HINT Training (Mon 4:30-5:00pm)',                  'Fitness & Movement Studio',   2, 2.5000, 'USD', MD5('rf-MQ4KQLLF-v2-hint'),             1, 1, 'marty@the-good-foundry.com'),
    (@cid, 'SUB-MQ4KQLLF', 'Personal Training (30 min, Mon-Fri)',              'Fitness & Movement Studio',   2, 2.5000, 'USD', MD5('rf-MQ4KQLLF-v2-personal-training'),1, 1, 'marty@the-good-foundry.com'),
    (@cid, 'SUB-MQ4KQLLF', 'Hydromassage Recovery (1 pt / week)',              'Reflections Room',            1, 2.5000, 'USD', MD5('rf-MQ4KQLLF-v2-hydromassage'),     1, 1, 'marty@the-good-foundry.com'),

    -- "Just Do It!" — all 1 pt / 15 min, Fitness & Movement Studio
    (@cid, 'SUB-MQ4KQLLF', 'Just Do It! — Movement Circuits (1 pt / 15 min)',            'Fitness & Movement Studio', 1, 2.5000, 'USD', MD5('rf-MQ4KQLLF-v2-jdi-movement-circuits'), 1, 1, 'marty@the-good-foundry.com'),
    (@cid, 'SUB-MQ4KQLLF', 'Just Do It! — Cardio Boost (1 pt / 15 min)',                 'Fitness & Movement Studio', 1, 2.5000, 'USD', MD5('rf-MQ4KQLLF-v2-jdi-cardio-boost'),      1, 1, 'marty@the-good-foundry.com'),
    (@cid, 'SUB-MQ4KQLLF', 'Just Do It! — Balance & Brain Training (1 pt / 15 min)',     'Fitness & Movement Studio', 1, 2.5000, 'USD', MD5('rf-MQ4KQLLF-v2-jdi-balance-brain'),     1, 1, 'marty@the-good-foundry.com'),
    (@cid, 'SUB-MQ4KQLLF', 'Just Do It! — Mindful Moments (1 pt / 15 min)',              'Fitness & Movement Studio', 1, 2.5000, 'USD', MD5('rf-MQ4KQLLF-v2-jdi-mindful-moments'),   1, 1, 'marty@the-good-foundry.com'),
    (@cid, 'SUB-MQ4KQLLF', 'Just Do It! — Guided Meditation (1 pt / 15 min)',            'Fitness & Movement Studio', 1, 2.5000, 'USD', MD5('rf-MQ4KQLLF-v2-jdi-guided-meditation'), 1, 1, 'marty@the-good-foundry.com'),
    (@cid, 'SUB-MQ4KQLLF', 'Just Do It! — Gratitude Practice (1 pt / 15 min)',           'Fitness & Movement Studio', 1, 2.5000, 'USD', MD5('rf-MQ4KQLLF-v2-jdi-gratitude'),         1, 1, 'marty@the-good-foundry.com'),
    (@cid, 'SUB-MQ4KQLLF', 'Just Do It! — Breathing Exercises (1 pt / 15 min)',          'Fitness & Movement Studio', 1, 2.5000, 'USD', MD5('rf-MQ4KQLLF-v2-jdi-breathing'),         1, 1, 'marty@the-good-foundry.com'),
    (@cid, 'SUB-MQ4KQLLF', 'Just Do It! — Gentle Stretching & Alignment (1 pt / 15 min)','Fitness & Movement Studio', 1, 2.5000, 'USD', MD5('rf-MQ4KQLLF-v2-jdi-stretching'),        1, 1, 'marty@the-good-foundry.com'),
    (@cid, 'SUB-MQ4KQLLF', 'Just Do It! — Sound Bath (1 pt / 15 min)',                   'Fitness & Movement Studio', 1, 2.5000, 'USD', MD5('rf-MQ4KQLLF-v2-jdi-sound-bath'),        1, 1, 'marty@the-good-foundry.com'),
    (@cid, 'SUB-MQ4KQLLF', 'Just Do It! — Guided Relaxation (1 pt / 15 min)',            'Fitness & Movement Studio', 1, 2.5000, 'USD', MD5('rf-MQ4KQLLF-v2-jdi-guided-relaxation'), 1, 1, 'marty@the-good-foundry.com'),
    (@cid, 'SUB-MQ4KQLLF', 'Just Do It! — Stress Reduction Practice (1 pt / 15 min)',    'Fitness & Movement Studio', 1, 2.5000, 'USD', MD5('rf-MQ4KQLLF-v2-jdi-stress-reduction'),  1, 1, 'marty@the-good-foundry.com'),
    (@cid, 'SUB-MQ4KQLLF', 'Just Do It! — Mindful Movement (1 pt / 15 min)',             'Fitness & Movement Studio', 1, 2.5000, 'USD', MD5('rf-MQ4KQLLF-v2-jdi-mindful-movement'),  1, 1, 'marty@the-good-foundry.com');
