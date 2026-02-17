-- Run this if your registrations / registration_kids tables were created before these columns.
-- Add parent fields to registrations
ALTER TABLE registrations ADD COLUMN address TEXT DEFAULT NULL AFTER phone;
ALTER TABLE registrations ADD COLUMN home_church VARCHAR(255) DEFAULT NULL AFTER address;

-- Add per-child fields to registration_kids
ALTER TABLE registration_kids ADD COLUMN date_of_birth DATE DEFAULT NULL AFTER gender;
ALTER TABLE registration_kids ADD COLUMN last_grade_completed VARCHAR(20) DEFAULT NULL AFTER date_of_birth;
ALTER TABLE registration_kids ADD COLUMN emergency_contact_name VARCHAR(100) DEFAULT NULL AFTER last_grade_completed;
ALTER TABLE registration_kids ADD COLUMN emergency_contact_phone VARCHAR(50) DEFAULT NULL AFTER emergency_contact_name;
ALTER TABLE registration_kids ADD COLUMN emergency_contact_relationship VARCHAR(50) DEFAULT NULL AFTER emergency_contact_phone;
