-- Lead pipeline status, notes timeline, and exclusive delivery to clients (e.g. Home Detroit).
-- 4Return qualifies leads, then delivers them to a client (Podio, email, CSV...). A delivered lead is
-- exclusive to that client until an admin releases it: it leaves 4Return's working list, exports and
-- auto skip trace, but stays visible (read-only) so outcomes can be recorded for scoring.

ALTER TABLE properties
  ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'new' AFTER needs_score,
  ADD COLUMN status_at DATETIME NULL AFTER status,
  ADD COLUMN exclusive_client_id INT UNSIGNED NULL AFTER status_at,
  ADD COLUMN delivered_at DATETIME NULL AFTER exclusive_client_id,
  ADD COLUMN delivery_channel VARCHAR(40) NULL AFTER delivered_at,
  ADD KEY idx_status (status),
  ADD KEY idx_exclusive (exclusive_client_id, delivered_at);

-- Timeline per lead: notes, status changes, deliveries and releases.
CREATE TABLE lead_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  parcel_id VARCHAR(40) NOT NULL,
  user_id INT UNSIGNED NULL,
  client_id INT UNSIGNED NULL,
  kind ENUM('note','status','delivered','released') NOT NULL,
  old_status VARCHAR(20) NULL,
  new_status VARCHAR(20) NULL,
  body TEXT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_parcel (parcel_id, id),
  KEY idx_kind (kind, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
