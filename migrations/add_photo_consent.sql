-- Add photo consent (yes/no) to registrations. Run on DBs created before this column.
ALTER TABLE registrations
  ADD COLUMN photo_consent VARCHAR(10) DEFAULT NULL AFTER consent_agreed_at;
