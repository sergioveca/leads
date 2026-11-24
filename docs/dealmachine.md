# DealMachine integration (verified 2026-11-24)

Verified against the live API with 4Return's account (plan: **DealMachine Pro Classic**, 30,000 credits / billing month).

## Basics

- Base URL: `https://api.v2.dealmachine.com/v1`
- Auth: `Authorization: Bearer <DEALMACHINE_API_KEY>` (key from DealMachine → Settings → Developer)
- Full spec: `GET /v1/openapi.json` (authenticated); docs at https://api.docs.dealmachine.com
- Rate limits (per organization): **60 req/min, 5,000 req/day**. Headers `X-RateLimit-*`; `429` with `Retry-After` when limited.
- Credits exhausted → `402` with `Retry-After` (billing cycle reset).
- Prospect allowance exceeded → `402` `prospect_limit_reached` (does not reset monthly).

## Endpoints Leads uses

| Purpose | Method + path | Notes |
|---|---|---|
| Credit check before a batch | `GET /v1/usage` | `credits.remaining`, `billing_cycle.end` |
| Skip trace by parcel | `POST /v1/enrichment/apn` | up to 250 per call; `location: {type:"county", code:"26163"}` (Wayne County FIPS) |
| Skip trace by address (fallback) | `POST /v1/enrichment/address` | up to 250 per call; `full_address` or street/city/state/zip |
| Skip trace a person (heirs, LLC officers) | `POST /v1/enrichment/name` | later phase |
| DNC + line type recheck | `POST /v1/phones/dnc` | up to 1,000 numbers per call |
| Push to client's DealMachine | `POST /v1/prospects`, `/v1/tags`, `/v1/lists` | optional per lead |
| Address autocomplete | `GET /v1/addresses/autocomplete?q=` | param is `q` |

Request option `contact_audience`: `owners` (default for Leads) | `owners_and_family` | `renters` | `residents` | `none` (property data only, no people credits).

## Verified behavior (live tests)

| Test | Result |
|---|---|
| Enrich by address, Detroit residential | matched; **2 credits** (1 property + 1 person) |
| Enrich by APN using Detroit parcel format (`16029586.`) with Wayne County FIPS | matched — Detroit parcel ids plug in directly |
| No match | **0 credits** (`match_failure.code = no_match`) |
| Same record again | **0 credits** (`credits.deduplicated`) |

**Budget:** ~1 property credit + 1 per owner returned → 20–30k credits ≈ 7,000–10,000+ traced leads/month.

## Response shape (relevant fields)

Property (top level of each `data[]` item): `matched`, `dm_property_id`, `apn`, `full_address`, `address`, `city`, `state`, `zip`, `latitude`, `longitude`, `estimated_value`, `estimated_equity_amount`, `estimated_equity_percentage`, `total_estimated_loan_balance`, `total_original_loan_amount`, `num_mortgages`, `owner_occupied`, `total_assessed_value`, `annual_property_tax_amount`, `year_built`, `living_area_sqft`, `lot_size_sqft`, `num_bedrooms`, `num_bathrooms`, `contacts[]`.

Contact (`contacts[]`): `dm_person_id`, `first_name`, `last_name`, `full_name`, `contact_type` (`owner`…), `is_resident`, `residence{address,unit,city,state,zip,full_address}`,
`phones[]{number, type (wireless|landline|voip), do_not_call, carrier, match_confidence, usage_2_months, usage_12_months}`,
`emails[]{address, match_confidence, verification_status}`.

Batch totals: `totals{submitted, matched, unmatched}`, `credits{used, properties, people, deduplicated, licensed}`.

DNC check result: `{input{number}, matched, do_not_call, phone_type, carrier}`.
