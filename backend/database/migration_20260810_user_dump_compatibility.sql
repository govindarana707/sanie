-- Compatibility fix for databases created from the August 2026 export.
-- The export data contains these fields even though its original users DDL omitted them.
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS settings JSON NULL AFTER notification_preferences,
    ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER settings,
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;
