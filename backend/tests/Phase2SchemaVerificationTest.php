<?php

$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/../database/SchemaMigrator.php';

function phase2Assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function phase2AdminConnection(): PDO {
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $user = getenv('DB_USERNAME') ?: 'root';
    $password = getenv('DB_PASSWORD') ?: '';
    return new PDO("mysql:host={$host};charset=utf8mb4", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function phase2DatabaseConnection(string $database): PDO {
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $user = getenv('DB_USERNAME') ?: 'root';
    $password = getenv('DB_PASSWORD') ?: '';
    return new PDO("mysql:host={$host};dbname={$database};charset=utf8mb4", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function phase2RunMysql(string $database, string $sql): void {
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $user = getenv('DB_USERNAME') ?: 'root';
    $password = getenv('DB_PASSWORD') ?: '';
    $command = ['mysql', '--host=' . $host, '--user=' . $user, '--default-character-set=utf8mb4'];
    if ($database !== '') $command[] = $database;
    $environment = getenv();
    if ($password !== '') $environment['MYSQL_PWD'] = $password;
    $pipes = [];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, dirname(__DIR__, 2), $environment);
    if (!is_resource($process)) throw new RuntimeException('Unable to start the MySQL client.');
    fwrite($pipes[0], $sql);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) throw new RuntimeException("MySQL import failed: {$stderr}{$stdout}");
}

function phase2BaselineDdl(string $dump): string {
    preg_match_all('/^CREATE TABLE `[^`]+` \(.*?^\) ENGINE=.*?;/ms', $dump, $matches);
    if (count($matches[0]) !== 17) {
        throw new RuntimeException('The supported August baseline no longer contains the expected 17 tables.');
    }
    return "SET FOREIGN_KEY_CHECKS=0;\n" . implode("\n", $matches[0]) . "\nSET FOREIGN_KEY_CHECKS=1;\n";
}

function phase2Request(string $baseUrl, string $method, string $path, ?array $body = null, ?string $token = null): array {
    $headers = "Content-Type: application/json\r\n";
    if ($token) $headers .= "Authorization: Bearer {$token}\r\n";
    $options = [
        'method' => $method,
        'header' => $headers,
        'ignore_errors' => true,
        'timeout' => 10,
    ];
    if ($body !== null) $options['content'] = json_encode($body);
    $raw = file_get_contents($baseUrl . $path, false, stream_context_create(['http' => $options]));
    $statusLine = $http_response_header[0] ?? '';
    preg_match('/\s(\d{3})\s/', $statusLine, $match);
    return [(int)($match[1] ?? 0), json_decode($raw ?: 'null', true)];
}

function phase2StartServer(string $database): array {
    $port = random_int(19000, 29000);
    $address = "127.0.0.1:{$port}";
    $apiDirectory = realpath(__DIR__ . '/../api');
    $router = $apiDirectory . DIRECTORY_SEPARATOR . 'index.php';
    $stdoutPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sanie-phase2-server-' . bin2hex(random_bytes(5)) . '.out';
    $stderrPath = $stdoutPath . '.err';
    $environment = getenv();
    $environment['DB_DATABASE'] = $database;
    $environment['DB_HOST'] = getenv('DB_HOST') ?: '127.0.0.1';
    $environment['DB_USERNAME'] = getenv('DB_USERNAME') ?: 'root';
    $environment['DB_PASSWORD'] = getenv('DB_PASSWORD') ?: '';
    $environment['JWT_SECRET'] = 'phase2-test-jwt-secret-at-least-32-characters';
    $environment['APP_ENV'] = 'testing';
    $environment['APP_DEBUG'] = '0';
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, '-S', $address, '-t', $apiDirectory, $router],
        [0 => ['pipe', 'r'], 1 => ['file', $stdoutPath, 'a'], 2 => ['file', $stderrPath, 'a']],
        $pipes,
        dirname(__DIR__, 2),
        $environment
    );
    if (!is_resource($process)) throw new RuntimeException('Unable to start the isolated API server.');
    fclose($pipes[0]);

    $baseUrl = "http://{$address}";
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        usleep(100000);
        $socket = @fsockopen('127.0.0.1', $port, $errorNumber, $errorMessage, 0.2);
        if ($socket) {
            fclose($socket);
            $ready = true;
            break;
        }
    }
    if (!$ready) {
        proc_terminate($process);
        throw new RuntimeException('Isolated API server did not start: ' . @file_get_contents($stderrPath));
    }
    return [$process, $baseUrl, $stdoutPath, $stderrPath];
}

function phase2StopServer(?array &$server): void {
    if (!$server) return;
    [$process, , $stdoutPath, $stderrPath] = $server;
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    @unlink($stdoutPath);
    @unlink($stderrPath);
    $server = null;
}

function phase2RegisterAndRead(string $baseUrl, string $email): array {
    $registration = [
        'email' => $email,
        'password' => 'Correct-Horse-Battery-2026!',
        'first_name' => 'Phase Two',
    ];
    [$registerStatus, $registerBody] = phase2Request($baseUrl, 'POST', '/auth/register', $registration);
    phase2Assert($registerStatus === 201 && ($registerBody['success'] ?? false), 'Fresh registration did not return 201.');
    $token = (string)($registerBody['data']['token'] ?? '');
    phase2Assert($token !== '', 'Registration response omitted the authentication token.');

    [$loginStatus, $loginBody] = phase2Request($baseUrl, 'POST', '/auth/login', [
        'email' => $email,
        'password' => $registration['password'],
    ]);
    phase2Assert($loginStatus === 200 && ($loginBody['success'] ?? false), 'Login after registration failed.');
    $loginToken = (string)($loginBody['data']['token'] ?? '');

    [$meStatus, $meBody] = phase2Request($baseUrl, 'GET', '/auth/me', null, $loginToken);
    phase2Assert($meStatus === 200 && ($meBody['data']['email'] ?? '') === $email, 'Authenticated user read failed.');
    [$accountsStatus, $accountsBody] = phase2Request($baseUrl, 'GET', '/accounts', null, $loginToken);
    phase2Assert($accountsStatus === 200 && count($accountsBody['data'] ?? []) === 3, 'Initial account read did not return three defaults.');
    return [$registration, $token];
}

function phase2SchemaSnapshot(PDO $connection, string $database): array {
    $columns = $connection->prepare(
        "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE,
                COALESCE(COLUMN_DEFAULT, '<NULL>') AS column_default, EXTRA
         FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :schema
         ORDER BY TABLE_NAME, COLUMN_NAME"
    );
    $columns->execute([':schema' => $database]);

    $indexes = $connection->prepare(
        "SELECT TABLE_NAME, NON_UNIQUE,
                GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_list
         FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = :schema
         GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE
         ORDER BY TABLE_NAME, NON_UNIQUE, columns_list"
    );
    $indexes->execute([':schema' => $database]);
    $normalizedIndexes = [];
    foreach ($indexes->fetchAll() as $index) {
        $normalizedIndexes[$index['TABLE_NAME'] . '|' . $index['NON_UNIQUE'] . '|' . $index['columns_list']] = true;
    }

    $foreignKeys = $connection->prepare(
        "SELECT k.TABLE_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.DELETE_RULE
         FROM information_schema.KEY_COLUMN_USAGE k
         JOIN information_schema.REFERENTIAL_CONSTRAINTS r
           ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
          AND r.TABLE_NAME = k.TABLE_NAME
          AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
         WHERE k.CONSTRAINT_SCHEMA = :schema
         ORDER BY k.TABLE_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME"
    );
    $foreignKeys->execute([':schema' => $database]);
    $normalizedForeignKeys = [];
    foreach ($foreignKeys->fetchAll() as $foreignKey) {
        if ($foreignKey['DELETE_RULE'] === 'NO ACTION') $foreignKey['DELETE_RULE'] = 'RESTRICT';
        $normalizedForeignKeys[] = $foreignKey;
    }

    return [
        'columns' => $columns->fetchAll(),
        'indexes' => array_keys($normalizedIndexes),
        'foreign_keys' => $normalizedForeignKeys,
    ];
}

$admin = phase2AdminConnection();
$suffix = strtolower(bin2hex(random_bytes(5)));
$freshDatabase = 'sanie_p2_fresh_' . $suffix;
$upgradeDatabase = 'sanie_p2_upgrade_' . $suffix;
$freshServer = null;
$upgradeServer = null;
$passed = 0;
$failed = 0;
$run = function (string $name, callable $test) use (&$passed, &$failed): void {
    try {
        $test();
        $passed++;
        echo "PASS: {$name}\n";
    } catch (Throwable $e) {
        $failed++;
        fwrite(STDERR, "FAIL: {$name} — {$e->getMessage()}\n");
    }
};

try {
    foreach ([$freshDatabase, $upgradeDatabase] as $database) {
        phase2Assert((bool)preg_match('/^sanie_p2_(fresh|upgrade)_[a-f0-9]+$/', $database), 'Unsafe temporary database name.');
        $admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    $canonical = file_get_contents(__DIR__ . '/../database/schema.sql');
    $canonical = str_replace(
        ['CREATE DATABASE IF NOT EXISTS sanie_db;', 'USE sanie_db;'],
        ['', "USE `{$freshDatabase}`;"],
        $canonical
    );
    phase2RunMysql('', $canonical);
    $fresh = phase2DatabaseConnection($freshDatabase);

    $run('empty database imports the canonical schema', function () use ($fresh): void {
        phase2Assert((int)$fresh->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchColumn() === 22, 'Fresh schema did not create 22 required tables.');
        phase2Assert((int)$fresh->query('SELECT COUNT(*) FROM categories')->fetchColumn() === 0, 'Canonical schema inserted duplicate-prone global categories.');
    });

    $rateFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sanie-rate-' . hash('sha256', 'register:127.0.0.1') . '.json';
    @unlink($rateFile);
    $freshServer = phase2StartServer($freshDatabase);
    $freshBaseUrl = $freshServer[1];
    $freshEmail = 'phase2-fresh-' . $suffix . '@example.invalid';

    $run('fresh registration, login, and authenticated basic read succeed', function () use ($freshBaseUrl, $freshEmail, $fresh): void {
        phase2RegisterAndRead($freshBaseUrl, $freshEmail);
        $userId = (int)$fresh->query("SELECT id FROM users WHERE email = " . $fresh->quote($freshEmail))->fetchColumn();
        phase2Assert($userId > 0, 'Registered user was not persisted.');
        phase2Assert((int)$fresh->query("SELECT COUNT(*) FROM accounts WHERE user_id = {$userId}")->fetchColumn() === 3, 'Default account count is incorrect.');
        phase2Assert((int)$fresh->query("SELECT COUNT(*) FROM categories WHERE user_id = {$userId}")->fetchColumn() === 12, 'Default category count is incorrect.');
    });

    $run('duplicate registration returns conflict without duplicate defaults', function () use ($freshBaseUrl, $freshEmail, $fresh): void {
        [$status] = phase2Request($freshBaseUrl, 'POST', '/auth/register', [
            'email' => $freshEmail,
            'password' => 'Correct-Horse-Battery-2026!',
            'first_name' => 'Duplicate',
        ]);
        phase2Assert($status === 409, 'Duplicate email registration did not return 409.');
        phase2Assert((int)$fresh->query("SELECT COUNT(*) FROM users WHERE email = " . $fresh->quote($freshEmail))->fetchColumn() === 1, 'Duplicate user row was created.');
    });

    $run('registration initialization failure rolls back user and defaults', function () use ($freshBaseUrl, $fresh): void {
        $fresh->exec(
            "CREATE TRIGGER phase2_fail_account BEFORE INSERT ON accounts FOR EACH ROW
             SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced default account failure'"
        );
        $failedEmail = 'phase2-rollback-' . bin2hex(random_bytes(4)) . '@example.invalid';
        try {
            [$status] = phase2Request($freshBaseUrl, 'POST', '/auth/register', [
                'email' => $failedEmail,
                'password' => 'Correct-Horse-Battery-2026!',
                'first_name' => 'Rollback',
            ]);
            phase2Assert($status === 500, 'Forced initialization failure did not return a safe 500 response.');
            phase2Assert((int)$fresh->query("SELECT COUNT(*) FROM users WHERE email = " . $fresh->quote($failedEmail))->fetchColumn() === 0, 'Failed registration left a user row behind.');
        } finally {
            $fresh->exec('DROP TRIGGER IF EXISTS phase2_fail_account');
        }
    });

    $baselineDump = file_get_contents(__DIR__ . '/../database/fixtures/august-2026-baseline-schema.sql');
    phase2RunMysql($upgradeDatabase, phase2BaselineDdl($baselineDump));
    $upgrade = phase2DatabaseConnection($upgradeDatabase);
    $upgrade->exec("INSERT INTO users (email, password, first_name) VALUES ('preserved@example.invalid', 'hash', 'Preserved')");
    $preservedUserId = (int)$upgrade->lastInsertId();
    $upgrade->exec("INSERT INTO accounts (user_id, name, type, balance, opening_balance) VALUES ({$preservedUserId}, 'Preserved Cash', 'cash', 1234.56, 1000.00)");
    $preservedAccountId = (int)$upgrade->lastInsertId();
    $upgrade->exec("INSERT INTO categories (user_id, name, type, status) VALUES ({$preservedUserId}, 'Preserved Expense', 'expense', 'active')");
    $preservedCategoryId = (int)$upgrade->lastInsertId();
    $upgrade->exec("INSERT INTO people (user_id, name, type) VALUES ({$preservedUserId}, 'Preserved Person', 'person')");
    $preservedPersonId = (int)$upgrade->lastInsertId();
    $upgrade->exec(
        "INSERT INTO transactions (user_id, account_id, category_id, amount, type, date, description)
         VALUES ({$preservedUserId}, {$preservedAccountId}, {$preservedCategoryId}, 234.56, 'expense', '2026-01-10', 'Preserved transaction')"
    );
    $preservedTransactionId = (int)$upgrade->lastInsertId();
    $upgrade->exec(
        "INSERT INTO karobar_transactions (user_id, person_id, type, amount, account_id, transaction_date, description)
         VALUES ({$preservedUserId}, {$preservedPersonId}, 'borrowed', 345.67, {$preservedAccountId}, '2026-01-10', 'Preserved debt')"
    );
    $preservedKarobarId = (int)$upgrade->lastInsertId();
    $upgrade->exec("UPDATE transactions SET karobar_transaction_id = {$preservedKarobarId} WHERE id = {$preservedTransactionId}");

    $run('supported August baseline upgrades safely and repeatably', function () use ($upgrade, $preservedUserId, $preservedAccountId, $preservedTransactionId, $preservedKarobarId): void {
        $first = (new SchemaMigrator($upgrade))->run();
        $second = (new SchemaMigrator($upgrade))->run();
        phase2Assert($first['applied'] === true && $second['applied'] === false, 'Migration was not apply-once and repeat-safe.');
        $account = $upgrade->query("SELECT balance, opening_balance FROM accounts WHERE id = {$preservedAccountId} AND user_id = {$preservedUserId}")->fetch();
        phase2Assert($account && abs((float)$account['balance'] - 1234.56) < 0.001 && abs((float)$account['opening_balance'] - 1000) < 0.001, 'Upgrade rewrote preserved financial values.');
        $transaction = $upgrade->query("SELECT amount, karobar_transaction_id, version FROM transactions WHERE id = {$preservedTransactionId}")->fetch();
        phase2Assert($transaction && abs((float)$transaction['amount'] - 234.56) < 0.001 && (int)$transaction['karobar_transaction_id'] === $preservedKarobarId && (int)$transaction['version'] === 1, 'Upgrade changed or disconnected preserved transaction history.');
        $karobar = $upgrade->query("SELECT amount, description FROM karobar_transactions WHERE id = {$preservedKarobarId}")->fetch();
        phase2Assert($karobar && abs((float)$karobar['amount'] - 345.67) < 0.001 && $karobar['description'] === 'Preserved debt', 'Upgrade changed preserved Karobar history.');
    });

    $run('Phase 1 scoped Karobar idempotency remains enforced', function () use ($upgrade, $preservedUserId, $preservedAccountId): void {
        $column = $upgrade->query(
            "SELECT COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'karobar_transactions' AND COLUMN_NAME = 'client_request_id'"
        )->fetch();
        phase2Assert($column && strtolower($column['COLUMN_TYPE']) === 'varchar(64)' && $column['IS_NULLABLE'] === 'YES', 'Phase 1 client_request_id definition changed.');
        $stmt = $upgrade->prepare("INSERT INTO people (user_id, name, type) VALUES (?, 'Phase 1 Person', 'person')");
        $stmt->execute([$preservedUserId]);
        $personId = (int)$upgrade->lastInsertId();
        $insert = $upgrade->prepare(
            "INSERT INTO karobar_transactions
             (user_id, person_id, type, amount, account_id, client_request_id, transaction_date)
             VALUES (?, ?, 'borrowed', 10, ?, ?, '2026-01-10')"
        );
        $insert->execute([$preservedUserId, $personId, $preservedAccountId, null]);
        $insert->execute([$preservedUserId, $personId, $preservedAccountId, null]);
        $insert->execute([$preservedUserId, $personId, $preservedAccountId, 'phase2-preserved-key']);
        try {
            $insert->execute([$preservedUserId, $personId, $preservedAccountId, 'phase2-preserved-key']);
            throw new RuntimeException('Duplicate scoped Phase 1 key was accepted.');
        } catch (PDOException $e) {
            phase2Assert((string)$e->getCode() === '23000', 'Unexpected error while checking Phase 1 uniqueness.');
        }
    });

    $run('Phase 1 SQL migration is safe to verify repeatedly', function () use ($freshDatabase): void {
        $phase1Sql = file_get_contents(__DIR__ . '/../database/migration_karobar_payment_idempotency.sql');
        phase2RunMysql($freshDatabase, $phase1Sql);
        phase2RunMysql($freshDatabase, $phase1Sql);
    });

    $run('fresh and upgraded schemas have material structural parity', function () use ($admin, $freshDatabase, $upgradeDatabase): void {
        $freshSnapshot = phase2SchemaSnapshot($admin, $freshDatabase);
        $upgradeSnapshot = phase2SchemaSnapshot($admin, $upgradeDatabase);
        phase2Assert($freshSnapshot === $upgradeSnapshot, 'Fresh and upgraded schema snapshots differ: ' . json_encode([
            'fresh_only_columns' => array_values(array_diff(array_map('json_encode', $freshSnapshot['columns']), array_map('json_encode', $upgradeSnapshot['columns']))),
            'upgrade_only_columns' => array_values(array_diff(array_map('json_encode', $upgradeSnapshot['columns']), array_map('json_encode', $freshSnapshot['columns']))),
            'fresh_only_indexes' => array_values(array_diff($freshSnapshot['indexes'], $upgradeSnapshot['indexes'])),
            'upgrade_only_indexes' => array_values(array_diff($upgradeSnapshot['indexes'], $freshSnapshot['indexes'])),
            'fresh_only_foreign_keys' => array_values(array_diff(array_map('json_encode', $freshSnapshot['foreign_keys']), array_map('json_encode', $upgradeSnapshot['foreign_keys']))),
            'upgrade_only_foreign_keys' => array_values(array_diff(array_map('json_encode', $upgradeSnapshot['foreign_keys']), array_map('json_encode', $freshSnapshot['foreign_keys']))),
        ]));
    });

    $upgradeServer = phase2StartServer($upgradeDatabase);
    $run('current application registers and reads against upgraded schema', function () use (&$upgradeServer, $suffix, $upgrade): void {
        $email = 'phase2-upgraded-' . $suffix . '@example.invalid';
        phase2RegisterAndRead($upgradeServer[1], $email);
        phase2Assert((int)$upgrade->query("SELECT COUNT(*) FROM users WHERE email = " . $upgrade->quote($email))->fetchColumn() === 1, 'Upgraded application registration was not persisted.');
    });
} finally {
    phase2StopServer($freshServer);
    phase2StopServer($upgradeServer);
    @unlink(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sanie-rate-' . hash('sha256', 'register:127.0.0.1') . '.json');
    foreach ([$freshDatabase, $upgradeDatabase] as $database) {
        if (preg_match('/^sanie_p2_(fresh|upgrade)_[a-f0-9]+$/', $database)) {
            try { $admin->exec("DROP DATABASE IF EXISTS `{$database}`"); } catch (Throwable $cleanupError) {
                fwrite(STDERR, 'Cleanup warning: ' . $cleanupError->getMessage() . "\n");
            }
        }
    }
}

echo "RESULT: {$passed} passed, {$failed} failed, 0 skipped\n";
exit($failed === 0 ? 0 : 1);
