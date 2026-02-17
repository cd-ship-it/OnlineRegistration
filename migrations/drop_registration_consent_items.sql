-- Consent sections are no longer tracked per registration; the UI ensures all sections are checked.
-- Run this to remove the table if it exists (e.g. from a previous add_consent_tracking.sql run).
DROP TABLE IF EXISTS registration_consent_items;
