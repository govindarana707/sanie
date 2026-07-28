-- Migration: Add transfer support to transactions
-- Adds from_account_id and to_account_id for double-entry accounting

ALTER TABLE transactions
    ADD COLUMN from_account_id INT NULL AFTER account_id,
    ADD COLUMN to_account_id INT NULL AFTER from_account_id;

ALTER TABLE transactions
    ADD CONSTRAINT fk_tx_from_account FOREIGN KEY (from_account_id) REFERENCES accounts(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_tx_to_account FOREIGN KEY (to_account_id) REFERENCES accounts(id) ON DELETE SET NULL;

-- Backfill existing data: set from/to based on type and account_id
-- Income: money came IN → account_id is the destination (to)
UPDATE transactions SET to_account_id = account_id WHERE type = 'income' AND to_account_id IS NULL;

-- Expense: money went OUT → account_id is the source (from)
UPDATE transactions SET from_account_id = account_id WHERE type = 'expense' AND from_account_id IS NULL;

-- Transfer: existing transfers (if any) — account_id is the source
UPDATE transactions SET from_account_id = account_id WHERE type = 'transfer' AND from_account_id IS NULL;
