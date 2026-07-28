-- Migration: Allow NULL category_id for transfer transactions
ALTER TABLE transactions MODIFY COLUMN category_id INT NULL;
ALTER TABLE transactions DROP FOREIGN KEY transactions_ibfk_3;
ALTER TABLE transactions ADD CONSTRAINT transactions_ibfk_3 FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL;
