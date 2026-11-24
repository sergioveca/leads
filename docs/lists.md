# Lists, motivators and data sources

## Building lists

Leads page → **Motivators** picker. For each motivator choose:

- **Must**: the lead has every one of these;
- **Any of**: the lead has at least one of these;
- **Exclude**: the lead has none of these.

Also available: **Stacked** (2+/3+/4+ distinct distress or life-event situations), **Property** (houses or vacant land),
min score, status, city, ZIP, skip-trace state. **Save list** stores the combination. **Lists** shows every saved list with
its size and how many leads are **new since the last export**; counts refresh hourly. Exporting from a list marks it
exported, so "new" means leads you haven't pulled yet.

Ten starter lists ship with the app (migration 004): stacked distress, mortgage foreclosure in redemption, tax
foreclosure pending (individuals), inherited/estate, tired landlords, fire-damaged, vacant and behind, long-time
absentee owners in trouble, out-of-state owners with distress, demolition list. Edit or delete them freely.

Motivators are defined once in `src/Motivators.php`: a SQL condition for filtering and a PHP test for scoring, chips
and exports. `tests/smoke.php` checks that the two agree.

| Motivator | Source | Score |
|---|---|---|
| Tax foreclosure pending / list / 2+ years | Wayne County Treasurer forfeiture lists | +45 pending, +25 past, +10 multi-year |
| Mortgage foreclosure, in redemption | Detroit assessor sales: `10-FORECLOSURE` by the Wayne County Sheriff in the last 183 days | +40 |
| Open blight tickets | Detroit `blight_tickets` | +15 (+5 3+ tickets, +5 $1k+, +5 recent) |
| Building fire | Detroit `Fire_Incidents`, "Building fire", 3 years | +20 (+5 last 12 months) |
| Demolition list | Detroit `city_demolition_pipeline` + `city_contracted_demolitions` | +25 |
| Registered vacant | Detroit `bseed_vacant_property_registrations` | +15 |
| Rental without compliance cert / landlord | Detroit `bseed_rental_registrations` without a matching `bseed_active_residential_compliance_certificates` | +10 / +5 |
| Inherited (estate transfer) | Detroit assessor sales `07-DEATH CERTIFICATE`, `08-ESTATE` or grantor "…ESTATE…", 3 years | +20 |
| Owner is an estate / heirs | Owner of record reads "ESTATE OF", "HEIRS", "DECEASED", "(ESTATE)" | +15 |
| Absentee, out of state, owned 20+ years, individual / company | Detroit parcel file | +10, +5, +5, company −10 |
| Equity 50%+, free and clear | DealMachine (after skip trace) | +10, +5 |
| 3+ distress situations stacked | (any of the above) | +10 |

Rental registrations and compliance certificates are stored for every Detroit parcel, but they only create a lead
when the parcel has some other distress. Every other source creates a lead.

## Mortgage foreclosure and probate notices (Detroit Legal News)

Mortgage foreclosure notices (pre-sale: owner, address, amount owed, lender, sale date) and probate notices
are published in the **Detroit Legal News** (legalnews.com → Public Notices: notice type, county and date filters,
**Export to Excel**). Its Terms of Use prohibit robots, spiders and automated monitoring or copying without
DLNP's written permission, so Leads does **not** scrape it. Options:

1. Ask DLNP for written permission or a data license or feed (best: automatic and complete).
2. Have someone run the site's own search (Wayne County, Foreclosures and/or Probates, last week) and use **Export
   to Excel**, then upload the file to Leads. An upload importer can be built once there's a sample export.

Until then, the free **sheriff's-deed** signal above covers mortgage foreclosures *after* the sale (the
redemption period), and **estate transfers / estate owners** cover inherited properties.

Not available as bulk public data: 36th District Court evictions and Wayne County divorce filings.
