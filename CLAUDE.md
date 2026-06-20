# CLAUDE.md — rewards-foundry

Notes for future Claude Code sessions working in this repo. Patterns
established at scaffold time so they don't get accidentally regressed.

---

## Scope

This repo serves `www.rewards-foundry.com` ONLY. The WBM application
lives in the sister repo at `c:/Users/marty/OneDrive/Desktop/00 WM Development`
and deploys to `smart-tools-foundry.com/WBM/`.

**Do not move WBM bank/vault/eval/api code into this repo, and do
not move rewards code back into WBM.** The split is intentional
(brand surface + consumer-agnostic reuse — see the carve-out plan in
`00 WM Development/docs/RewardsFoundry/rewards_foundry_carve_out_2026-06-21.md`).

WBM's bank-super Rewards Foundry panel is a **proxy** to this
service after Phase D — never a direct DB call to WBM's old
`reward_items` table.

## Naming convention

- Env vars: `REWARDS_*` (matches Marty's `rewards_secrets.php`)
- Files: `rewards_*` (rewards_bootstrap.php, rewards_cron_auth.php)
- PHP helpers: `rewards_*` (rewards_db, rewards_cron_auth_check)
- DB tables: `rewards_*` (rewards_consumer, rewards_item, rewards_redemption)

Keep this consistent — every search, grep, and copy-paste assumes the
prefix.

## Stack

- Vanilla HTML/JS/PHP 8.2 — no build step
- MySQL 8 on SiteGround shared hosting (dedicated account, separate
  from smart-tools-foundry.com)
- GitHub Actions deploy via lftp FTPS-explicit (port 21 + AUTH TLS) —
  NOT implicit FTPS `ftps://` which SG doesn't expose on port 990
- Apex `rewards-foundry.com` AND `www` both serve (no apex→www redirect
  unless DNS pushes it that way)

## Critical conventions

### 1. Always backtick column names

SiteGround's PHP/PDO silently 500s on the unquoted `role` column (and
possibly others context-dependently). Wrap every column name in
backticks in every INSERT/UPDATE/SELECT/DELETE. This bit WBM hard
during Wave 3.

### 2. All timestamps UTC

PHP default tz forced to UTC via `api/rewards_bootstrap.php`. PDO
sessions force `time_zone = '+00:00'`. Display tz conversion happens
at render time, never at storage.

### 3. Migration runner pattern

- Schema files: `api/migrate/NNN_<name>.sql`, applied in lexical order
- Tracked in `rewards_schema_migrations` table
- Runner: `api/migrate/run.php?token=<REWARDS_MIGRATE_TOKEN>`
- No transactions around DDL — MySQL implicitly commits on every
  CREATE/ALTER. Use `IF NOT EXISTS` guards everywhere.

### 4. Every PHP endpoint that reads/writes data

```php
require_once __DIR__ . '/db.php';
$pdo = rewards_db();
```

`db.php` handles connection caching, error paths, and the bootstrap
require chain. Don't `new PDO()` directly.

### 5. `db.php` reads credentials from `rewards_secrets.php` ABOVE the webroot

The file lives at
`/home/customer/www/rewards-foundry.com/rewards_secrets.php` — one
level above `public_html`. **NEVER in git, NEVER inside the webroot,
NEVER opened in a local IDE** (the IDE leaks file contents via
selection context to AI assistants).

Single quotes inside `putenv()` to avoid `$` interpolation surprises:

```php
<?php
putenv('REWARDS_DB_PASS=...');
```

### 6. Cron auth: use `rewards_cron_auth.php`

```php
require_once __DIR__ . '/rewards_cron_auth.php';
rewards_cron_auth_check();
```

Behaviour:
- CLI invocation → returns immediately (trusted by filesystem perms)
- HTTP invocation → accepts `?token=<REWARDS_CRON_SECRET>` first,
  falls back to `?token=<REWARDS_MIGRATE_TOKEN>`, otherwise 403

Prefer PHP-CLI cron invocation over `wget`/`curl` URL gating where
possible — keeps secrets out of the SG panel.

### 7. Consumer authentication

Public consumer API at `/v1/*` authenticates per-call:

- **Primary**: `X-Consumer-Key: <api_key>` header (not in server access
  logs, not in browser referrers)
- **Fallback**: `?consumer_key=<api_key>` query param (legacy-friendly)

Server-side: `rewards_resolve_consumer($req)` returns the
`rewards_consumer` row or `null` (then the endpoint responds 401).

Consumer API keys are stored **sha256-hashed at rest** in
`rewards_consumer.api_key_hash`. The plaintext key is shown ONCE
on consumer creation in the admin UI; never retrievable after.

### 8. Brand theme — denormalised on each item

Per the carve-out plan (decision 5): each `rewards_item` row carries
its own `theme_primary_hex` column. WBM proxy sends the org's theme
primary on every item create/update. The new domain never round-trips
back to WBM for theme info.

Why: themes change rarely, the duplication is cheap, no sync race.

### 9. Printed-QR continuity

Existing printed QR codes encode
`https://smart-tools-foundry.com/WBM/app/redeem.html?t=<TOKEN>`.
WBM `app/.htaccess` 301-redirects that path to
`https://www.rewards-foundry.com/redeem?t=<TOKEN>`. The token survives
in `qr_token` (UNIQUE column on `rewards_item`) so existing tokens
resolve to the same item.

**Never change the `qr_token` value** for a row, ever. Print runs are
in the field.

## Deployment

- Push to `main` → GitHub Actions runs `lftp mirror --delete` against
  the SG account
- See `.github/workflows/deploy.yml` for the FTPS-explicit pattern +
  exclude list
- Required GH Actions secrets: `SG_HOST`, `SG_USER`, `SG_PASS`

## What NOT to do

- Don't add the WBM consumer key to this repo — it lives in WBM's
  `wm_secrets.php`; only the sha256 hash lives in `rewards_consumer`
- Don't bypass `db.php` with raw PDO
- Don't store local-time strings — UTC only
- Don't commit anything to `rewards_secrets.php` — it must stay above
  the webroot
- Don't disable hooks (`--no-verify`) when committing
- Don't run `git push --force` against `main`
- Don't change a `qr_token` value (printed in the field)
