# New Podio Seller (Home Detroit)

A positive SMS reply becomes a Contact + Seller Lead in Home Detroit's Podio (org `detroitprogresscom`, workspace DPGR CRM),
filled with what Leads knows about the property, after a human review.

## Flow
1. sms.4-return.com posts the reply to Slack `#hd-acq-replies` (C0CLUSJJP7Y) with a **New Podio Seller** button:
   `https://leads.4-return.com/podio/new?phone=+13137197372&address=15517%20Lauder%20St…`
2. The review page (admin login) matches the phone to traced leads (owner with several properties: pick one), pulls the
   SMS thread from the SMS app's read-only bridge, and pre-fills every field. Everything is editable.
3. **Create in Podio**: finds the Contact by phone (Leads' own record first, then Podio search) or creates it
   (Complete Name, Phone Number as mobile, Contact Type = Seller, Address), creates the Seller Lead linked to it, and posts
   the seller's replies as a comment (`Reply: "…"`).
4. Leads records the push (`podio_pushes`), sets the lead to Contacted with the Podio link as a note, and the number
   leaves future SMS exports. It tells the SMS app (`POST /api/bridge/podio-created`) so the Slack thread gets the link.

Also reachable from a lead's page: **Send to Podio** next to each mobile number.

## Seller Lead fields filled
Seller Contact, Lead Manager (Tom ACQ), Lead Source (4Return - County Leads APP), Lead Status (#New Untouched#),
Property Address Map, Date Lead Came In (time of the last reply, Miami time), Bedroom/Bathroom count, SqFt, Property Type,
Years Owned (last sale), owner/tenant/vacant, Property Paid off?, Mortgage Amount, Property Value (TotalView) (DealMachine
estimate, so Podio's Avg Value and Quick Offer Range calculate), County, ++APN, List Type (the list the number was exported
in), Motivation (motivators + why this lead), Miscellaneous Notes (value/equity/loan, back taxes, last sale, owner of record,
Leads link).

Fields are matched by **label**, values converted by field type (category: by option text; contact: workspace member by
name; app: referenced item by title). Anything that cannot be filled is listed on the result page, never guessed.
Calculated fields are never written. `/podio/check` (admin) shows each field Leads fills, its Podio type and options,
and Podio fields that are required but not filled.

## Settings (server .env)
`PODIO_CLIENT_ID`, `PODIO_CLIENT_SECRET` (podio.com/settings/api, app "4Return Leads", domain leads.4-return.com),
`PODIO_USERNAME`, `PODIO_PASSWORD` (the user the items are created as; email + password sign-in only, no SSO).
Optional: `PODIO_SELLER_APP`, `PODIO_CONTACTS_APP` (`org/space/app` from the Podio URL, or app ids), `PODIO_LEAD_MANAGER`,
`PODIO_LEAD_SOURCE`, `PODIO_LEAD_STATUS`. SMS thread: `SMS_APP_URL`, `SMS_HISTORY_BRIDGE_TOKEN` (same value as the SMS app's).
The Podio token is cached encrypted in `settings` (`podio_token`); app field lists for 6 hours (`podio_app_*`).

No exclusivity: a lead sent to Podio stays in the 4Return pool (status Contacted), it is only kept out of SMS exports.
