-- Store digital signature and consent timestamp on registration; track which consent sections were agreed to.
-- Run this only on databases created before consent tracking was added (skip if you use a recent schema.sql).

-- Add consent fields to registrations
ALTER TABLE registrations
  ADD COLUMN digital_signature VARCHAR(200) DEFAULT NULL AFTER consent_accepted,
  ADD COLUMN consent_agreed_at DATETIME DEFAULT NULL AFTER digital_signature;

-- Table: which consent items (sections) were checked per registration
CREATE TABLE IF NOT EXISTS registration_consent_items (
  registration_id INT UNSIGNED NOT NULL,
  consent_slug VARCHAR(64) NOT NULL,
  agreed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (registration_id, consent_slug),
  CONSTRAINT fk_consent_items_registration FOREIGN KEY (registration_id) REFERENCES registrations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
