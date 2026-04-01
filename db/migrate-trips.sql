-- Migration: add admin token, map preview, and live tracking fields to trips
-- Run: psql -U bcc -d bcc -f migrate_trips.sql

ALTER TABLE trips ADD COLUMN IF NOT EXISTS token       TEXT UNIQUE;
ALTER TABLE trips ADD COLUMN IF NOT EXISTS map_lat     DOUBLE PRECISION;
ALTER TABLE trips ADD COLUMN IF NOT EXISTS map_lon     DOUBLE PRECISION;
ALTER TABLE trips ADD COLUMN IF NOT EXISTS map_zoom    INT DEFAULT 12;
ALTER TABLE trips ADD COLUMN IF NOT EXISTS tracker_type TEXT;  -- 'spot' | 'inreach' | null
ALTER TABLE trips ADD COLUMN IF NOT EXISTS tracker_id  TEXT;   -- feed URL or share ID
ALTER TABLE trips ADD COLUMN IF NOT EXISTS track_from  TIMESTAMPTZ;
ALTER TABLE trips ADD COLUMN IF NOT EXISTS track_until TIMESTAMPTZ;

-- Back-fill tokens for any existing trips
UPDATE trips SET token = substr(md5(random()::text || id::text), 1, 12) WHERE token IS NULL;

-- Make token NOT NULL after back-fill
ALTER TABLE trips ALTER COLUMN token SET NOT NULL;