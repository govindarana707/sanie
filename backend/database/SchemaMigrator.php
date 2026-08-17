<?php

require_once __DIR__ . '/../config/database.php';

/**
 * Reconciles the documented August 10, 2026 schema baseline with the schema
 * required by the current application. Every operation is state-aware so an
 * interrupted run can be retried without recreating tables or deleting data.
 */
class SchemaMigrator {
    public const MIGRATION_ID = '20260815_phase2_schema_reconciliation_v1';
    public const CHECKSUM = 'b13dba48443348318766447209ea0759ce07123b2124e31478b9f0a3ba0ba627';
    public const PHASE3_MIGRATION_ID = '20260815_phase3_transfer_integrity_v1';
    public const PHASE3_CHECKSUM = 'e71296715749793e627f9676564249bf260621435c65eb26dd6fc903effdc08f';
    public const PHASE4_MIGRATION_ID = '20260817_phase4_goal_contribution_integrity_v1';
    public const PHASE4_CHECKSUM = '04f4a2515038bc68e8bfaad14b932f767be987b44c5dd8e19143288022f7e7f1';
    public const PHASE5_MIGRATION_ID = '20260817_phase5_credit_purchase_integrity_v1';
    public const PHASE5_CHECKSUM = 'a6db5f13cc13b6fe6ec5eb7bbdc448fcaf96533ec84fd06f0804bb1037fd6761';
    public const PHASE8_MIGRATION_ID = '20260817_phase8_person_history_protection_v1';
    public const PHASE8_CHECKSUM = '1a87a27230967a98786126a3af46012c376721ea5acc4eb595b067a3f8a4045b';
    public const PHASE9_MIGRATION_ID = '20260817_phase9_budget_classification_integrity_v1';
    public const PHASE9_CHECKSUM = 'b86760fe32d0ccb5dfde2a81a94596a32406ed24fe87c1a2efcb1cab61566c1d';
    public const PHASE10_MIGRATION_ID = '20260817_phase10_report_ledger_pagination_v1';
    public const PHASE10_CHECKSUM = '55f3a6ce4980ac7cac90831446685f8beb3da19ab878df0c31ba479141096f33';
    public const PHASE11_MIGRATION_ID = '20260817_phase11_credential_session_version_v1';
    public const PHASE11_CHECKSUM = 'acb822709a4c2f5092c146178284ee850d6403cb508541912af11a375a261f61';

    private PDO $conn;
    private string $databaseName;

    public function __construct(?PDO $connection = null) {
        $resolvedConnection = $connection ?: (new Database())->getConnection();
        if (!$resolvedConnection) throw new RuntimeException('Database connection unavailable.');
        $this->conn = $resolvedConnection;
        $this->databaseName = (string)$this->conn->query('SELECT DATABASE()')->fetchColumn();
        if ($this->databaseName === '') throw new RuntimeException('No database is selected.');
    }

    public function run(): array {
        $this->createMigrationHistory();
        $this->assertSupportedBaseline();

        $applied = false;
        $record = $this->migrationRecord(self::MIGRATION_ID);
        if ($record) {
            if (!hash_equals(self::CHECKSUM, (string)$record['checksum'])) {
                throw new RuntimeException('Applied Phase 2 migration checksum does not match this release.');
            }
        } else {
            $this->reconcileColumns();
            $this->reconcileIndexes();
            $this->reconcileForeignKeys();
            $this->backfillDerivedColumns();
            $this->recordMigration(self::MIGRATION_ID, self::CHECKSUM);
            $applied = true;
        }

        $phase3 = $this->migrationRecord(self::PHASE3_MIGRATION_ID);
        if ($phase3) {
            if (!hash_equals(self::PHASE3_CHECKSUM, (string)$phase3['checksum'])) {
                throw new RuntimeException('Applied Phase 3 migration checksum does not match this release.');
            }
        } else {
            $this->reconcileTransferIntegrity();
            $this->recordMigration(self::PHASE3_MIGRATION_ID, self::PHASE3_CHECKSUM);
            $applied = true;
        }

        $phase4 = $this->migrationRecord(self::PHASE4_MIGRATION_ID);
        if ($phase4) {
            if (!hash_equals(self::PHASE4_CHECKSUM, (string)$phase4['checksum'])) {
                throw new RuntimeException('Applied Phase 4 migration checksum does not match this release.');
            }
        } else {
            $this->reconcileGoalContributionIntegrity();
            $this->recordMigration(self::PHASE4_MIGRATION_ID, self::PHASE4_CHECKSUM);
            $applied = true;
        }

        $phase5 = $this->migrationRecord(self::PHASE5_MIGRATION_ID);
        if ($phase5) {
            if (!hash_equals(self::PHASE5_CHECKSUM, (string)$phase5['checksum'])) {
                throw new RuntimeException('Applied Phase 5 migration checksum does not match this release.');
            }
        } else {
            $this->reconcileCreditPurchaseIntegrity();
            $this->recordMigration(self::PHASE5_MIGRATION_ID, self::PHASE5_CHECKSUM);
            $applied = true;
        }

        $phase8 = $this->migrationRecord(self::PHASE8_MIGRATION_ID);
        if ($phase8) {
            if (!hash_equals(self::PHASE8_CHECKSUM, (string)$phase8['checksum'])) {
                throw new RuntimeException('Applied Phase 8 migration checksum does not match this release.');
            }
        } else {
            $this->reconcilePersonHistoryProtection();
            $this->recordMigration(self::PHASE8_MIGRATION_ID, self::PHASE8_CHECKSUM);
            $applied = true;
        }

        $phase9 = $this->migrationRecord(self::PHASE9_MIGRATION_ID);
        if ($phase9) {
            if (!hash_equals(self::PHASE9_CHECKSUM, (string)$phase9['checksum'])) {
                throw new RuntimeException('Applied Phase 9 migration checksum does not match this release.');
            }
        } else {
            $this->reconcileBudgetClassificationIntegrity();
            $this->recordMigration(self::PHASE9_MIGRATION_ID, self::PHASE9_CHECKSUM);
            $applied = true;
        }

        $phase10 = $this->migrationRecord(self::PHASE10_MIGRATION_ID);
        if ($phase10) {
            if (!hash_equals(self::PHASE10_CHECKSUM, (string)$phase10['checksum'])) {
                throw new RuntimeException('Applied Phase 10 migration checksum does not match this release.');
            }
        } else {
            $this->reconcileReportLedgerPagination();
            $this->recordMigration(self::PHASE10_MIGRATION_ID, self::PHASE10_CHECKSUM);
            $applied = true;
        }

        $phase11 = $this->migrationRecord(self::PHASE11_MIGRATION_ID);
        if ($phase11) {
            if (!hash_equals(self::PHASE11_CHECKSUM, (string)$phase11['checksum'])) {
                throw new RuntimeException('Applied Phase 11 migration checksum does not match this release.');
            }
        } else {
            $this->reconcileCredentialSessionVersion();
            $this->recordMigration(self::PHASE11_MIGRATION_ID, self::PHASE11_CHECKSUM);
            $applied = true;
        }

        $this->assertCurrentApplicationSchema();
        return ['applied' => $applied, 'migration_id' => self::PHASE11_MIGRATION_ID];
    }

    private function recordMigration(string $id, string $checksum): void {
        $stmt = $this->conn->prepare(
            'INSERT INTO schema_migrations (migration_id, checksum) VALUES (:migration_id, :checksum)'
        );
        $stmt->execute([':migration_id' => $id, ':checksum' => $checksum]);
    }

    private function reconcileTransferIntegrity(): void {
        $this->addColumn('transactions', 'transfer_parent_id', 'INT NULL AFTER client_request_id');
        $this->addUniqueIndex('transactions', 'uq_transactions_transfer_fee', ['user_id', 'transfer_parent_id']);
        $this->addForeignKey('transactions', 'transfer_parent_id', 'transactions', 'id', 'CASCADE', 'fk_transactions_transfer_parent');
    }

    private function reconcileGoalContributionIntegrity(): void {
        $this->addColumn('goals', 'initial_amount', 'DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER target_amount');
        $this->addColumn('goals', 'version', 'INT NOT NULL DEFAULT 1 AFTER current_amount');
        $this->conn->exec('UPDATE goals SET initial_amount = current_amount');
        $this->addColumn('transactions', 'goal_id', 'INT NULL AFTER transfer_parent_id');
        $this->ensureGoalContributionType();
        $this->addIndex('transactions', 'idx_goal_id', ['goal_id']);
        $this->addForeignKey('transactions', 'goal_id', 'goals', 'id', 'RESTRICT', 'fk_transactions_goal');
    }

    private function reconcileCreditPurchaseIntegrity(): void {
        $this->addColumn('karobar_transactions', 'version', 'INT NOT NULL DEFAULT 1 AFTER client_request_id');
        $this->addUniqueIndex('transactions', 'uq_transactions_user_karobar', ['user_id', 'karobar_transaction_id']);
        $this->addUniqueIndex('karobar_transactions', 'uq_karobar_user_expense_tx', ['user_id', 'expense_transaction_id']);
    }

    private function reconcilePersonHistoryProtection(): void {
        $this->ensureForeignKeyDeleteRule(
            'karobar_transactions', 'person_id', 'people', 'id', 'RESTRICT', 'fk_karobar_person'
        );
    }

    private function reconcileBudgetClassificationIntegrity(): void {
        $this->ensureForeignKeyDeleteRule(
            'budgets', 'category_id', 'categories', 'id', 'RESTRICT', 'fk_budgets_category'
        );
        $this->ensureForeignKeyDeleteRule(
            'budgets', 'subcategory_id', 'subcategories', 'id', 'RESTRICT', 'fk_budgets_subcategory'
        );
    }

    private function reconcileReportLedgerPagination(): void {
        $this->addIndex('transactions', 'idx_transactions_user_date_created', ['user_id','date','created_at']);
        $this->addIndex('karobar_transactions', 'idx_karobar_user_date_created', ['user_id','transaction_date','created_at']);
    }

    private function reconcileCredentialSessionVersion(): void {
        $this->addColumn('users','token_version','INT NOT NULL DEFAULT 1 AFTER settings');
        $this->addColumn('users','password_changed_at','TIMESTAMP NULL AFTER token_version');
    }

    private function createMigrationHistory(): void {
        $this->conn->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                migration_id VARCHAR(100) PRIMARY KEY,
                checksum CHAR(64) NOT NULL,
                applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private function assertSupportedBaseline(): void {
        $required = [
            'users', 'accounts', 'categories', 'subcategories', 'goals',
            'recurring_transactions', 'transactions', 'budgets', 'attachments',
            'notifications', 'ai_analysis_history', 'reports', 'activity_logs',
            'people', 'karobar_transactions', 'password_reset_tokens',
            'email_verification_tokens',
        ];
        foreach ($required as $table) {
            if (!$this->tableExists($table)) {
                throw new RuntimeException("Unsupported schema baseline: required table {$table} is missing.");
            }
        }
    }

    private function reconcileColumns(): void {
        $this->addColumn('users', 'settings', 'LONGTEXT NULL AFTER notification_preferences');
        $this->addColumn('users', 'created_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER settings');
        $this->addColumn('users', 'updated_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at');
        $this->ensureLongText('users', 'notification_preferences');
        $this->ensureLongText('users', 'settings');

        $this->addColumn('accounts', 'opening_balance', 'DECIMAL(15,2) NULL DEFAULT 0.00 AFTER balance');
        $this->addColumn('accounts', 'include_in_savings', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER is_default');

        $this->addColumn('categories', 'parent_id', 'INT NULL AFTER is_default');
        $this->addColumn('categories', 'status', "ENUM('active','archived','deleted') NULL DEFAULT 'active' AFTER updated_at");
        $this->addColumn('categories', 'sort_order', 'INT NULL DEFAULT 0 AFTER status');
        $this->addColumn('categories', 'deleted_at', 'TIMESTAMP NULL AFTER sort_order');

        $this->addColumn('people', 'type', "ENUM('person','friend','family','shop','vendor','business','other') NULL DEFAULT 'person' AFTER name");

        $this->addColumn('transactions', 'from_account_id', 'INT NULL AFTER account_id');
        $this->addColumn('transactions', 'to_account_id', 'INT NULL AFTER from_account_id');
        $this->addColumn('transactions', 'payment_method', 'VARCHAR(30) NULL AFTER type');
        $this->addColumn('transactions', 'karobar_transaction_id', 'INT NULL AFTER payment_method');
        $this->addColumn('transactions', 'client_request_id', 'VARCHAR(64) NULL AFTER karobar_transaction_id');
        $this->addColumn('transactions', 'version', 'INT NOT NULL DEFAULT 1 AFTER client_request_id');
        $this->ensureNullableInt('transactions', 'account_id');
        $this->ensureNullableInt('transactions', 'category_id');
        $this->ensureTransactionType();

        $this->addColumn('karobar_transactions', 'expense_transaction_id', 'INT NULL AFTER account_id');
        $this->addColumn('karobar_transactions', 'income_transaction_id', 'INT NULL AFTER expense_transaction_id');
        $this->addColumn('karobar_transactions', 'payment_method', 'VARCHAR(30) NULL AFTER income_transaction_id');
        $this->addColumn('karobar_transactions', 'client_request_id', 'VARCHAR(64) NULL AFTER payment_method');

        $this->ensureLongText('ai_analysis_history', 'insights');
        $this->ensureLongText('reports', 'data');
        $this->ensureLongText('activity_logs', 'metadata');

        $this->conn->exec('ALTER TABLE password_reset_tokens MODIFY expires_at TIMESTAMP NOT NULL');
        $this->conn->exec('ALTER TABLE email_verification_tokens MODIFY expires_at TIMESTAMP NOT NULL');
    }

    private function reconcileIndexes(): void {
        $this->addIndex('categories', 'idx_parent_id', ['parent_id']);
        $this->addIndex('categories', 'idx_status', ['status']);
        $this->addIndex('categories', 'idx_sort_order', ['sort_order']);
        $this->addIndex('people', 'idx_type', ['type']);
        $this->addIndex('transactions', 'idx_from_account_id', ['from_account_id']);
        $this->addIndex('transactions', 'idx_to_account_id', ['to_account_id']);

        $this->addUniqueIndex(
            'transactions',
            'uq_transactions_user_client_request',
            ['user_id', 'client_request_id']
        );
        $this->addUniqueIndex(
            'karobar_transactions',
            'uq_karobar_user_client_request',
            ['user_id', 'client_request_id']
        );
    }

    private function reconcileForeignKeys(): void {
        $this->addForeignKey('activity_logs', 'user_id', 'users', 'id', 'CASCADE', 'fk_activity_logs_user');
        $this->addForeignKey('ai_analysis_history', 'user_id', 'users', 'id', 'CASCADE', 'fk_ai_analysis_user');
        $this->addForeignKey('reports', 'user_id', 'users', 'id', 'CASCADE', 'fk_reports_user');
        $this->addForeignKey('categories', 'parent_id', 'categories', 'id', 'SET NULL', 'fk_categories_parent');
        $this->addForeignKey('transactions', 'from_account_id', 'accounts', 'id', 'SET NULL', 'fk_tx_from_account');
        $this->addForeignKey('transactions', 'to_account_id', 'accounts', 'id', 'SET NULL', 'fk_tx_to_account');
        $this->addForeignKey('transactions', 'karobar_transaction_id', 'karobar_transactions', 'id', 'SET NULL', 'fk_transactions_karobar');
        $this->addForeignKey('karobar_transactions', 'expense_transaction_id', 'transactions', 'id', 'SET NULL', 'fk_karobar_expense_tx');
        $this->addForeignKey('karobar_transactions', 'income_transaction_id', 'transactions', 'id', 'SET NULL', 'fk_karobar_income_tx');
    }

    private function backfillDerivedColumns(): void {
        $this->conn->exec("UPDATE accounts SET include_in_savings = 1 WHERE type = 'savings' AND include_in_savings = 0");
        $this->conn->exec("UPDATE transactions SET to_account_id = account_id WHERE type = 'income' AND to_account_id IS NULL");
        $this->conn->exec("UPDATE transactions SET from_account_id = account_id WHERE type IN ('expense','transfer') AND from_account_id IS NULL");
    }

    private function assertCurrentApplicationSchema(): void {
        $requiredColumns = [
            'accounts' => ['opening_balance', 'include_in_savings'],
            'categories' => ['status', 'sort_order', 'deleted_at'],
            'goals' => ['initial_amount', 'current_amount', 'version'],
            'transactions' => ['from_account_id', 'to_account_id', 'payment_method', 'karobar_transaction_id', 'client_request_id', 'transfer_parent_id', 'goal_id', 'version'],
            'people' => ['type', 'status'],
            'karobar_transactions' => ['expense_transaction_id', 'income_transaction_id', 'payment_method', 'client_request_id', 'version'],
            'users' => ['settings', 'token_version', 'password_changed_at', 'created_at', 'updated_at'],
        ];
        foreach ($requiredColumns as $table => $columns) {
            foreach ($columns as $column) {
                if (!$this->columnExists($table, $column)) {
                    throw new RuntimeException("Schema reconciliation failed: {$table}.{$column} is missing.");
                }
            }
        }
        if (!$this->indexExists('karobar_transactions', ['user_id', 'client_request_id'], true)) {
            throw new RuntimeException('Phase 1 Karobar idempotency constraint is missing.');
        }
        if (!$this->indexExists('transactions', ['user_id', 'client_request_id'], true)) {
            throw new RuntimeException('Transaction idempotency constraint is missing.');
        }
        if (!$this->indexExists('transactions', ['user_id', 'transfer_parent_id'], true)) {
            throw new RuntimeException('Transfer fee linkage constraint is missing.');
        }
        if (!$this->indexExists('transactions', ['goal_id'], false)) {
            throw new RuntimeException('Goal contribution linkage index is missing.');
        }
        if (!$this->indexExists('transactions', ['user_id', 'karobar_transaction_id'], true)
            || !$this->indexExists('karobar_transactions', ['user_id', 'expense_transaction_id'], true)) {
            throw new RuntimeException('Credit purchase one-to-one linkage constraints are missing.');
        }
        $typeInfo = $this->columnInfo('transactions', 'type');
        if (!$typeInfo || stripos((string)$typeInfo['COLUMN_TYPE'], "'goal_contribution'") === false) {
            throw new RuntimeException('Goal contribution transaction classification is missing.');
        }
        if (!$this->foreignKeyExists('transactions', 'goal_id', 'goals', 'id')) {
            throw new RuntimeException('Goal contribution foreign key is missing.');
        }
        if (!$this->foreignKeyHasDeleteRule('karobar_transactions','person_id','people','id','RESTRICT')) {
            throw new RuntimeException('Karobar person history foreign key must use ON DELETE RESTRICT.');
        }
        if (!$this->foreignKeyHasDeleteRule('budgets','category_id','categories','id','RESTRICT')
            || !$this->foreignKeyHasDeleteRule('budgets','subcategory_id','subcategories','id','RESTRICT')) {
            throw new RuntimeException('Budget classification foreign keys must use ON DELETE RESTRICT.');
        }
        if (!$this->indexExists('transactions',['user_id','date','created_at'],false)
            || !$this->indexExists('karobar_transactions',['user_id','transaction_date','created_at'],false)) {
            throw new RuntimeException('Phase 10 report and ledger pagination indexes are missing.');
        }
    }

    private function addColumn(string $table, string $column, string $definition): void {
        $this->assertIdentifier($table);
        $this->assertIdentifier($column);
        if (!$this->columnExists($table, $column)) {
            $this->conn->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        }
    }

    private function ensureNullableInt(string $table, string $column): void {
        $info = $this->columnInfo($table, $column);
        if (!$info) throw new RuntimeException("Required column {$table}.{$column} is missing.");
        if ($info['IS_NULLABLE'] !== 'YES' || strtolower($info['COLUMN_TYPE']) !== 'int') {
            $this->conn->exec("ALTER TABLE `{$table}` MODIFY `{$column}` INT NULL");
        }
    }

    private function ensureLongText(string $table, string $column): void {
        $info = $this->columnInfo($table, $column);
        if (!$info) return;
        if (strtolower($info['DATA_TYPE']) !== 'longtext') {
            $this->conn->exec("ALTER TABLE `{$table}` MODIFY `{$column}` LONGTEXT NULL");
        }
    }

    private function ensureTransactionType(): void {
        $info = $this->columnInfo('transactions', 'type');
        if (!$info || stripos($info['COLUMN_TYPE'], "'transfer'") === false) {
            $this->conn->exec("ALTER TABLE transactions MODIFY type ENUM('income','expense','transfer') NOT NULL");
        }
    }

    private function ensureGoalContributionType(): void {
        $info = $this->columnInfo('transactions', 'type');
        if (!$info || stripos($info['COLUMN_TYPE'], "'goal_contribution'") === false) {
            $this->conn->exec("ALTER TABLE transactions MODIFY type ENUM('income','expense','transfer','goal_contribution') NOT NULL");
        }
    }

    private function addIndex(string $table, string $name, array $columns): void {
        if ($this->indexExists($table, $columns, false)) return;
        $this->assertIdentifier($name);
        $columnSql = implode(', ', array_map(fn($column) => '`' . $this->assertIdentifier($column) . '`', $columns));
        $this->conn->exec("ALTER TABLE `{$table}` ADD INDEX `{$name}` ({$columnSql})");
    }

    private function addUniqueIndex(string $table, string $name, array $columns): void {
        if ($this->indexExists($table, $columns, true)) return;
        $groupSql = implode(', ', array_map(fn($column) => '`' . $this->assertIdentifier($column) . '`', $columns));
        $nonNull = array_map(fn($column) => '`' . $this->assertIdentifier($column) . '` IS NOT NULL', $columns);
        $duplicates = (int)$this->conn->query(
            "SELECT COUNT(*) FROM (
                SELECT {$groupSql}, COUNT(*) AS duplicate_count FROM `{$table}`
                WHERE " . implode(' AND ', $nonNull) . "
                GROUP BY {$groupSql} HAVING duplicate_count > 1
            ) duplicate_groups"
        )->fetchColumn();
        if ($duplicates > 0) {
            throw new RuntimeException("Cannot add {$name}: duplicate values must be resolved explicitly first.");
        }
        $this->assertIdentifier($name);
        $this->conn->exec("ALTER TABLE `{$table}` ADD UNIQUE INDEX `{$name}` ({$groupSql})");
    }

    private function addForeignKey(
        string $table,
        string $column,
        string $parentTable,
        string $parentColumn,
        string $deleteRule,
        string $constraintName
    ): void {
        if ($this->foreignKeyExists($table, $column, $parentTable, $parentColumn)) return;
        foreach ([$table, $column, $parentTable, $parentColumn, $constraintName] as $identifier) {
            $this->assertIdentifier($identifier);
        }
        $orphans = (int)$this->conn->query(
            "SELECT COUNT(*) FROM `{$table}` child
             LEFT JOIN `{$parentTable}` parent ON parent.`{$parentColumn}` = child.`{$column}`
             WHERE child.`{$column}` IS NOT NULL AND parent.`{$parentColumn}` IS NULL"
        )->fetchColumn();
        if ($orphans > 0) {
            throw new RuntimeException("Cannot add {$constraintName}: {$orphans} orphaned relationship(s) exist.");
        }
        $this->conn->exec(
            "ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraintName}`
             FOREIGN KEY (`{$column}`) REFERENCES `{$parentTable}` (`{$parentColumn}`) ON DELETE {$deleteRule}"
        );
    }

    private function ensureForeignKeyDeleteRule(
        string $table, string $column, string $parentTable, string $parentColumn,
        string $deleteRule, string $fallbackConstraintName
    ): void {
        foreach ([$table,$column,$parentTable,$parentColumn,$fallbackConstraintName] as $identifier) {
            $this->assertIdentifier($identifier);
        }
        $deleteRule=strtoupper($deleteRule);
        if(!in_array($deleteRule,['RESTRICT','CASCADE','SET NULL'],true)){
            throw new InvalidArgumentException('Unsupported foreign-key delete rule.');
        }

        $childInfo=$this->columnInfo($table,$column);
        $parentInfo=$this->columnInfo($parentTable,$parentColumn);
        if(!$childInfo||!$parentInfo){
            throw new RuntimeException("Cannot protect {$table}.{$column}: required columns are missing.");
        }
        if(strtolower((string)$childInfo['COLUMN_TYPE'])!==strtolower((string)$parentInfo['COLUMN_TYPE'])){
            throw new RuntimeException("Cannot protect {$table}.{$column}: child and parent column types do not match.");
        }
        $orphans=(int)$this->conn->query(
            "SELECT COUNT(*) FROM `{$table}` child LEFT JOIN `{$parentTable}` parent
             ON parent.`{$parentColumn}`=child.`{$column}`
             WHERE child.`{$column}` IS NOT NULL AND parent.`{$parentColumn}` IS NULL"
        )->fetchColumn();
        if($orphans>0){
            throw new RuntimeException("Cannot protect {$table}.{$column}: {$orphans} orphaned relationship(s) require explicit repair.");
        }

        $constraints=$this->personForeignKeyRows($table,$column,$parentTable,$parentColumn);
        if(count($constraints)>1){
            throw new RuntimeException("Cannot protect {$table}.{$column}: multiple foreign keys reference the same parent.");
        }
        if($constraints&&$this->deleteRulesEquivalent((string)$constraints[0]['DELETE_RULE'],$deleteRule))return;

        $constraintName=$constraints?(string)$constraints[0]['CONSTRAINT_NAME']:$fallbackConstraintName;
        $this->assertIdentifier($constraintName);
        if($constraints)$this->conn->exec("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$constraintName}`");
        $this->addForeignKey($table,$column,$parentTable,$parentColumn,$deleteRule,$constraintName);
    }

    private function foreignKeyHasDeleteRule(
        string $table,string $column,string $parentTable,string $parentColumn,string $deleteRule
    ):bool {
        $rows=$this->personForeignKeyRows($table,$column,$parentTable,$parentColumn);
        return count($rows)===1&&$this->deleteRulesEquivalent((string)$rows[0]['DELETE_RULE'],$deleteRule);
    }

    private function personForeignKeyRows(
        string $table,string $column,string $parentTable,string $parentColumn
    ):array {
        $stmt=$this->conn->prepare(
            'SELECT k.CONSTRAINT_NAME,r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k
             JOIN information_schema.REFERENTIAL_CONSTRAINTS r
               ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME
             WHERE k.CONSTRAINT_SCHEMA=:schema AND k.TABLE_NAME=:table AND k.COLUMN_NAME=:column
               AND k.REFERENCED_TABLE_NAME=:parent_table AND k.REFERENCED_COLUMN_NAME=:parent_column'
        );
        $stmt->execute([':schema'=>$this->databaseName,':table'=>$table,':column'=>$column,':parent_table'=>$parentTable,':parent_column'=>$parentColumn]);
        return$stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function deleteRulesEquivalent(string $actual,string $expected):bool {
        $actual=strtoupper($actual);$expected=strtoupper($expected);
        return$actual===$expected||($expected==='RESTRICT'&&$actual==='NO ACTION');
    }

    private function tableExists(string $table): bool {
        $stmt = $this->conn->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table'
        );
        $stmt->execute([':schema' => $this->databaseName, ':table' => $table]);
        return (int)$stmt->fetchColumn() === 1;
    }

    private function columnExists(string $table, string $column): bool {
        return $this->columnInfo($table, $column) !== false;
    }

    private function columnInfo(string $table, string $column) {
        $stmt = $this->conn->prepare(
            'SELECT DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table AND COLUMN_NAME = :column'
        );
        $stmt->execute([':schema' => $this->databaseName, ':table' => $table, ':column' => $column]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function indexExists(string $table, array $columns, bool $unique): bool {
        $stmt = $this->conn->prepare(
            'SELECT INDEX_NAME, NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_list
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table
             GROUP BY INDEX_NAME, NON_UNIQUE'
        );
        $stmt->execute([':schema' => $this->databaseName, ':table' => $table]);
        $wanted = implode(',', $columns);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $index) {
            if ($index['columns_list'] === $wanted && (!$unique || (int)$index['NON_UNIQUE'] === 0)) return true;
        }
        return false;
    }

    private function foreignKeyExists(string $table, string $column, string $parentTable, string $parentColumn): bool {
        $stmt = $this->conn->prepare(
            'SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
             WHERE CONSTRAINT_SCHEMA = :schema AND TABLE_NAME = :table AND COLUMN_NAME = :column
               AND REFERENCED_TABLE_NAME = :parent_table AND REFERENCED_COLUMN_NAME = :parent_column'
        );
        $stmt->execute([
            ':schema' => $this->databaseName,
            ':table' => $table,
            ':column' => $column,
            ':parent_table' => $parentTable,
            ':parent_column' => $parentColumn,
        ]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private function migrationRecord(string $migrationId) {
        $stmt = $this->conn->prepare('SELECT * FROM schema_migrations WHERE migration_id = :migration_id');
        $stmt->execute([':migration_id' => $migrationId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function assertIdentifier(string $identifier): string {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
            throw new InvalidArgumentException("Unsafe SQL identifier: {$identifier}");
        }
        return $identifier;
    }
}
