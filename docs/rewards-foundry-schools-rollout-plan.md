# Schools Rollout — Plan & Decisions

**Pairs with:** [`rewards-foundry-schools-rollout-brief.md`](rewards-foundry-schools-rollout-brief.md)
**Author:** Claude (planning artifact)
**Date:** 2026-06-26
**Status:** PROPOSAL — pending Marty review

Phase A (schema + importer) is in place. This brief adds three follow-on workstreams that surround the pilot launch: the **client + rep explainer pages**, the **rep-wallet notice system**, and the **contact-URL config**. None of these depend on Phase C-F backend work, so they can land in **parallel** with the build.

---

## 1. Where each piece lives — repo placement

The brief doesn't say which repo gets what. My recommended split:

| Workstream | Repo | Why |
|---|---|---|
| **A1 — Client explainer page** ("Rewards Foundry for Schools") | **TGF** (`the-good-foundry/public/rewards-foundry-schools.html`) | Public marketing surface; sits alongside the homepage + Power-Up showcase already on the TGF site. Auto-deploys via the existing TGF GH Actions. |
| **A2 — Rep-facing page** ("Schools Power-Up: Rep Brief") | **WBM** (`app/rep_schools_brief.html`) | Auth-gated to the rep role. Sits with the other rep surfaces (rep_wallet, rep_portal). Picks up the rep session check that already exists. |
| **B — `rep_notices` + `rep_notice_reads` schema** | **WBM** | Reps are WBM users. The rep_wallet itself is a WBM surface. Notices aren't a rewards-foundry concept — they're a generic rep-comms system that just happens to launch with the Schools Power-Up. |
| **B — Rep wallet "Notices" surface** | **WBM** (extends `app/rep_wallet.html`) | Same surface that we already touched today for the Policies nav |
| **B — Admin "Notices" CRUD** | **WBM** (`app/wm_bank_super.html` — new Bank Super tab) | Marty's the admin; matches the pattern of every other admin CRUD on the platform |
| **C — `CONTACT_URL` config** | Both — but **single source of truth in WBM** (`data/global_settings.json` or a new `data/contact_config.json`) and TGF reads it via a tiny `/api/contact_config.php` proxy if the public page needs it dynamically | Avoids two configs drifting |

This split keeps each piece in the repo that already owns the surrounding concerns. **Rewards-foundry stays focused on the rewards backend** — no marketing pages or notice admin land there.

---

## 2. Schema — `rep_notices` + `rep_notice_reads` (WBM migration)

Lands as **WBM migration 175** (next free number after 174_wearable_rewards_tracking):

```sql
-- 175_rep_notices.sql — generic rep-wallet announcement system.
--                       First use: Schools Power-Up launch notice.

CREATE TABLE IF NOT EXISTS `rep_notices` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title`        VARCHAR(255)    NOT NULL,
    `body`         TEXT            NOT NULL,
    `cta_label`    VARCHAR(80)     NULL,
    `cta_url`      VARCHAR(1024)   NULL,
    `category`     ENUM('POWER_UP','PRODUCT','SYSTEM','GENERAL') NOT NULL DEFAULT 'GENERAL',
    `priority`     ENUM('NORMAL','HIGH') NOT NULL DEFAULT 'NORMAL',
    `starts_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ends_at`      DATETIME        NULL,
    `active`       TINYINT(1)      NOT NULL DEFAULT 1,
    `created_by`   VARCHAR(255)    NULL,
    `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_rn_active_window` (`active`, `starts_at`, `ends_at`),
    KEY `idx_rn_priority`      (`priority`, `starts_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rep_notice_reads` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `rep_notice_id` BIGINT UNSIGNED NOT NULL,
    `rep_email`     VARCHAR(255)    NOT NULL,
    `read_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_rnr_pair` (`rep_notice_id`, `rep_email`),
    KEY `idx_rnr_rep` (`rep_email`),
    CONSTRAINT `fk_rnr_notice` FOREIGN KEY (`rep_notice_id`)
        REFERENCES `rep_notices`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Two diffs from the brief's data model:**

1. **`rep_email` not `rep_id`** in `rep_notice_reads`. WBM's rep identity is the email (per the `representatives` table); no integer rep_id is canonical. Matches the rest of the WBM schema.
2. **`created_by` is VARCHAR not FK.** Bank Super admin actions stamp the actor email (per the `activity_log` convention). FK would force a users-table join we don't actually use.

Indexes target the two hot queries:
- "Show me active notices in the current time window, newest first, HIGH first" → `idx_rn_active_window` + `idx_rn_priority`
- "Has this rep read this notice?" → `uk_rnr_pair`
- "How many unread for this rep?" → `idx_rnr_rep` + the LEFT JOIN

**Unread count query** (one round trip):
```sql
SELECT COUNT(*) FROM rep_notices rn
LEFT JOIN rep_notice_reads rnr
  ON rnr.rep_notice_id = rn.id AND rnr.rep_email = ?
WHERE rn.active = 1
  AND rn.starts_at <= NOW()
  AND (rn.ends_at IS NULL OR rn.ends_at >= NOW())
  AND rnr.id IS NULL
```

---

## 3. Rep wallet — where Notices surface

Looking at the rep_wallet.html chrome touched today: the left nav has Dashboard / Portfolios / Clients / Subscriptions / Offers / Billing / Policies. Add **Notices** between Offers and Billing.

The nav-item gets an unread badge — small circle with the count (red on the orange-themed wallet → `var(--orange)` for consistency with the rest of the rep wallet, NOT TGF teal because rep_wallet has its own palette).

Notices view layout: list of cards, newest first, HIGH pinned. Each card carries:
- Category badge (Power-Up / Product / System / General — TGF gradient styling per §0 of the brief)
- Title + body
- CTA button (white text on TGF gradient)
- "Mark read" link (auto-fires on CTA click or explicit dismiss)

Endpoint additions:
- `api/rep_notices_list.php` — GET — returns active notices + which ones this rep has read
- `api/rep_notices_mark_read.php` — POST — writes a `rep_notice_reads` row idempotently

---

## 4. The two explainer pages (A1 + A2)

The brief says: *"Both page designs already exist as approved HTML mockups — request them from Product and use as the visual source of truth."*

**Open question 1:** Where are those mockups? If they're paste-into-a-message-when-you-come-back content, I can land both pages in one commit each. If I need to design from the brief's bullet-list outline, I can draft starter versions for you to refine.

Recommended path:
- **A1 (TGF):** drop mockup HTML into `the-good-foundry/public/rewards-foundry-schools.html`, theme via the existing TGF tokens (matches the homepage Power-Up showcase chrome).
- **A2 (WBM):** drop mockup HTML into `app/rep_schools_brief.html`, gate via the existing rep-session guard (same pattern as `rep_wallet.html` / `rep_portal.html`).

**Pricing slot (A2 §6):**

Brief says: "Pricing is config-driven, not hard-coded." I'd store the placeholder text + the future real number in a new `data/rep_brief_config.json` so Bank Super can flip it when pricing locks. Default content:

```json
{
  "schools": {
    "pricing_lead": "Pricing for the pilot is set per cohort + edition and rolls into the tailored offer via Offerings. Route pricing questions to a representative for the current pilot rate.",
    "pricing_pill_number": null,
    "pricing_pill_label":  null
  }
}
```

When Marty wants to drop a number in: edit the JSON → page re-renders with the pill.

---

## 5. CONTACT_URL config (§C of the brief)

Single config value lands in `data/global_settings.json` (WBM) — it already holds platform-wide singletons. Two keys:

```json
{
  "contact_url":          "https://www.the-good-foundry.com/#contact",
  "book_a_call_url":      "https://www.coachingfoundry.com/bookcall/"
}
```

For TGF's public A1 page, the values get baked at page-write time (or fetched via a tiny `api/contact_config.php` proxy if Marty wants live-edit without redeploy). My pick: **bake at page-write time** — these URLs change once a year if that; not worth the live-fetch round trip.

---

## 6. Launch-notice seed

Once Phase C-D ship and the Schools rep brief page (A2) URL is locked, run a one-time INSERT to seed the launch notice. Put it in a tiny token-gated PHP at `api/admin_seed_schools_launch_notice.php`:

```sql
INSERT INTO rep_notices
    (title, body, cta_label, cta_url, category, priority, starts_at, active, created_by)
VALUES (
    'New Power-Up: Schools behaviour recognition',
    'Reward positive behaviour and discourage negative behaviour across Me · We · Us, with a full dashboard. A Power-Up that bolts onto any active Wellbeing Matters subscription.',
    'Read the rep brief',
    '/app/rep_schools_brief.html',
    'POWER_UP',
    'HIGH',
    NOW(),
    1,
    'marty@the-good-foundry.com'
);
```

Date stamp = launch day. Marty triggers it manually so we control the moment every rep sees the notice.

---

## 7. Bank Super admin CRUD for Notices

New tab in `wm_bank_super.html` (matches every other Bank Super CRUD). List existing notices with their active/window state; new-notice form; per-row "Edit / Expire / Delete". Endpoints:

- `api/admin_rep_notices.php?action=list`
- `api/admin_rep_notices.php?action=upsert` (handles create + update)
- `api/admin_rep_notices.php?action=delete`

This is the path the brief calls for in §B4 — "future Power-Ups can be announced the same way without a code change."

---

## 8. Open questions / decisions

| # | Question | My recommended default if you don't have time to answer | Blocking? |
|---|---|---|---|
| 1 | Do the HTML mockups for A1 + A2 exist? Where do I pick them up from? | If not, I draft starter pages from the brief's bullet outline (you refine) | No — I can start with starters |
| 2 | Should the rep wallet Notices badge follow rep_wallet's orange palette, or use the TGF teal→green→amber gradient from the brief? | **Orange** — keeps wallet chrome consistent; notice CARDS use the TGF gradient per §0 | No |
| 3 | Where does `CONTACT_URL` live — WBM `global_settings.json` or a new `contact_config.json`? | `global_settings.json` (avoids a new file for two keys) | No |
| 4 | A2 pricing pill — is there a number you want to seed today, or stays NULL until pilot pricing locks? | Stays NULL — pill renders only when populated | No |
| 5 | Notices visibility: only the rep-wallet (current scope) or also a "you have N unread notices" pill in the rep_portal too? | Wallet only for the pilot — portal can come later | No |

None of these are blocking. I can start when you give the word.

---

## 9. Phase mapping — where this fits

Existing phasing (architecture doc §10/§11):

| Phase | Deliverable |
|---|---|
| **A** ✓ | Migration 004 + importer (rewards-foundry) |
| **B** | Behaviour library seed (your library, your call) |
| **C** | `/redeem` enhancement + behaviour_award + behaviour_approve (rewards-foundry) |
| **D** | School Behaviours management UI (rewards-foundry admin) |
| **E** | Bulk-print PDF generator (rewards-foundry) |
| **F** | WPY variant catalogue |

This rollout brief adds:

| Phase | Deliverable | Repo | Depends on |
|---|---|---|---|
| **G1** | A1 client explainer page (`rewards-foundry-schools.html`) | TGF | None — can start now |
| **G2** | A2 rep brief page (`rep_schools_brief.html`) | WBM | None — can start now |
| **G3** | Migration 175 + Notices wallet surface + endpoints | WBM | None — can start now |
| **G4** | Bank Super Notices CRUD tab | WBM | G3 |
| **G5** | CONTACT_URL config wired through both pages | WBM + TGF | G1 + G2 |
| **G6** | Launch-notice INSERT (the seed) | WBM | G2 + G3 (needs the rep brief URL + the schema) |

G1-G5 run **in parallel** with the Phase C-F build. G6 fires on launch day.

Total Schools rollout extras: **~2-3 days** beyond the Phase A-F core build.

---

## 10. What I'd ship in one commit when you say go (G3 — the highest-leverage piece)

Migration 175 + the two rep_wallet endpoints + the Notices view in rep_wallet.html. Lands the BACKBONE so any future Power-Up announcement uses the same plumbing. After that, G1/G2 land next, and G6 seeds the actual launch notice.

Standing by — say the word when you want me to start. Phase A verification first.
