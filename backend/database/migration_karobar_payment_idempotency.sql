-- Phase 1: idempotency for debt repayment and receivable collection.
-- Repeat-safe for databases where either the column or index already exists.

SET @phase1_has_column = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'karobar_transactions'
      AND COLUMN_NAME = 'client_request_id'
);
SET @phase1_column_sql = IF(
    @phase1_has_column = 0,
    'ALTER TABLE karobar_transactions ADD COLUMN client_request_id VARCHAR(64) NULL AFTER payment_method',
    'SELECT 1'
);
PREPARE phase1_column_stmt FROM @phase1_column_sql;
EXECUTE phase1_column_stmt;
DEALLOCATE PREPARE phase1_column_stmt;

SET @phase1_has_unique = (
    SELECT COUNT(*) FROM (
        SELECT INDEX_NAME
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'karobar_transactions'
          AND NON_UNIQUE = 0
        GROUP BY INDEX_NAME
        HAVING GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) = 'user_id,client_request_id'
    ) phase1_unique_indexes
);
SET @phase1_index_sql = IF(
    @phase1_has_unique = 0,
    'ALTER TABLE karobar_transactions ADD UNIQUE KEY uq_karobar_user_client_request (user_id, client_request_id)',
    'SELECT 1'
);
PREPARE phase1_index_stmt FROM @phase1_index_sql;
EXECUTE phase1_index_stmt;
DEALLOCATE PREPARE phase1_index_stmt;
