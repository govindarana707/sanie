-- Phase 5: idempotency for offline-created transactions.
-- Existing rows remain valid because client_request_id is nullable.
ALTER TABLE transactions
    ADD COLUMN client_request_id VARCHAR(64) NULL AFTER karobar_transaction_id,
    ADD UNIQUE KEY uq_transactions_user_client_request (user_id, client_request_id);
