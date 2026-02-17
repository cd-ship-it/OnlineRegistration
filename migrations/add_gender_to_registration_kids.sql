-- Run this if registration_kids was created before the gender column was added.
-- Safe to run: adds column only (ignore error if column already exists on new installs).

ALTER TABLE registration_kids ADD COLUMN gender VARCHAR(10) DEFAULT NULL AFTER age;
