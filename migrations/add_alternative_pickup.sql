-- Add optional Alternative Pick Up contact to registrations.
ALTER TABLE registrations
  ADD COLUMN alternative_pickup_name VARCHAR(100) DEFAULT NULL AFTER home_church,
  ADD COLUMN alternative_pickup_phone VARCHAR(50) DEFAULT NULL AFTER alternative_pickup_name;
