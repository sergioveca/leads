-- Leads schema v1 (MariaDB 10.11)

CREATE TABLE settings (
  `key` VARCHAR(100) PRIMARY KEY,
  `value` TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE clients (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE,
  dm_api_key_enc TEXT NULL,
  monthly_credit_cap INT UNSIGNED NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','client') NOT NULL DEFAULT 'client',
  client_id INT UNSIGNED NULL,
  failed_logins INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  CONSTRAINT fk_users_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per parcel that has at least one distress signal.
CREATE TABLE properties (
  parcel_id VARCHAR(40) PRIMARY KEY,
  municipality VARCHAR(80) NOT NULL DEFAULT '',
  address VARCHAR(190) NOT NULL DEFAULT '',
  zip VARCHAR(10) NULL,
  owner1 VARCHAR(190) NULL,
  owner2 VARCHAR(190) NULL,
  mail_address VARCHAR(190) NULL,
  mail_city VARCHAR(80) NULL,
  mail_state VARCHAR(20) NULL,
  mail_zip VARCHAR(12) NULL,
  property_class VARCHAR(80) NULL,
  tax_status VARCHAR(120) NULL,
  assessed_value INT NULL,
  taxable_value INT NULL,
  year_built SMALLINT NULL,
  building_sqft INT NULL,
  sale_date DATE NULL,
  sale_price INT NULL,
  is_absentee TINYINT(1) NULL,
  is_out_of_state TINYINT(1) NULL,
  is_entity TINYINT(1) NULL,
  is_excluded TINYINT(1) NOT NULL DEFAULT 0,
  exclude_reason VARCHAR(120) NULL,
  parcel_synced_at DATETIME NULL,
  -- DealMachine property data
  dm_property_id VARCHAR(40) NULL,
  dm_estimated_value INT NULL,
  dm_equity_amount INT NULL,
  dm_equity_pct DECIMAL(6,2) NULL,
  dm_loan_balance INT NULL,
  dm_owner_occupied TINYINT(1) NULL,
  dm_beds DECIMAL(4,1) NULL,
  dm_baths DECIMAL(4,1) NULL,
  dm_sqft INT NULL,
  dm_year_built SMALLINT NULL,
  dm_annual_tax INT NULL,
  dm_matched TINYINT(1) NULL,
  traced_at DATETIME NULL,
  traced_by_client_id INT UNSIGNED NULL,
  pushed_to_dm_at DATETIME NULL,
  -- scoring
  score TINYINT UNSIGNED NOT NULL DEFAULT 0,
  score_reasons VARCHAR(500) NULL,
  needs_score TINYINT(1) NOT NULL DEFAULT 1,
  first_seen_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY idx_score (is_excluded, score),
  KEY idx_needs_score (needs_score),
  KEY idx_sync (parcel_synced_at),
  KEY idx_traced (traced_at),
  KEY idx_muni (municipality)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Distress signals per parcel. Unique on (parcel, type, source_ref) so re-imports update in place.
CREATE TABLE signals (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  parcel_id VARCHAR(40) NOT NULL,
  type VARCHAR(40) NOT NULL,
  source VARCHAR(60) NOT NULL,
  source_ref VARCHAR(80) NOT NULL DEFAULT '',
  amount DECIMAL(12,2) NULL,
  item_count INT NULL,
  event_date DATE NULL,
  details TEXT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  first_seen_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  UNIQUE KEY uq_signal (parcel_id, type, source_ref),
  KEY idx_type (type, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE contacts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  parcel_id VARCHAR(40) NOT NULL,
  dm_person_id VARCHAR(40) NULL,
  full_name VARCHAR(190) NULL,
  first_name VARCHAR(80) NULL,
  last_name VARCHAR(80) NULL,
  contact_type VARCHAR(30) NULL,
  is_resident TINYINT(1) NULL,
  res_address VARCHAR(190) NULL,
  res_city VARCHAR(80) NULL,
  res_state VARCHAR(20) NULL,
  res_zip VARCHAR(12) NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_contact (parcel_id, dm_person_id),
  KEY idx_parcel (parcel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE phones (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  contact_id BIGINT UNSIGNED NOT NULL,
  number VARCHAR(20) NOT NULL,
  phone_type VARCHAR(20) NULL,
  carrier VARCHAR(80) NULL,
  is_dnc TINYINT(1) NULL,
  confidence DECIMAL(6,3) NULL,
  usage_2m VARCHAR(20) NULL,
  usage_12m VARCHAR(20) NULL,
  dnc_checked_at DATETIME NULL,
  UNIQUE KEY uq_phone (contact_id, number),
  KEY idx_number (number),
  KEY idx_dnc_checked (dnc_checked_at),
  CONSTRAINT fk_phone_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE emails (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  contact_id BIGINT UNSIGNED NOT NULL,
  address VARCHAR(190) NOT NULL,
  confidence DECIMAL(6,3) NULL,
  verification VARCHAR(40) NULL,
  UNIQUE KEY uq_email (contact_id, address),
  CONSTRAINT fk_email_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Do-not-contact requests (phone digits or lowercase email). Suppressed for everyone.
CREATE TABLE opt_outs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  value VARCHAR(190) NOT NULL UNIQUE,
  kind ENUM('phone','email') NOT NULL,
  note VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE skiptrace_runs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  trigger_type VARCHAR(20) NOT NULL,
  submitted INT NOT NULL DEFAULT 0,
  matched INT NOT NULL DEFAULT 0,
  credits_used INT NOT NULL DEFAULT 0,
  message VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE job_state (
  job VARCHAR(60) PRIMARY KEY,
  cursor_value VARCHAR(255) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'idle',
  last_started_at DATETIME NULL,
  last_finished_at DATETIME NULL,
  last_success_at DATETIME NULL,
  rows_last_run INT NOT NULL DEFAULT 0,
  message VARCHAR(500) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE audit_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  client_id INT UNSIGNED NULL,
  action VARCHAR(60) NOT NULL,
  details VARCHAR(1000) NULL,
  ip VARCHAR(64) NULL,
  created_at DATETIME NOT NULL,
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO clients (name, created_at) VALUES ('4Return', UTC_TIMESTAMP()), ('Home Detroit', UTC_TIMESTAMP());
INSERT INTO settings (`key`, `value`) VALUES ('trace_min_score', '50'), ('monthly_credit_cap', '15000'), ('auto_trace', '1'), ('auto_trace_batch', '250');
