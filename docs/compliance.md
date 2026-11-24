# Outreach compliance rules enforced by Leads

Not legal advice — have counsel review before scaling. Summary of rules the app is built to enforce:

1. **DNC:** every phone carries a DNC flag from DealMachine; numbers are re-checked at least every **31 days** (`POST /v1/phones/dnc`). DNC numbers are hidden from call exports by default.
2. **Opt-out ledger:** any "do not contact" request is recorded per number/person and suppressed for 4Return and every client.
3. **Calling window (Michigan):** 9:00 a.m.–9:00 p.m. Eastern (MCL 750.540e is stricter than the federal 8 a.m. start).
4. **No prerecorded / AI-voice / ringless voicemail** solicitations (Michigan MCL 445.111a; TCPA).
5. **Texts are treated as calls** for TCPA/DNC purposes (no binding 6th Circuit ruling otherwise).
6. **Marketing use only:** client terms forbid credit, employment, tenant-screening or insurance uses (keeps Leads outside FCRA) and forbid reselling contact data.
7. **Source tagging:** every field records where it came from (county record vs. DealMachine) and when.
8. **Audit:** log which user/client exported or pushed which records, and when.

Damages under the TCPA are $500–$1,500 per call/text — these controls are not optional.
