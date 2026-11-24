# Leads — leads.4-return.com

Motivated-seller lead engine for **4Return** and its clients. Runs entirely server-side on Plesk (cron jobs), no PC needs to be on.

## Phase 1 scope — Detroit / Wayne County, MI
| Signal | Source | Refresh |
|---|---|---|
| Tax forfeited / facing foreclosure | Wayne County Treasurer published lists | daily check |
| Blight tickets (code violations) | City of Detroit ArcGIS `blight_tickets` FeatureServer | daily |
| Owner / absentee / parcel | City of Detroit ArcGIS `parcel_file_current` | daily |
| Equity, loans, owner-occupied, contacts | DealMachine API (enrichment) | on demand |

Phase 2: sheriff-sale / foreclosure notices, Register of Deeds (lis pendens). Phase 3: probate, evictions, divorce.

## Pipeline
```
ingest (county/city) -> normalize by parcel ID -> score (stacked distress)
  -> skip trace (cache 90d) -> DealMachine /v1/enrichment/apn (fallback /address)
     -> [optional Tracerfy] -> [manual-assist link]
  -> compliance gate (DNC, phone type, opt-outs, 9am–9pm ET)
  -> dashboard / CSV export / push to DealMachine prospects
```

## DealMachine integration (verified 2026-11-24 against live API)
- Base `https://api.v2.dealmachine.com/v1`, header `Authorization: Bearer <key>`.
- `POST /enrichment/apn` — body `{data:[{apn}], location:{type:"county",code:"26163"}, contact_audience:"owners"}`; max 250/request. Detroit parcel numbers (e.g. `16029586.`) match directly.
- `POST /enrichment/address` — fallback, `full_address` or street/city/state/zip.
- Billing: ~1 property credit + 1 per returned person. **No-match = 0 credits. Previously billed records = 0 credits (deduplicated).**
- Returns per person: phones (`number,type,do_not_call,carrier,match_confidence,usage_*`), emails (`address,match_confidence,verification_status`), residence. Property: apn, equity %, loan balance, owner_occupied, assessed value, tax.
- `POST /phones/dnc` — up to 1,000 numbers: `do_not_call`, `phone_type`, `carrier`.
- `GET /usage` — credit guard before each batch.
- Rate limits: 60 req/min, 5,000 req/day; 429 → honor `Retry-After`; 402 → credits exhausted.

## Compliance rules enforced in code (not legal advice — counsel review pending)
- Never export/send to numbers with `do_not_call=true`; re-check DNC every 31 days.
- Contact window 9:00–21:00 America/Detroit (MCL 750.540e).
- Global + per-client opt-out ledger; STOP honored immediately.
- No prerecorded/AI voice or ringless voicemail (MCL 445.111a).
- Each client uses its own DealMachine/DNC credentials; marketing-use-only client terms.
