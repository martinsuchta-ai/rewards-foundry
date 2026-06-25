# Rewards Foundry — Schools Pilot: Implementation Brief

**Audience:** Claude Code (implementation into the Rewards Foundry management area)
**Edition:** "School Kids / School Students" subscription
**Author:** Product

---

## 1. Objective

Add a school-context behaviour-recognition module to Rewards Foundry. Trusted persons
(teachers/aides) award (**Spin Up**) or deduct (**Spin Down**) wallet points by scanning a
behaviour QR code and identifying a student by KEY or email. Students may also self-scan.

Behaviours are organised on two axes:

- **Scope:** ME (individual) · WE (class/group) · US (whole school)
- **Direction:** Spin Up (ADD points) · Spin Down (SUBTRACT points)

Each Scope × Direction contains **12 PERMA-H behaviours** (2 each for Positive Emotions,
Engagement, Relationships, Meaning, Accomplishment, Health). Plus two **generic libraries**
of 25 Spin Up and 25 Spin Down common school behaviours.

Total behaviour activities to seed: (6 scope/direction sets × 12) + 25 + 25 = **122**.

---

## 2. Data model

Reuse the existing reward-activity / token pattern (the `t=` token that already generates
QR codes at `/api/v1/qr.php?t=<token>` and resolves at `/redeem?t=<token>`).

### `behaviour_activities`
| field | type | notes |
|-------|------|-------|
| id | PK | |
| token | string(32) | same token scheme as existing activities; drives QR + redeem URL |
| scope | enum | `ME` \| `WE` \| `US` \| `GENERIC` |
| direction | enum | `UP` \| `DOWN` |
| perma_h | enum/null | `POSITIVE_EMOTIONS` \| `ENGAGEMENT` \| `RELATIONSHIPS` \| `MEANING` \| `ACCOMPLISHMENT` \| `HEALTH` \| null (generic) |
| title | string | behaviour text |
| points | int | signed or magnitude + direction; default per scope (see §5) |
| edition_id | FK | scopes the activity to the School edition/subscription |
| client_id | FK | tenant/client — drives theming (see §7) |
| active | bool | |

### `spin_events` (audit / wallet ledger entry)
| field | type | notes |
|-------|------|-------|
| id | PK | |
| behaviour_activity_id | FK | |
| student_id | FK | resolved from KEY or email |
| awarded_by | FK | trusted person (teacher) user id; null if self-scan pending confirmation |
| source | enum | `SELF_SCAN` \| `TRUSTED_PERSON` |
| status | enum | `CONFIRMED` \| `PENDING` \| `REJECTED` |
| points_delta | int | signed value actually applied to wallet |
| created_at | datetime | |

Wallet balance updates only on `CONFIRMED`.

---

## 3. Flows

### 3.1 Trusted-person flow (enhance existing `/redeem`)
The current redeem page takes a `t=` token. Enhance it so that when the token resolves to a
**behaviour_activity**, the page:

1. Displays scope, behaviour title, direction, and points (with clear UP/DOWN colour cue).
2. Requires the trusted person to be authenticated (role: teacher/trusted).
3. Accepts the **student KEY or email** (existing identification mechanism).
4. On submit, creates a `CONFIRMED` `spin_event` and applies `points_delta` to the wallet.
5. Shows confirmation with new balance.

Add a **behaviour picker** variant of the page: trusted person opens a single entry point,
selects **Scope → (PERMA-H or Generic) → Behaviour**, then enters KEY/email. This avoids
needing a physical card for every action.

### 3.2 Self-scan flow
Student scans the QR on a printed card/poster. Page resolves the token and:
- If self-scan is **enabled** for that activity: create a `PENDING` `spin_event` tied to the
  student (student must be logged in / identified). A trusted person confirms later, or
  auto-confirm if the client policy allows (configurable per client/edition).
- Spin Down items should default to **trusted-person-only** (no self-scan) — configurable.

### 3.3 Identification
Reuse the existing KEY/email resolution already used at `/redeem`. No new auth scheme.

---

## 4. QR generation
Reuse `/api/v1/qr.php?t=<token>`. Every behaviour_activity gets a token at seed time and its
QR is generated through the existing endpoint. No new QR service needed.

---

## 5. Default points (tune in pilot)
- ME: ±5
- WE: ±10
- US: ±20
- Generic: ±5

Direction `DOWN` applies a negative delta. Make all defaults editable per client.

---

## 6. Management area
In the Rewards Foundry management UI, add a **School Behaviours** section (School edition only):
- Browse/edit the 122 seeded activities, filter by scope/direction/PERMA-H.
- Edit title, points, active flag, self-scan permission.
- **Bulk print/export** behaviour cards and posters (QR + title + points + scope badge).
- Reporting: spin events by student, class, PERMA-H dimension, direction, over time.

---

## 7. Theming — IMPORTANT
**All batch/bulk-created artifacts (behaviour cards, posters, QR sheets, redeem/self-scan
pages) MUST render using the CLIENT's configured set theming** (logo, colour palette, fonts,
brand tokens) rather than generic Rewards Foundry defaults. Theming is resolved from
`client_id` on each activity. Bulk generation must pull the client theme at render time so a
school's printed packs and on-screen pages are consistently branded to that client. Do not
hard-code styling in the card/poster generator — read from the client theme config.

---

## 8. Seed data
Seed all 122 behaviours per the attached library (ME/WE/US Up & Down × 12 PERMA-H, plus
25 generic up / 25 generic down). Generate a token per row and confirm QR resolves via the
existing endpoint.

---

## 9. Acceptance criteria
- 122 activities seeded with working tokens, QR codes, and redeem pages.
- Trusted-person flow: select scope→behaviour, enter KEY/email, wallet updates correctly for
  both UP (add) and DOWN (subtract).
- Self-scan respects per-activity permission; Spin Down trusted-person-only by default.
- All bulk artifacts and pages render in the client's set theme.
- Reporting available by student / class / PERMA-H / direction.
- Module gated to the School Kids / School Students edition subscription.
