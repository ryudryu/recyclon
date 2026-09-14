-- Recyclon migration 003
-- Add the driver auto-accept flag to existing PostgreSQL/Supabase databases.
-- Safe to run more than once.

ALTER TABLE public.users
    ADD COLUMN IF NOT EXISTS auto_accept_bookings boolean NOT NULL DEFAULT false;