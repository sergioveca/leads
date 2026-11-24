# Lead pipeline and client delivery

4Return generates and qualifies leads, then **delivers** them to a client (first: Home Detroit). Delivery happens
outside the app for now (Podio, email or a CSV), so clients don't need logins. The app records the delivery and
makes the lead exclusive.

## Status (one per lead)

New → Contacted → Appointment → Offer → Under contract → Closed, plus **Dead / not interested**.
After delivery, 4Return keeps updating the status with what the client reports. Those outcomes are what scoring will
be tuned on later.

- Lead page → **Pipeline** card: change the status and/or add a note. Notes, status changes, deliveries and releases
  form a timeline (`lead_events`). Every change is also in the audit log.
- Leads page: tick leads → **Set status** (bulk).
- The Leads list defaults to **Open** (hides Dead and Closed). Use the Status filter to see them.

## Delivery and exclusivity

- Lead page → **Deliver (exclusive)**, or tick leads on the Leads page → **Deliver selected (exclusive)**. Choose the
  client and the channel (Podio / Email / CSV file / Other). Admins only.
- A delivered lead is **exclusive to that client with no expiry**. Only an admin can end it, with **Release
  exclusivity** on the lead page (the reason is logged).
- Exclusive leads:
  - leave 4Return's **Working pool** tab, the dashboard's top leads and auto skip trace;
  - stay visible to admins **read-only** under **Delivered to clients** (and **All**). Skip trace and DealMachine push
    are blocked, but status, notes and opt-outs still work so outcomes and compliance can be recorded;
  - can't be delivered to a second client (skipped, with a count in the message);
  - if a client user is ever created, that client sees the pool plus its own exclusive leads and never another
    client's.
- To hand a batch over as a file: **Delivered to clients** → Client filter → **Export CSV**. The export has `status`,
  `exclusive_client`, `delivered_at` and `delivery_channel` columns; DNC and opted-out numbers are removed as always.
- Dashboard → **Pipeline**: counts per status and per client (total, last 30 days, closed, dead).

## Not built yet

- Pushing leads straight into the client's Podio app (needs their Podio app ID, field mapping and API credentials).
- Using the outcomes to tune scoring weights (needs a few months of Closed/Dead results).
