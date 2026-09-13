-- Recyclon migration 001
-- Apply once to a legacy database before deploying this checkout.
-- Do not run this file repeatedly after the columns exist.

ALTER TABLE users
    MODIFY role ENUM('Admin','Staff','Driver','Customer') DEFAULT NULL;

ALTER TABLE users
    ADD COLUMN auto_accept_bookings TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE booking
    ADD COLUMN booking_type ENUM('pickup','walkin') NOT NULL DEFAULT 'pickup' AFTER status;
