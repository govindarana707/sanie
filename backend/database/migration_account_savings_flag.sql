SET @has_savings_flag = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'accounts' AND COLUMN_NAME = 'include_in_savings'
);
SET @add_savings_flag = IF(
    @has_savings_flag = 0,
    'ALTER TABLE accounts ADD COLUMN include_in_savings TINYINT(1) NOT NULL DEFAULT 0 AFTER is_default',
    'SELECT 1'
);
PREPARE savings_flag_stmt FROM @add_savings_flag;
EXECUTE savings_flag_stmt;
DEALLOCATE PREPARE savings_flag_stmt;

UPDATE accounts SET include_in_savings = 1 WHERE type = 'savings';
