# Leads — leads.4-return.com

Motivated-seller lead engine for **4Return** and its clients. Leads pulls **real county and city records** on a schedule, stacks distress signals per parcel, scores them, skip traces the best ones through **DealMachine**, and serves them in a web dashboard with CSV export and a "Send to DealMachine" action.

It runs entirely server-side on our Plesk server (web app + scheduled tasks), so no PC has to stay on.

> Status: **v1 in progress** — first market: **Detroit / Wayne County, Michigan**.

---

## How it works

```
 Real public records (scheduled pulls)
 ├─ Wayne County Treasurer — tax-forfeited / facing-foreclosure parcels
 ├─ City of Detroit — blight violation tickets (daily, ArcGIS API)
 └─ City of Detroit — assessor parcels: owner, mailing address (daily, ArcGIS API)
                │
                ▼
 Normalize by parcel number (Detroit parcel id, e.g. "16029586.")
                │
                ▼
 Score: stack signals → tax forfeiture + blight + absentee owner + equity …
                │
                ▼
 Skip trace leads above threshold → DealMachine API
   (by APN, fallback by address; misses and repeats cost 0 credits)
   → owners, phones (type / carrier / DNC / confidence), emails,
     plus property data: value, equity %, loan balance, owner-occupied, beds/baths
                │
                ▼
 Compliance layer: DNC flags + 31-day recheck, opt-out ledger, 9am–9pm ET call window
                │
                ▼
 Dashboard (4Return + client logins) → filters, lead detail, CSV export,
 "Send to DealMachine" (adds prospects with Leads tags)
```

## Data sources (v1)

| Signal | Source | Access | Refresh |
|---|---|---|---|
| Tax forfeiture / foreclosure | Wayne County Treasurer (waynecountymi.gov) | Public files | Weekly; daily in Dec–Mar season |
| Blight / code violations | City of Detroit `blight_tickets` FeatureServer (services2.arcgis.com) | Public ArcGIS REST | Daily |
| Owner + mailing address, absentee | City of Detroit `parcel_file_current` FeatureServer | Public ArcGIS REST | Daily |
| Phones, emails, equity, value | DealMachine API (`api.v2.dealmachine.com/v1`) | API key (paid plan) | On demand, cached |

Planned: sheriff sale / mortgage foreclosure notices, Register of Deeds (lis pendens), probate, 36th District evictions, suburban Wayne County.

See [`docs/dealmachine.md`](docs/dealmachine.md) for the verified API integration notes and [`docs/compliance.md`](docs/compliance.md) for the outreach rules the app enforces.

## Tenancy

- **4Return** (admin) sees everything and manages clients.
- **Clients** (e.g. Home Detroit) get their own login and lead views. Each client may use **their own DealMachine API key**; otherwise leads are traced on 4Return's key. Keys are stored encrypted, never in the repo.

## Configuration

Copy `.env.example` to `.env` on the server and fill it in. **Never commit `.env` or any API key.**

## Deployment

Target: Plesk subdomain `leads.4-return.com`, Let's Encrypt SSL, deployed from this repo via the Plesk Git extension, scheduled tasks via Plesk cron. Exact stack and steps: [`docs/deploy.md`](docs/deploy.md) (written once the server inventory is confirmed).
