# rewards-foundry — standalone Rewards Foundry service

Serves `www.rewards-foundry.com`. Public consumer API + admin UI for
managing rewards items + recording redemptions on behalf of any
consumer (WBM is the first).

## What lives here

- **Public consumer API** at `/v1/*` — items list, QR PNG generator,
  redemption submit. Authenticated per-call via consumer API key.
- **Admin UI** at `/admin/*` — login (bcrypt), catalogue curate,
  redemption analytics + CSV export.
- **Public redemption page** at `/redeem?t=<TOKEN>` — what end users
  land on when they scan a printed QR.

## What does NOT live here

- WBM bank super UI (stays in `00-WM-Development` — but its Rewards
  Foundry panel will proxy to this service after Phase D).
- The WBM evaluation / vault / participant-link QR composer (different
  feature, same composer in principle — kept in WBM).
- TGF marketing pages.

## Stack

- Vanilla HTML / JS / PHP 8.2 — no build step, no framework
- MySQL 8 on SiteGround shared hosting (separate account from
  smart-tools-foundry.com)
- GitHub Actions deploy via lftp FTPS (explicit, port 21 + AUTH TLS)
- Hosted at `www.rewards-foundry.com`

## Required GH Actions secrets

- `SG_HOST` — SG FTP hostname (e.g. `ftp.rewards-foundry.com` or
  whatever SG showed under DevTools → FTP Accounts)
- `SG_USER` — full FTP user (`user@rewards-foundry.com` shape on SG
  shared)
- `SG_PASS` — single-quote in GH secret to avoid `$` interpolation

## Required `rewards_secrets.php` (above webroot)

Lives at `/home/customer/www/rewards-foundry.com/rewards_secrets.php`,
NEVER in git, NEVER inside `public_html`:

```php
<?php
putenv('REWARDS_DB_HOST=localhost');
putenv('REWARDS_DB_NAME=...');
putenv('REWARDS_DB_USER=...');
putenv('REWARDS_DB_PASS=...');               // single quotes — no $ interp
putenv('REWARDS_MIGRATE_TOKEN=...');         // admin-paste secret for migrate runner
putenv('REWARDS_CRON_SECRET=...');           // cron HTTP auth
putenv('REWARDS_SESSION_SECRET=...');        // admin session signing + IP hash salt
```

## Migration runner

```
https://www.rewards-foundry.com/api/migrate/run.php?token=<REWARDS_MIGRATE_TOKEN>
```

Applies any pending `api/migrate/NNN_*.sql` files in lexical order,
tracks state in `rewards_schema_migrations`.

## Repo layout

```
.
├── api/
│   ├── rewards_bootstrap.php   (UTC tz, CORS, IP anonymisation)
│   ├── db.php                  (PDO + secrets loader)
│   ├── rewards_cron_auth.php   (cron + migrate auth helpers)
│   ├── admin/                  (admin UI endpoints: auth, items, redemptions)
│   ├── lib/
│   │   └── qr_compose_helper.php  (mirror of WBM helper)
│   ├── v1/                     (public consumer API)
│   └── migrate/
│       ├── run.php
│       ├── .htaccess           (block direct .sql access)
│       └── NNN_*.sql
├── app/admin/                  (static admin UI shell)
├── public/                     (static: redeem.html, robots.txt, .htaccess)
├── .github/workflows/deploy.yml
├── README.md
├── CLAUDE.md
└── .gitignore
```

## Pattern lineage

Mirrors `affiliates-foundry` (same shape, same conventions, same
gotchas). See that repo's frictions doc + this one's CLAUDE.md.
