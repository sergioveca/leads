# Deployment — leads.4-return.com

## Hosting facts (confirmed 2026-11-24)

| Item | Value |
|---|---|
| Panel | Plesk, customer account (no API access) |
| Server | Windows / IIS, PHP 8.4 (FastCGI), MariaDB 10.11 on localhost:3306, no SSH |
| PHP limits | 60 s per web request, 128 MB memory (jobs run in time-boxed slices) |
| Site folder | `C:\Inetpub\vhosts\vecanet.com\leads.4-return.com` |
| Document root | `leads.4-return.com\public` |
| SSL | Let's Encrypt, auto-renew |

## How code reaches the server (automatic)

Push to `main` → GitHub webhook → Plesk Git pulls (read-only deploy key `leads`) → deployed to `\leads.4-return.com`.
Database migrations in `migrations/` apply automatically on the next request or scheduled run. No PC, SSH or panel login needed.

## One-time setup

1. **Settings file**: Plesk → Files → `leads.4-return.com` → copy `.env.example` to `.env` and fill in the `FILL` values
   (DB password, DealMachine key, first admin password, tokens). `.env` is never in Git and is not web-reachable.
2. **Document root**: Hosting & DNS → Hosting Settings → `leads.4-return.com\public`.
3. **Scheduled task**: Plesk → Scheduled Tasks → Add Task → *Run a PHP script*
   - Script path: `leads.4-return.com\bin\cron.php`  ·  Arguments: `240`  ·  PHP 8.4  ·  every 10 minutes.
   - Fallback if PHP scripts aren't allowed: *Fetch a URL* → `https://leads.4-return.com/cron?token=<CRON_TOKEN>` every 5 minutes.

## What the scheduled run does

Treasurer CSV import → Treasurer page check (daily) → Detroit blight pass (daily, paged) → Detroit parcel owner sync (14-day refresh)
→ scoring (full rescore on the first run of each month) → automatic DealMachine skip trace if enabled (score ≥ setting,
within monthly credit cap; never leads delivered to a client or marked dead/closed) → DNC re-check (daily, free).

## Monitoring

`GET /health?token=<HEALTH_TOKEN>` returns versions, counts and job status as JSON. Logs: `storage/logs/`.

## New Treasurer list (yearly, ~December), automatic

The server can't parse PDFs (no pdftotext or Python on the Plesk host), so GitHub does it:

1. **GitHub Actions → "Treasurer list"** (`.github/workflows/treasurer-list.yml`) runs daily at ~7 a.m. Detroit.
   `tools/fetch_treasurer_list.py` reads the Treasurer page, takes the newest non-archive
   `<year>_wayne_county_delinquent_tax_liens*.pdf` link, and does nothing if that year's CSV is already loaded
   and the PDF hasn't changed (sha256 in `data/treasurer/wayne_forfeited_<year>.source.json`).
2. A new or changed PDF is parsed with `tools/parse_treasurer_pdf.py` and validated (10,000+ parcels, 40%+ Detroit,
   at most 5% without an address; the 2026 list had 37,001 / 85% / 0.2%). If it passes, the bot commits the CSV and
   sidecar to `main`, which deploys it.
3. The next scheduled run on the server imports it (`Treasurer::import`, resumable, ~20k rows per run) and rescores.
   The new year's parcels get "foreclosure pending" (+45) until March 31. Everything is rescored monthly, so on
   April 1 that drops to +25.

**If it fails** (page moved, Akamai blocks the runner, PDF layout changed, validation failed), nothing is committed
and GitHub emails the repo owner. Run it manually from Actions → Treasurer list → *Run workflow*. It can take a `year`,
a direct `pdf_url`, `dry_run` and `force`. Or on a PC with Python and poppler:
`python3 tools/fetch_treasurer_list.py --year 2027`, then commit and push.

The county site is behind Akamai, which returns **403 to browser-like and unknown User-Agents** (including `Leads/1.0`)
but allows tool User-Agents, so both the fetcher and the server's daily `treasurer_watch` send
`curl/8.5.0 Leads/1.0 (+https://leads.4-return.com)`. The dashboard shows "NEW LIST PUBLISHED" while a list is
published but not yet loaded.

## CI

`.github/workflows/ci.yml` runs on every push. It does a PHP 8.4 lint, then runs `tests/smoke.php` against MariaDB 10.11
(migrations, full Treasurer import, scoring, lead pipeline and exclusivity rules). After that, `tests/http_smoke.sh`
signs in to `php -S` and loads every page. A last job dry-runs the fetcher against the live 2026 PDF and checks that it
parses to the committed row count.
