-- Backward-compatible account Net Balance inclusion preference.
-- Existing accounts receive ON so their previous Net Balance behavior is preserved.
SET @has_net_balance_flag = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'accounts' AND COLUMN_NAME = 'include_in_net_balance'
);
SET @add_net_balance_flag = IF(
    @has_net_balance_flag = 0,
    'ALTER TABLE accounts ADD COLUMN include_in_net_balance TINYINT(1) NOT NULL DEFAULT 1 AFTER include_in_savings',
    'SELECT 1'
);
PREPARE net_balance_flag_stmt FROM @add_net_balance_flag;
EXECUTE net_balance_flag_stmt;
DEALLOCATE PREPARE net_balance_flag_stmt;
