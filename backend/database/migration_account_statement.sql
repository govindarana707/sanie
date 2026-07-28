-- Migration: Add opening_balance to accounts for account statement feature
-- SanIE - Account Statement Feature

-- Add opening_balance column (defaults to 0 for existing accounts)
ALTER TABLE accounts ADD COLUMN opening_balance DECIMAL(15, 2) DEFAULT 0.00 AFTER balance;
