# Leads — project context for Claude

Motivated-seller lead engine for 4Return (wholesaling) and its clients (first client: Home Detroit, currently buying from motivatedsellers.com). Live at https://leads.4-return.com.

## Stack and hosting
- Plain PHP 8.4 (no framework, no Composer), MariaDB 10.11. Entry points: `public/index.php` (web), `bin/cron.php` (scheduled task).
- Host: Plesk **customer** account on **Windows/IIS** (no SSH, no Plesk API). PHP limits: 60 s web requests, 128 MB.
- Deploy: push to `main` → GitHub webhook → Plesk Git auto-deploys to `leads.4-return.com` (document root `public`). Migrations in `migrations/` apply automatically.
- Server secrets live only in the server `.env` (never in Git). See `.env.example`.
- Scheduled task: Plesk "Run a PHP script" `leads.4-return.com\bin\cron.php 240`, every 10 min.
- Health: `GET /health?token=<HEALTH_TOKEN>` (token is in the server .env; ask the user).

## Data
- Wayne County Treasurer forfeiture list (PDF → `tools/parse_treasurer_pdf.py` → `data/treasurer/wayne_forfeited_<year>.csv`). 2026 list loaded (37,001 parcels). 2027 list expected early Dec 2026. **Automatic**: GitHub Actions "Treasurer list" (daily) runs `tools/fetch_treasurer_list.py`, validates the parse and commits the CSV to main (see `docs/deploy.md`). The county site (Akamai) 403s browser-like and unknown User-Agents; use a `curl/…` UA.
- City of Detroit ArcGIS: `blight_tickets` (aggregated per parcel) and `parcel_file_current` (owner, mailing, tax status).
- DealMachine API (`docs/dealmachine.md`): enrichment by APN (Wayne FIPS 26163) with address fallback; ~2 credits/match, misses and repeats free; DNC check free.

## Decisions so far
- Skip tracing is **manual by default** (migration 002); auto-trace can be enabled in Admin. Monthly cap 15,000 credits.
- Scoring (`src/Scoring.php`): stacked signals; company/trust owners −10; past-year tax list +25, pending foreclosure +45.
- Compliance (`docs/compliance.md`): DNC flags + recheck, global opt-out list, audit log, 9am–9pm ET.
- Admin login: admin@vecanet.com (any password length allowed).
- Lead pipeline (`docs/pipeline.md`, migration 003): one status per lead (New → Contacted → Appointment → Offer → Under contract → Closed, Dead) + notes timeline (`lead_events`). Home Detroit has no login: 4Return qualifies leads and delivers them (Podio/email/CSV). A delivered lead is exclusive to that client **with no expiry** (admin can release). Admins see it read-only (no trace/push, status/notes allowed), and it leaves the working pool, top leads and auto-trace.
- Full rescore on the first cron run each month (scores depend on the date).
- No PHP/Python on the dev PC: CI (`.github/workflows/ci.yml`) is the test bed: MariaDB 10.11 smoke test (`tests/smoke.php`) + HTTP checks (`tests/http_smoke.sh`).

## Next steps agreed/proposed
1. ~~Automate ingest of the 2027 Treasurer list~~ (built 2026-11-24; confirm the first real run in December).
2. ~~Lead status/notes + Home Detroit exclusivity~~ (built 2026-11-24). Next: push delivered leads into Home Detroit's Podio; tune scoring on outcomes.
3. Pre-foreclosure (sheriff sale notices), then probate/evictions.
Housekeeping: rotate the DealMachine API key (was pasted in chat), enable daily DB backup in Plesk.
