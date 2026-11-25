-- DealMachine's property address ("618 Gladstone St"): the county lists often drop the street type (St, Ave, Rd),
-- so the SMS export uses DealMachine's version. '' = looked up, DealMachine had none (stops the backfill retrying).
ALTER TABLE properties
  ADD COLUMN dm_address VARCHAR(190) NULL AFTER dm_property_id,
  ADD COLUMN dm_city VARCHAR(80) NULL AFTER dm_address,
  ADD COLUMN dm_zip VARCHAR(10) NULL AFTER dm_city;
