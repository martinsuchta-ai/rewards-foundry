# Rewards Foundry — Schools Power-Up: Rollout & Rep-Notice Brief

**Audience:** Claude Code
**Relates to:** `rewards-foundry-schools-pilot-brief.md` (the core feature build). This brief
covers two additional pieces: (A) the branded client + rep explainer pages, and (B) the
rep-wallet NOTICE that announces the new Power-Up and links reps to their page.

---

## 0. Brand adherence (applies to everything below)

All pages and assets MUST adhere to The Good Foundry theme as seen at
`https://www.the-good-foundry.com`:

- **Primary gradient:** teal → green → amber. Use approximately
  `#0F6E56` (teal) → `#639922` (green) → `#BA7517` (amber). Pull the exact tokens from the
  client theme config / brand stylesheet rather than hard-coding if those tokens exist.
- **Logo:** the Good Foundry gradient primary logo
  (`/logos/TGF_logo/good-foundry-gradient-primary_2560.png`).
- **Voice & framing:** "Power-Up · Pilot", the **Me · We · Us** three-level model, and
  **PERMA+** as the wellbeing framework. CTA voice is "Talk to us" / "Talk to a
  representative", with the promise of a response within one business day.
- **Theming is client-driven:** where a page is rendered per client (school-facing
  artifacts, cards, posters, redeem/self-scan pages), it MUST read the CLIENT's set theme
  (logo, palette, fonts) at render time, not Good Foundry defaults. Do not hard-code styling
  in any bulk/batch generator — read from the client theme config.

---

## A. Two explainer pages

Create two web pages in the management/marketing area, both brand-themed per §0.

### A1. Client-facing explainer — "Rewards Foundry for Schools"
Audience: prospective/active school clients. Public or share-link accessible.
Content (already designed — mirror this structure):
1. Gradient hero: "Rewards Foundry for Schools" + Power-Up · Pilot badge + one-line intro.
2. Spin Up / Spin Down explanation (two cards).
3. Me · We · Us — three level cards.
4. "How a spin happens" — 4 steps: Scan QR → Enter code/email → Confirm spin → Wallet updates.
5. "Your full dashboard, included" — Me view / We view / Us view drill-down.
6. PERMA+ footnote line.
7. **CTA block:** "Talk to a representative →" linking to the contact form, plus
   `info@the-good-foundry.com`.

### A2. Rep-facing page — "Schools Power-Up: Rep Brief"
Audience: internal Relationship Managers. **Auth-gated to the rep role.**
This is the page the rep-wallet NOTICE (§B) links to.
Content:
1. Internal badges: "Internal · RM brief" + "Power-Up · Pilot".
2. One-line summary of the offering.
3. Who it's for / What it is (two cards).
4. Three things to lead with (PERMA+ alignment, Me·We·Us visibility, client branding).
5. Quick talk track (setup, gaming, subscription model).
6. **Pricing guidance:** do NOT hard-code a price. State that pricing is set per pilot by
   edition + cohort size, built into the tailored offer via the offerings flow, and that reps
   route pricing questions to a representative for the current pilot rate. Leave a clearly
   marked, config-driven slot so a real figure can be dropped in later without code changes.
7. **CTA block:** "Contact a representative →" + `info@the-good-foundry.com`.

Both page designs already exist as approved HTML mockups — request them from Product and use
as the visual source of truth.

---

## B. Rep-wallet NOTICE (the new requirement)

When the Schools Power-Up launches, every rep should see a NOTICE in their **rep wallet**
announcing it, which links through to the rep-facing page (A2).

### B1. Data model

#### `rep_notices`
| field | type | notes |
|-------|------|-------|
| id | PK | |
| title | string | e.g. "New Power-Up: Schools behaviour recognition" |
| body | text | short announcement copy (1–3 sentences) |
| cta_label | string | e.g. "Read the rep brief" |
| cta_url | string | link to the A2 rep page |
| category | enum | `POWER_UP` \| `PRODUCT` \| `SYSTEM` \| `GENERAL` |
| priority | enum | `NORMAL` \| `HIGH` |
| starts_at | datetime | when the notice becomes visible |
| ends_at | datetime/null | optional expiry |
| active | bool | |
| created_by | FK | admin user |
| created_at | datetime | |

#### `rep_notice_reads`
| field | type | notes |
|-------|------|-------|
| id | PK | |
| rep_notice_id | FK | |
| rep_id | FK | |
| read_at | datetime | set when the rep opens/dismisses the notice |

A notice is "unread" for a rep when no `rep_notice_reads` row exists for that pair.

### B2. Surfacing in the rep wallet
- Add a **Notices** area to the rep wallet view. Show active notices
  (`active = true AND now BETWEEN starts_at AND COALESCE(ends_at, now)`), newest first,
  `HIGH` priority pinned to the top.
- Each notice renders: title, body, and a CTA button (`cta_label` → `cta_url`).
- Show an **unread badge/count** on the wallet's Notices entry point driven by
  `rep_notice_reads`.
- Clicking the CTA (or an explicit dismiss) writes a `rep_notice_reads` row so it stops
  counting as unread. Opening the link should mark-as-read.
- Theme the notice card per §0 (Power-Up styling: gradient accent, Power-Up badge).

### B3. Seed the launch notice
Insert one `rep_notices` row at launch:
- title: `New Power-Up: Schools behaviour recognition`
- body: `Reward positive behaviour and discourage negative behaviour across Me · We · Us,
  with a full dashboard. A Power-Up that bolts onto any active Wellbeing Matters subscription.`
- cta_label: `Read the rep brief`
- cta_url: the A2 rep page URL
- category: `POWER_UP`
- priority: `HIGH`
- starts_at: launch datetime
- active: true

### B4. Admin control
Add a simple admin screen to create/edit/expire `rep_notices` so future Power-Ups can be
announced the same way without a code change. Fields map 1:1 to the table above.

---

## C. CTA / link configuration

- The contact CTA on both pages should point at the site's contact mechanism. Use a single
  config value (e.g. `CONTACT_URL`) so it can be set precisely — default
  `https://www.the-good-foundry.com/#contact`. There is also a "Book a call" option
  (`https://www.coachingfoundry.com/bookcall/`) — expose both if Product wants the secondary
  CTA.

---

## D. Acceptance criteria
- Client page (A1) and rep page (A2) live, brand-themed per §0, rep page auth-gated to reps.
- Pricing on A2 is config-driven, not hard-coded.
- `rep_notices` + `rep_notice_reads` tables exist; rep wallet shows a Notices area with an
  unread count.
- Launch notice (B3) appears in every rep's wallet as unread, pinned (HIGH), and its CTA opens
  the A2 rep page and marks the notice read.
- Admin can create/edit/expire notices without code changes.
- All CTAs resolve via the configurable `CONTACT_URL` (and optional book-a-call URL).
