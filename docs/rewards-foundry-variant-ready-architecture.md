# Rewards Foundry — Variant-Ready Architecture for Behaviour Recognition

**Author:** Claude (planning artifact)
**Date:** 2026-06-26
**Status:** PROPOSAL — pending Marty review before any code lands

This document proposes how to land the Schools Pilot (per `rewards-foundry-schools-pilot-brief.md`) in a way that absorbs **future edition variants** (Workplace, Sport, Aging Well, University, etc.) with zero schema churn.

The brief's data model works for Schools in isolation but hardcodes PERMA-H, ME/WE/US, and "trusted person = teacher". This proposal abstracts those three axes so the same tables serve every edition.

---

## 1. The key architectural call

> **Don't create a parallel `spin_events` ledger.** The existing `rewards_point_award` table (migration 003) already supports signed integer points + idempotency + email-keyed attribution. The Schools pilot is just one more `source` value on the same ledger.

### Why this matters

- **One source of truth for every wallet movement** across every Power-Up. Wearables already writes here; schools writes here; future bespoke Power-Ups write here.
- **Wallet balance is a single SUM query** — no UNION across two ledgers, no risk of drift.
- **Reporting is unified** — "show me Anna's total this month" works whether she earned via wearable, school behaviour, or wallet redemption.
- **Idempotency** — the existing `(participant_email, source, rule_key, metric_date)` unique key already handles re-posts cleanly.

### What needs adding to `rewards_point_award`

Five new columns to absorb the trusted-person + self-scan + status workflow:

| Column | Type | Purpose |
|---|---|---|
| `awarded_by_email` | `VARCHAR(255) NULL` | Teacher / coach / manager who awarded the points. NULL for automated sources (wearables). |
| `source_type` | `ENUM('AUTO','SELF_SCAN','TRUSTED_PERSON') NOT NULL DEFAULT 'AUTO'` | How the points were earned. Wearables stays AUTO; schools writes SELF_SCAN or TRUSTED_PERSON. |
| `status` | `ENUM('CONFIRMED','PENDING','REJECTED') NOT NULL DEFAULT 'CONFIRMED'` | Wearables always CONFIRMED. Schools self-scan defaults PENDING until a trusted person approves. |
| `participant_key` | `VARCHAR(32) NULL` | Student KEY (mirrors `rewards_redemption.redeemer_key`). NULL when only email is known. |
| `behaviour_activity_id` | `BIGINT UNSIGNED NULL` | FK to the behaviour catalogue activity. NULL for non-behaviour sources. |

**Balance derivation changes from `SUM(points)` to `SUM(points) WHERE status='CONFIRMED'`** — PENDING items don't accrue until a trusted person approves them.

---

## 2. The three new tables — variant-ready

### 2.1 `rewards_behaviour_catalogue` — per-edition config

One row per `(consumer_id, edition_key)`. Defines the LABELS + DEFAULTS for an edition.

```sql
CREATE TABLE rewards_behaviour_catalogue (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    consumer_id     BIGINT UNSIGNED NOT NULL,
    edition_key     VARCHAR(16)     NOT NULL,  -- 'WPK', 'WPY', 'WPA', 'WAW', 'WPS', etc.
    -- Labels per scope. Schools = {ME:"ME",WE:"WE",US:"US"}; Workplace = {ME:"Self",WE:"Team",US:"Org"}; etc.
    scope_labels    JSON            NOT NULL,
    -- Labels per dimension. Schools = PERMA-H; Workplace = PERMA+/Hazards/Civility; Aging Well = LiveWell pillars.
    dimension_labels JSON           NOT NULL,
    -- Default point bands per scope.
    -- Schools = {"ME":5,"WE":10,"US":20,"GENERIC":5}; per-edition overrideable.
    default_point_bands JSON        NOT NULL,
    -- Who's the "trusted person" in this edition? Teacher / Coach / Manager / Care Coordinator.
    trusted_role_label VARCHAR(40)  NOT NULL,
    -- Terminology overrides. Defaults Spin Up / Spin Down; some editions might prefer "Award" / "Deduct" etc.
    terminology     JSON            NULL,
    active          TINYINT(1)      NOT NULL DEFAULT 1,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_catalogue_consumer_edition (consumer_id, edition_key),
    KEY idx_catalogue_active (active),
    CONSTRAINT fk_catalogue_consumer
        FOREIGN KEY (consumer_id) REFERENCES rewards_consumer(id)
);
```

### 2.2 `rewards_behaviour_activity` — the actual scannable behaviours

One row per behaviour (122 for Schools). Same `qr_token` scheme as `rewards_item` so existing QR infra works.

```sql
CREATE TABLE rewards_behaviour_activity (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    consumer_id         BIGINT UNSIGNED NOT NULL,
    sub_id              VARCHAR(64)     NOT NULL,
    catalogue_id        BIGINT UNSIGNED NOT NULL,
    -- Token drives QR + redeem URL. Mirror rewards_item.qr_token scheme verbatim.
    qr_token            VARCHAR(64)     NOT NULL,
    -- VALUE not label; label lives on the catalogue.
    scope               ENUM('ME','WE','US','GENERIC') NOT NULL,
    direction           ENUM('UP','DOWN') NOT NULL,
    -- Key not label; catalogue.dimension_labels maps key→display.
    -- NULL when scope='GENERIC' (the 25+25 universal libraries).
    dimension_key       VARCHAR(40)     NULL,
    title               VARCHAR(255)    NOT NULL,
    -- Magnitude. Sign comes from direction at award-insert time.
    points              INT             NOT NULL,
    self_scan_enabled   TINYINT(1)      NOT NULL DEFAULT 0,
    -- Denormalised theme primary so the QR/poster compositor renders
    -- without round-tripping to the consumer (same pattern as
    -- rewards_item.theme_primary_hex).
    theme_primary_hex   CHAR(7)         NULL,
    active              TINYINT(1)      NOT NULL DEFAULT 1,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by_email    VARCHAR(255)    NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_activity_token (qr_token),
    KEY idx_activity_consumer_sub (consumer_id, sub_id),
    KEY idx_activity_catalogue (catalogue_id),
    KEY idx_activity_scope_dir (scope, direction),
    KEY idx_activity_dimension (dimension_key),
    KEY idx_activity_active (active),
    CONSTRAINT fk_activity_consumer
        FOREIGN KEY (consumer_id) REFERENCES rewards_consumer(id),
    CONSTRAINT fk_activity_catalogue
        FOREIGN KEY (catalogue_id) REFERENCES rewards_behaviour_catalogue(id)
);
```

### 2.3 No third table

`spin_events` from the brief → folds into `rewards_point_award` per §1.

---

## 3. How a single award flows

### 3.1 Trusted-person flow (teacher awards Spin Up)

1. Teacher opens `/redeem?t=<qr_token>` or the behaviour picker → resolves to a `rewards_behaviour_activity` row.
2. Auth check — teacher is signed in as a trusted user (mechanism TBD, question 2 below).
3. Teacher enters student KEY or email.
4. Server writes one row to `rewards_point_award`:
   - `source = 'schools_behaviour'`
   - `source_type = 'TRUSTED_PERSON'`
   - `status = 'CONFIRMED'`
   - `points = activity.points * (direction == 'UP' ? +1 : -1)` (signed)
   - `awarded_by_email = teacher's email`
   - `participant_email | participant_key = student's identifier`
   - `behaviour_activity_id = activity.id`
   - `rule_key = qr_token` (so the existing idempotency unique key catches double-fires)
   - `metric_date = today`
5. Show confirmation + new balance.

### 3.2 Self-scan flow (student scans QR themselves)

Same as 3.1 but:
- `source_type = 'SELF_SCAN'`
- `status = 'PENDING'` (defaults to PENDING; trusted person approves later in the management UI)
- `awarded_by_email = NULL` until approved

Spin Down behaviours default `self_scan_enabled = 0` so students can't deduct their own points.

### 3.3 Wearables flow (unchanged)

- `source = 'wearable_sync'`
- `source_type = 'AUTO'`
- `status = 'CONFIRMED'`
- `behaviour_activity_id = NULL`
- `awarded_by_email = NULL`

The new columns all NULL-default cleanly so the wearables flow is unaffected.

---

## 4. Variant readiness — concrete examples

### 4.1 Schools (WPK / WPY) — first to ship

```json
// rewards_behaviour_catalogue row for WPK
{
  "edition_key": "WPK",
  "scope_labels": {"ME": "ME", "WE": "WE", "US": "US"},
  "dimension_labels": {
    "POSITIVE_EMOTIONS": "Positive Emotions",
    "ENGAGEMENT": "Engagement",
    "RELATIONSHIPS": "Relationships",
    "MEANING": "Meaning",
    "ACCOMPLISHMENT": "Accomplishment",
    "HEALTH": "Health"
  },
  "default_point_bands": {"ME": 5, "WE": 10, "US": 20, "GENERIC": 5},
  "trusted_role_label": "Teacher",
  "terminology": {"spin_up": "Spin Up", "spin_down": "Spin Down"}
}
```

### 4.2 Workplace (WPA) — future variant

```json
// rewards_behaviour_catalogue row for WPA
{
  "edition_key": "WPA",
  "scope_labels": {"ME": "Self", "WE": "Team", "US": "Org"},
  "dimension_labels": {
    "POSITIVE_EMOTIONS": "Positive Emotions",
    "ENGAGEMENT": "Engagement",
    "RELATIONSHIPS": "Relationships",
    "MEANING": "Meaning",
    "ACCOMPLISHMENT": "Accomplishment",
    "HEALTH": "Health",
    "HAZARDS": "Psychosocial Safety",
    "CIVILITY": "Civility"
  },
  "default_point_bands": {"ME": 5, "WE": 10, "US": 20, "GENERIC": 5},
  "trusted_role_label": "Manager",
  "terminology": {"spin_up": "Recognise", "spin_down": "Flag concern"}
}
```

### 4.3 Sport (WPS) — future variant

```json
{
  "edition_key": "WPS",
  "scope_labels": {"ME": "Athlete", "WE": "Team", "US": "Club"},
  "dimension_labels": { /* sport-specific dims */ },
  "trusted_role_label": "Coach",
  "terminology": {"spin_up": "Acknowledge", "spin_down": "Reset"}
}
```

### 4.4 Aging Well (WAW) — future variant

```json
{
  "edition_key": "WAW",
  "scope_labels": {"ME": "Self", "WE": "Care Circle", "US": "Community"},
  "dimension_labels": { /* 6 LiveWell pillars */ },
  "trusted_role_label": "Care Coordinator",
  "terminology": {"spin_up": "Notice", "spin_down": "Reflect"}
}
```

Every variant gets its own catalogue row + its own seed list of activities. **Zero schema churn.**

---

## 5. UI labelling — driven by the catalogue config

Every screen that renders a behaviour pulls the LABEL from the catalogue config:

```php
$cat = rewards_get_behaviour_catalogue($consumer_id, $edition_key);
// In any rendered output:
echo $cat['scope_labels'][$activity->scope];           // "Team" for WPA, "WE" for WPK
echo $cat['dimension_labels'][$activity->dimension_key]; // resolved per edition
echo $cat['terminology']['spin_up'];                    // "Recognise" / "Spin Up"
echo $cat['trusted_role_label'];                        // "Coach" / "Teacher"
```

The management UI, the `/redeem` page, the printable cards/posters, the reporting tab — all read from this one config. **No hardcoded "Teacher" or "PERMA-H" or "ME/WE/US" anywhere in code.**

---

## 6. What the brief asks vs. what this proposal does

| Brief (verbatim) | This proposal |
|---|---|
| `behaviour_activities` table | ✓ becomes `rewards_behaviour_activity` (rewards_ prefix to match schema convention) |
| `spin_events` table | ✗ replaced by extending `rewards_point_award` (5 new columns) — single ledger for all sources |
| Hardcoded PERMA-H enum | ✓ stored as `dimension_key VARCHAR(40)`; labels in catalogue config |
| Hardcoded ME/WE/US labels | ✓ value enum stays; labels driven from catalogue config |
| Hardcoded "trusted_person = teacher" | ✓ `trusted_role_label` per edition |
| Per-client theming on bulk artifacts | ✓ same pattern as `rewards_item.theme_primary_hex` |
| Reuse existing `t=` token + QR | ✓ `qr_token` column on activity table |
| Gated to Schools edition | ✓ catalogue row gates it; new variants come online by adding rows |

---

## 7. Open questions — RESOLVED 2026-06-26

| # | Question | Marty's answer | Implementation |
|---|---|---|---|
| 1 | DB location | **rewards-foundry** | New tables land in the rewards-foundry MySQL DB, alongside `rewards_item` / `rewards_redemption` / `rewards_point_award` |
| 2 | Self-scan policy | **Both** (per-activity flag + per-client override) | Activity carries `self_scan_enabled` BOOL; catalogue carries `self_scan_policy ENUM('allow','never','spin_up_only')`. Catalogue policy WINS over activity flag. |
| 3 | PENDING → CONFIRMED approver | **(a) Any teacher in the school** | Any user with the trusted role for that `sub_id` sees the full pending queue in the management UI. Approval / rejection writes the approver's email back onto the row. No class→teacher mapping needed for the pilot. |
| 4 | Bulk print format | **Both** (A4 posters + A6 card decks) | Two PDF templates per catalogue: `bulk_print.php?layout=poster_a4` and `bulk_print.php?layout=cards_a6`. Both pull theme from the catalogue's denormalised `theme_primary_hex`. |
| 5 | Schools-pilot launch clients | **SUB-MOVWPL0B (Kids · WPK)** + **SUB-MOVXLAHI (Students · WPY)**. Theme from each sub's WBM theme config. | Two catalogue rows seeded in migration 004 (see `draft_004_behaviour_catalogue.sql`). Importer reads the WBM theme config for each sub at seed-time and stamps `theme_primary_hex` onto the catalogue row + every behaviour_activity row. |

---

## 8. Proposed phasing

| Phase | Deliverable | Effort estimate |
|---|---|---|
| **A** | Migration 004 — three table changes (catalogue + activity + point_award alters). Seed default WPK catalogue row. | ~half-day |
| **B** | Behaviour-library seed JSON (122 items for WPK). Importer script. | ~half-day after Marty provides/approves the wording for the 122 behaviours |
| **C** | `/redeem` enhancement — token → behaviour resolution + trusted-person flow + self-scan flow + PENDING handling | ~1-1.5 days |
| **D** | Management UI (`rewards-foundry/app/`) — School Behaviours tab: browse/edit + bulk-print + reporting | ~2-3 days |
| **E** | Printable cards/posters generator (PDF, theme-aware) | ~1 day |
| **F** | WPY variant catalogue (Youth edition rolls in next) | ~half-day (catalogue + seed only) |

Total Schools-pilot path: **~5-7 working days** end-to-end. Variant additions after that: half-day per edition (catalogue + seed only).

---

## 9. Files this proposal will touch (when greenlit)

```
rewards-foundry/
  api/migrate/
    004_behaviour_catalogue.sql            # NEW — schema
  api/v1/
    redeem.php                             # ENHANCED — token resolves to either rewards_item OR rewards_behaviour_activity
    behaviour_award.php                    # NEW — trusted-person + self-scan award endpoint
    behaviour_approve.php                  # NEW — PENDING → CONFIRMED workflow
    behaviour_catalogue.php                # NEW — admin CRUD on the catalogue config
    behaviour_activity.php                 # NEW — admin CRUD on activities
    behaviour_print.php                    # NEW — bulk PDF generator (cards + posters)
  app/admin/
    school-behaviours.html                 # NEW — management UI page
  docs/
    schools_behaviour_seed_wpk.json        # NEW — 122 behaviours for WPK
    rewards-foundry-variant-ready-architecture.md  # THIS FILE
```

WBM-side: nothing for Phase A-E. Phase F+ might expose a "Schools Behaviours" pill in the bank super if Marty wants admins managing it from the bank instead of the rewards-foundry admin UI.

---

## 10. Phase A — SHIPPED 2026-06-26 ✓

After Marty's GO, the following landed in the repo (uncommitted; Marty
commits + pushes per the standing rule):

| File | Purpose |
|---|---|
| [`api/migrate/004_behaviour_catalogue.sql`](../api/migrate/004_behaviour_catalogue.sql) | Migration — 2 new tables + 5 new columns on `rewards_point_award` + 2 catalogue seed rows for SUB-MOVWPL0B (Kids/WPK) and SUB-MOVXLAHI (Students/WPY). All ALTERs gated via `information_schema` probe per the standing rule. |
| [`api/migrate/import_school_behaviours.php`](../api/migrate/import_school_behaviours.php) | Token-gated importer. Reads `api/seeds/schools_behaviour_seed_<edition>.json`, mints a unique 32-hex qr_token per row (checking BOTH `rewards_item` AND `rewards_behaviour_activity` for collisions), stamps theme_primary_hex onto catalogue + activities, idempotent re-run via content-dedupe. HTTP + CLI invocation. |
| [`api/seeds/schools_behaviour_seed_wpk.json`](../api/seeds/schools_behaviour_seed_wpk.json) | Starter seed shape for Kids/WPK. Section markers + a few example rows per cell — Marty fills the rest from the source library. Lives under `api/` so the rewards-foundry deploy workflow (which mirrors `./public/` + `./api/` only) ships it. |

### Run order

1. Marty commits + pushes from this directory; GitHub Actions deploys.
2. Apply migration:
   `GET https://<rewards-foundry-host>/api/migrate/run.php?token=<REWARDS_MIGRATE_TOKEN>`
3. Drop the full WPK behaviour library into `api/seeds/schools_behaviour_seed_wpk.json`
   (or run with the current starter file to validate the pipeline end-to-end).
4. (Optional) Add `api/seeds/schools_behaviour_seed_wpy.json` for the Students pilot.
5. Run the importer per pilot sub:
   `POST /api/migrate/import_school_behaviours.php?token=<TOKEN>&sub_id=SUB-MOVWPL0B&theme_primary_hex=%23<hex>&dry_run=1`
   Verify the counts. Drop `&dry_run=1` to actually insert.
   Repeat with `sub_id=SUB-MOVXLAHI` for Students.

## 11. Held until Phase A is verified live

- Behaviour seed library — held until Marty drops the 122-row JSON in.
- Phase C — `/redeem` enhancement, behaviour_award.php, behaviour_approve.php.
- Phase D — Management UI in `rewards-foundry/app/admin/`.
- Phase E — Bulk-print PDF generator (A4 posters + A6 cards).
- Phase F — WPY variant catalogue + seed.

Each ready to start when the previous phase is verified live.
