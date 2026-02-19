-- Add confirmation_email_sent flag to registrations.
-- This enables idempotent email delivery: the column acts as an atomic "claimed"
-- flag so only one caller (success.php or stripe-webhook.php) ever sends the email.

ALTER TABLE `registrations`
  ADD COLUMN `confirmation_email_sent` TINYINT(1) NOT NULL DEFAULT 0
  AFTER `status`;
