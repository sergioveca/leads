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
→ scoring → automatic DealMachine skip trace (score ≥ setting, within monthly credit cap) → DNC re-check (daily, free).

## Monitoring

`GET /health?token=<HEALTH_TOKEN>` returns versions, counts and job status as JSON. Logs: `storage/logs/`.

## New Treasurer list (yearly, ~December)

Run `python3 tools/parse_treasurer_pdf.py <pdf> <year> data/treasurer/wayne_forfeited_<year>.csv`, commit, push.
The app flags "NEW LIST PUBLISHED" on the dashboard job list when it detects one.
