-- Migration: Credit (Udharo) Management System
-- Adds person type, payment method tracking, and linked transaction IDs
-- Run this on sanie_db

-- 1. Add `type` column to people table
ALTER TABLE people ADD COLUMN type ENUM('person','friend','family','shop','vendor','business','other') DEFAULT 'person' AFTER name;
ALTER TABLE people ADD INDEX idx_type (type);

-- 2. Add linked transaction IDs to karobar_transactions
ALTER TABLE karobar_transactions ADD COLUMN expense_transaction_id INT NULL AFTER account_id;
ALTER TABLE karobar_transactions ADD COLUMN income_transaction_id INT NULL AFTER expense_transaction_id;
ALTER TABLE karobar_transactions ADD COLUMN payment_method VARCHAR(30) NULL AFTER income_transaction_id;
ALTER TABLE karobar_transactions ADD FOREIGN KEY (expense_transaction_id) REFERENCES transactions(id) ON DELETE SET NULL;
ALTER TABLE karobar_transactions ADD FOREIGN KEY (income_transaction_id) REFERENCES transactions(id) ON DELETE SET NULL;

-- 3. Add `payment_method` to transactions table to track how expense was paid
ALTER TABLE transactions ADD COLUMN payment_method VARCHAR(30) DEFAULT NULL AFTER type;
ALTER TABLE transactions ADD COLUMN karobar_transaction_id INT NULL AFTER payment_method;
ALTER TABLE transactions ADD FOREIGN KEY (karobar_transaction_id) REFERENCES karobar_transactions(id) ON DELETE SET NULL;
