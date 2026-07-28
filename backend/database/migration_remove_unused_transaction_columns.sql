-- Migration: Remove unused transaction columns
-- SanIE - Remove Notes, Receipt, Tags, and other unused fields
-- Run this SQL against the sanie_db database
--
-- Columns being dropped:
--   time              - not used (only date is displayed)
--   notes             - removed per design decision
--   tags              - removed per design decision
--   is_recurring      - feature not implemented
--   recurring_transaction_id - feature not implemented
--   is_favorite       - feature not implemented
--   location_lat      - feature not implemented
--   location_lng      - feature not implemented
--   location_address  - feature not implemented
--   receipt_path      - removed per design decision
--   voice_note_path   - feature not implemented

USE sanie_db;

ALTER TABLE transactions
    DROP COLUMN IF EXISTS time,
    DROP COLUMN IF EXISTS notes,
    DROP COLUMN IF EXISTS tags,
    DROP COLUMN IF EXISTS is_recurring,
    DROP COLUMN IF EXISTS recurring_transaction_id,
    DROP COLUMN IF EXISTS is_favorite,
    DROP COLUMN IF EXISTS location_lat,
    DROP COLUMN IF EXISTS location_lng,
    DROP COLUMN IF EXISTS location_address,
    DROP COLUMN IF EXISTS receipt_path,
    DROP COLUMN IF EXISTS voice_note_path;
