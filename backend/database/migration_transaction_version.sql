-- Phase 6: optimistic concurrency for transaction updates and deletes.
-- Existing transactions start at version 1; every successful update increments it.
ALTER TABLE transactions
    ADD COLUMN version INT NOT NULL DEFAULT 1 AFTER client_request_id;
