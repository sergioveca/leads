-- Multi-county support (Macomb first). Every property belongs to a county; skip tracing sends that county's FIPS code
-- to DealMachine. `apn` is the parcel number in DealMachine's format when it differs from our parcel_id
-- (Macomb: parcel_id is the county BSA id "01-13-21-202-024", apn is the local TAX_ID "13-21-202-024").

ALTER TABLE properties
  ADD COLUMN county VARCHAR(20) NOT NULL DEFAULT 'WAYNE' AFTER parcel_id,
  ADD COLUMN apn VARCHAR(40) NULL AFTER county,
  ADD KEY idx_county (county, parcel_synced_at);
