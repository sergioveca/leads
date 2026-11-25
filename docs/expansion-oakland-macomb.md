# Expanding Leads to Macomb and Oakland counties (research, 2026-11-24)

> **Status:** Macomb is built (v1.5.0): notice parser + daily fetch, county column, owner sync from the county parcel layer,
> per-county DealMachine FIPS, County filter, "Owes $5k+ in back taxes" motivator (+5). Oakland: not started.

## Summary

| | Macomb (FIPS 26099) | Oakland (FIPS 26125) |
|---|---|---|
| Owner / parcel data | **Free official layer**: 332,993 parcels with owner name, property address, SEV, taxable value; updated Oct 2026. No owner *mailing* address. | Free layer has parcel shapes + IDs only (**no owner**). Owner data is paid (Property Gateway reports, Access Oakland business account, resellers ~$300/county, unverified). |
| Tax foreclosure list | **County posts the newspaper notice as a text PDF** ("Notice of properties subject to foreclosure", Macomb Daily supplement, December). 2024 cycle: **2,537 parcels**, each with **amount owed**, address and interested parties. | Published in a newspaper around Dec 1 (county timeline), likely Oakland County Legal News (DLNP, no robots). No county-hosted copy found. Ask the Treasurer / FOIA for the list as a file. |
| Mortgage foreclosure | Notices in the legal news (DLNP, no robots). Sheriff's deeds are recorded at the Register of Deeds, which has no free bulk feed. | Same. |
| Code violations / blight | No countywide data; none found for Warren, Sterling Heights etc. | None found (Pontiac, Southfield…). |
| Skip trace | DealMachine by APN with FIPS 26099, address fallback. | FIPS 26125, address fallback. |
| Effort | ~1–2 days: PDF parser + importer + parcel owner sync. | Blocked on getting the list (request) and, optionally, owner data. |
| Expected leads | ~2,500 tax-foreclosure leads/year with dollar amounts; scoring mostly tax + amount owed + absentee (after trace). | Unknown volume (Oakland reported 400+ foreclosed parcels in 2022, so likely low thousands at the forfeiture stage). |

**Recommendation:** Macomb first, timed for its December notice (properties foreclosing March 31, 2027). Oakland after we have the
forfeiture list as a file from the Treasurer. For Oakland we don't need to buy owner data, because the forfeiture notice names
the owners and DealMachine fills in the rest on skip trace.

Neither county has Detroit's signal depth (blight, fire, vacancy, rental, demolition, sheriff deeds), so their leads will
score lower and stack less. They are mainly **tax-foreclosure lists**, but good ones, because they include the amount owed.

## Details

### Macomb
- Parcel layer: `https://services6.arcgis.com/K0qS4r8AEJxrE8em/arcgis/rest/services/Macomb_County_Parcel_Data/FeatureServer/10`
  fields `TAX_ID` (e.g. `02-24-378-004`), `BSA_ID` (`25-02-24-378-004`), `ADDRESS`, `CVT_NAME` (city/township),
  `propertyCityStateZip`, `ownername1`, `zoning`, `mborsev_0` (SEV), `mbortax` (taxable). Verified 2026-11-24, count 332,993.
- Foreclosure notice PDF (2024 cycle): `macombgov.org/sites/default/files/files/2024-01/SupplementtotheMacombDailyforDecember07142123.pdf`.
  `pdftotext -raw` gives blocks: `Parcel ID#: 01-13-21-430-007`, `Amount to Redeem as of 12/01/2023: 651.16`,
  `Property Address: …`, interested parties, legal description. Amounts: median $3,065, top 10% over $7,879, $11.2M total.
- Timeline (county page): 2024 taxes forfeit March 2, 2026; forfeited parcels published in the newspaper December 2026;
  foreclosure March 31, 2027.
- Treasurer: 1 S. Main St, Mt. Clemens, (586) 469-5190.

### Oakland
- Free layer `gisservices.oakgov.com/arcgisds/rest/services/Hosted/Tax_Parcel_2025/FeatureServer/0`: `keypin`, acres, no owner.
- Owner data: Property Gateway (fee per report), Access Oakland business account; resellers (ReportAll, Regrid, LandRecords).
- Treasurer: 1200 N. Telegraph Rd, Pontiac, (248) 858-0611, treasurer@oakgov.com. Ask for the current list of
  forfeited parcels subject to foreclosure (MCL 211.78e list / petition list) as Excel/CSV.

### Already in Leads: Wayne County suburbs
The Wayne Treasurer list already includes ~5,500 non-Detroit parcels, which have no owner/mailing data in Leads (the parcel sync
is Detroit-only). They can only be reached through skip trace today. Worth a look before or alongside Macomb.
