-- Run this if registrations table was created before emergency contact fields at registration level.
ALTER TABLE registrations ADD COLUMN emergency_contact_name VARCHAR(100) DEFAULT NULL AFTER home_church;
ALTER TABLE registrations ADD COLUMN emergency_contact_phone VARCHAR(50) DEFAULT NULL AFTER emergency_contact_name;
ALTER TABLE registrations ADD COLUMN emergency_contact_relationship VARCHAR(50) DEFAULT NULL AFTER emergency_contact_phone;
