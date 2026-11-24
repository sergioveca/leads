-- Saved lists: named combinations of motivators and filters (stored as the Leads page query string).
-- "New" on the Lists page = leads first seen since the list was last exported.

CREATE TABLE saved_lists (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  description VARCHAR(500) NULL,
  query VARCHAR(2000) NOT NULL,
  sort_order INT NOT NULL DEFAULT 100,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  last_exported_at DATETIME NULL,
  last_count INT NULL,
  last_new INT NULL,
  last_counted_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Starter lists (edit or delete in the app). min=0: the motivators do the filtering, not the score.
INSERT INTO saved_lists (name, description, query, sort_order, created_at, updated_at) VALUES
('Stacked distress (3+)', 'Three or more different distress / life-event signals on one property.', 'stack=3&min=0', 10, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
('Mortgage foreclosure, in redemption', 'Sheriff''s sale in the last 6 months: the owner can still sell before redemption ends.', 'all%5B%5D=sheriff_sale&all%5B%5D=individual&min=0', 20, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
('Tax foreclosure pending, individuals', 'On the current Treasurer list (foreclosure March 31), owned by a person, not a company.', 'all%5B%5D=tax_pending&all%5B%5D=individual&min=0', 30, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
('Inherited / estate', 'Recently inherited homes and properties still in a deceased owner''s name.', 'any%5B%5D=inherited&any%5B%5D=estate_owner&none%5B%5D=entity&min=0', 40, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
('Tired landlords', 'Registered rentals with tax, blight, fire or compliance trouble.', 'all%5B%5D=landlord&any%5B%5D=tax&any%5B%5D=blight&any%5B%5D=fire&any%5B%5D=rental_noncompliant&min=0', 50, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
('Fire-damaged', 'Building fire in the last 3 years, owner is a person.', 'all%5B%5D=fire&all%5B%5D=individual&min=0', 60, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
('Vacant and behind', 'Registered vacant with tax or blight debt.', 'all%5B%5D=vacant&any%5B%5D=tax&any%5B%5D=blight&min=0', 70, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
('Long-time absentee owners in trouble', 'Owned 20+ years, tax bill goes elsewhere, and tax or blight debt (high equity, tired owner).', 'all%5B%5D=long_owned&all%5B%5D=absentee&any%5B%5D=tax&any%5B%5D=blight&none%5B%5D=entity&min=0', 80, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
('Out-of-state owners with distress', 'Owner lives outside Michigan and the property has tax or blight debt.', 'all%5B%5D=out_of_state&any%5B%5D=tax&any%5B%5D=blight&min=0', 90, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
('Demolition list', 'Houses the city plans to demolish: owner must act or lose the structure.', 'all%5B%5D=demolition&min=0', 100, UTC_TIMESTAMP(), UTC_TIMESTAMP());
