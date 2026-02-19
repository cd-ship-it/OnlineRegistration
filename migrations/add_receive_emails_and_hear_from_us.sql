ALTER TABLE `registrations`
  ADD COLUMN `receive_emails` varchar(3) NOT NULL DEFAULT 'yes' AFTER `photo_consent`,
  ADD COLUMN `hear_from_us`   varchar(255) DEFAULT NULL               AFTER `receive_emails`;
