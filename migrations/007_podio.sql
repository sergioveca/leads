-- Podio push (Home Detroit's Seller Leads app): one row per lead sent, so a phone/property is never pushed twice
-- and sent leads stay out of later SMS exports.
CREATE TABLE IF NOT EXISTS podio_pushes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  parcel_id VARCHAR(40) NOT NULL,
  phone VARCHAR(10) NOT NULL,
  contact_item_id BIGINT NULL,
  seller_item_id BIGINT NULL,
  seller_url VARCHAR(255) NULL,
  warnings TEXT NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_phone (phone),
  KEY idx_parcel (parcel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Which list each number was exported in for SMS (Podio "List Type" shows which lists convert).
CREATE TABLE IF NOT EXISTS sms_export_log (
  phone VARCHAR(10) NOT NULL,
  parcel_id VARCHAR(40) NOT NULL,
  list_name VARCHAR(120) NOT NULL,
  exported_at DATETIME NOT NULL,
  PRIMARY KEY (phone, parcel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- cached Podio app definitions (hundreds of fields) do not fit in TEXT
ALTER TABLE settings MODIFY `value` MEDIUMTEXT NOT NULL;
