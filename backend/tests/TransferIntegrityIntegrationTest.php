<?php

$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/AccountingService.php';
require_once __DIR__ . '/../services/BalanceService.php';
require_once __DIR__ . '/../models/Account.php';

function transferAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function transferClose(float $expected, float $actual, string $message): void {
    if (abs($expected - $actual) > 0.001) throw new RuntimeException("{$message}: expected {$expected}, got {$actual}");
}

function transferExpect(string $class, callable $operation, string $contains = ''): void {
    try {
        $operation();
    } catch (Throwable $e) {
        if (!is_a($e, $class)) throw new RuntimeException("Expected {$class}, got " . get_class($e));
        if ($contains !== '' && stripos($e->getMessage(), $contains) === false) {
            throw new RuntimeException("Expected '{$contains}' in exception: {$e->getMessage()}");
        }
        return;
    }
    throw new RuntimeException("Expected {$class}, but operation succeeded");
}

$db = (new Database())->getConnection();
if (!$db) {
    fwrite(STDERR, "FAIL: database is unavailable\n");
    exit(1);
}

$users = [];
$passed = 0;
$failed = 0;
$sequence = 0;
$run = function (string $name, callable $test) use (&$passed, &$failed): void {
    try {
        $test();
        $passed++;
        echo "PASS: {$name}\n";
    } catch (Throwable $e) {
        $failed++;
        fwrite(STDERR, "FAIL: {$name} - {$e->getMessage()}\n");
    }
};
$requestId = function (string $label) use (&$sequence): string {
    return 'req_transfer_' . (++$sequence) . '_' . preg_replace('/[^A-Za-z0-9_]/', '_', $label);
};

try {
    foreach (['owner', 'foreign'] as $label) {
        $stmt = $db->prepare("INSERT INTO users (email, password, first_name) VALUES (?, ?, 'Transfer Test')");
        $stmt->execute(['transfer-' . $label . '-' . bin2hex(random_bytes(6)) . '@example.invalid', password_hash('fixture', PASSWORD_DEFAULT)]);
        $users[$label] = (int)$db->lastInsertId();
    }
    $createAccount = function (int $userId, string $name, float $balance) use ($db): int {
        $stmt = $db->prepare("INSERT INTO accounts (user_id, name, type, balance, opening_balance, is_active, is_default) VALUES (?, ?, 'cash', ?, ?, 1, 0)");
        $stmt->execute([$userId, $name, $balance, $balance]);
        return (int)$db->lastInsertId();
    };
    $owner = $users['owner'];
    $foreign = $users['foreign'];
    $a = $createAccount($owner, 'Cash', 10000);
    $b = $createAccount($owner, 'Bank', 2000);
    $c = $createAccount($owner, 'Wallet', 3000);
    $foreignAccount = $createAccount($foreign, 'Foreign', 8000);
    $manualCash = $createAccount($owner, 'Manual Cash', 15000);
    $manualBank = $createAccount($owner, 'Manual Bank', 5000);
    $stmt = $db->prepare("INSERT INTO categories (user_id, name, type, status) VALUES (?, 'Transfer fees', 'expense', 'active')");
    $stmt->execute([$owner]);
    $feeCategory = (int)$db->lastInsertId();
    $service = new AccountingService();
    $balances = new BalanceService();
    $accountModel = new Account();
    $balance = fn(int $id): float => $balances->calculateAccountBalance($id, $owner);
    $payload = function (int $from, int $to, $amount, string $key, $fee = 0) use ($feeCategory): array {
        return [
            'type' => 'transfer', 'from_account_id' => $from, 'to_account_id' => $to,
            'amount' => $amount, 'fee_amount' => $fee,
            'fee_category_id' => $fee > 0 ? $feeCategory : null,
            'date' => '2026-08-15', 'description' => 'Integrity transfer',
            'client_request_id' => $key,
        ];
    };

    $run('create without fee preserves assets and statement direction', function () use ($service, $owner, $a, $b, $balance, $payload, $requestId, $accountModel): void {
        $before = $balance($a) + $balance($b);
        $resource = $service->createTransaction($owner, $payload($a, $b, 3000, $requestId('no_fee')));
        transferClose(7000, $balance($a), 'source balance');
        transferClose(5000, $balance($b), 'destination balance');
        transferClose($before, $balance($a) + $balance($b), 'net worth without fee');
        transferAssert($resource['source_transaction']['direction'] === 'out', 'source response direction is not OUT');
        transferAssert($resource['destination_transaction']['direction'] === 'in', 'destination response direction is not IN');
        transferAssert($resource['fee_transaction'] === null, 'unexpected fee row');
        $rows = $accountModel->getStatement($owner, $b);
        $statement = (new BalanceService())->buildStatement($rows, 2000, $b);
        $entry = array_values(array_filter($statement['entries'], fn($row) => ($row['id'] ?? null) == $resource['transfer_id']))[0] ?? null;
        transferAssert($entry && (float)$entry['money_in'] === 3000.0 && $entry['money_out'] === null, 'destination statement did not record money IN');
        $service->deleteTransaction($resource['transfer_id'], $owner);
        transferClose(10000, $balance($a), 'source restore');
        transferClose(2000, $balance($b), 'destination restore');
    });

    $run('create with fee links it once and changes assets only by fee', function () use ($db, $service, $owner, $a, $b, $balance, $payload, $requestId): void {
        $before = $balance($a) + $balance($b);
        $resource = $service->createTransaction($owner, $payload($a, $b, 3000, $requestId('fee'), 100));
        transferClose(6900, $balance($a), 'source with fee');
        transferClose(5000, $balance($b), 'destination with fee');
        transferClose($before - 100, $balance($a) + $balance($b), 'net worth with fee');
        $stmt = $db->prepare('SELECT COUNT(*) FROM transactions WHERE transfer_parent_id = ?');
        $stmt->execute([$resource['transfer_id']]);
        transferAssert((int)$stmt->fetchColumn() === 1, 'linked fee count is not one');
        transferAssert((int)$resource['fee_transaction']['account_id'] === $a && $resource['fee_transaction']['type'] === 'expense', 'fee semantics are incorrect');
        $service->deleteTransaction($resource['transfer_id'], $owner);
        $stmt->execute([$resource['transfer_id']]);
        transferAssert((int)$stmt->fetchColumn() === 0, 'fee survived parent deletion');
        transferClose(10000, $balance($a), 'fee deletion source restore');
        transferClose(2000, $balance($b), 'fee deletion destination restore');
    });

    $run('validation rejects malformed, same-account, and foreign accounts', function () use ($service, $owner, $a, $b, $foreignAccount, $payload, $requestId): void {
        transferExpect(InvalidArgumentException::class, fn() => $service->createTransaction($owner, $payload($a, $a, 1, $requestId('same'))), 'same account');
        transferExpect(InvalidArgumentException::class, fn() => $service->createTransaction($owner, $payload($a, $b, '1.001', $requestId('precision'))), 'decimal');
        transferExpect(InvalidArgumentException::class, fn() => $service->createTransaction($owner, $payload($a, $b, 1, $requestId('fee_precision'), '0.001')), 'decimal');
        transferExpect(TransferAuthorizationException::class, fn() => $service->createTransaction($owner, $payload($foreignAccount, $b, 1, $requestId('foreign_source'))));
        transferExpect(TransferAuthorizationException::class, fn() => $service->createTransaction($owner, $payload($a, $foreignAccount, 1, $requestId('foreign_destination'))));
    });

    $run('edit amount, fee lifecycle, and account changes remain atomic', function () use ($service, $owner, $a, $b, $c, $feeCategory, $balance, $payload, $requestId): void {
        $resource = $service->createTransaction($owner, $payload($a, $b, 1000, $requestId('edit')));
        $version = (int)$resource['transfer']['version'];
        $resource = $service->updateTransaction($resource['transfer_id'], $owner, ['amount' => 1500, 'fee_amount' => 100, 'fee_category_id' => $feeCategory], $version);
        transferClose(8400, $balance($a), 'amount edit source');
        transferClose(3500, $balance($b), 'amount edit destination');
        transferClose(100, (float)$resource['fee_transaction']['amount'], 'zero to positive fee');
        $version = (int)$resource['transfer']['version'];
        $resource = $service->updateTransaction($resource['transfer_id'], $owner, ['fee_amount' => 50, 'fee_category_id' => $feeCategory], $version);
        transferClose(8450, $balance($a), 'fee decrease source');
        $version = (int)$resource['transfer']['version'];
        $resource = $service->updateTransaction($resource['transfer_id'], $owner, ['fee_amount' => 0, 'fee_category_id' => null], $version);
        transferAssert($resource['fee_transaction'] === null, 'positive to zero fee did not remove row');
        transferClose(8500, $balance($a), 'fee removal source');
        $version = (int)$resource['transfer']['version'];
        $resource = $service->updateTransaction($resource['transfer_id'], $owner, [
            'from_account_id' => $c, 'to_account_id' => $a,
            'fee_amount' => 25, 'fee_category_id' => $feeCategory,
        ], $version);
        transferClose(11500, $balance($a), 'changed destination effect');
        transferClose(2000, $balance($b), 'old destination restored');
        transferClose(1475, $balance($c), 'changed source and fee effect');
        $service->deleteTransaction($resource['transfer_id'], $owner);
        transferClose(10000, $balance($a), 'changed transfer deletion account A');
        transferClose(2000, $balance($b), 'changed transfer deletion account B');
        transferClose(3000, $balance($c), 'changed transfer deletion account C');
    });

    $run('idempotent retry creates one transfer group and one fee', function () use ($db, $service, $owner, $a, $b, $payload, $requestId): void {
        $key = $requestId('retry');
        $data = $payload($a, $b, 500, $key, 10);
        $first = $service->createTransaction($owner, $data);
        $second = $service->createTransaction($owner, $data);
        transferAssert($first['transfer_id'] === $second['transfer_id'], 'retry returned a different group');
        $stmt = $db->prepare('SELECT COUNT(*) FROM transactions WHERE user_id = ? AND (client_request_id = ? OR transfer_parent_id = ?)');
        $stmt->execute([$owner, $key, $first['transfer_id']]);
        transferAssert((int)$stmt->fetchColumn() === 2, 'retry duplicated or lost a group member');
        transferExpect(TransactionConflictException::class, fn() => $service->createTransaction($owner, $payload($a, $b, 501, $key, 10)), 'payload');
        $service->deleteTransaction($first['transfer_id'], $owner);
    });

    $run('manual scenarios A, B, and C produce exact balances', function () use ($service, $owner, $manualCash, $manualBank, $feeCategory, $balance, $payload, $requestId): void {
        $plain = $service->createTransaction($owner, $payload($manualCash, $manualBank, 4000, $requestId('manual_a')));
        transferClose(11000, $balance($manualCash), 'Scenario A Cash');
        transferClose(9000, $balance($manualBank), 'Scenario A Bank');
        transferClose(20000, $balance($manualCash) + $balance($manualBank), 'Scenario A Total');
        $service->deleteTransaction($plain['transfer_id'], $owner);

        $withFee = $service->createTransaction($owner, $payload($manualCash, $manualBank, 4000, $requestId('manual_b'), 50));
        transferClose(10950, $balance($manualCash), 'Scenario B Cash');
        transferClose(9000, $balance($manualBank), 'Scenario B Bank');
        transferClose(19950, $balance($manualCash) + $balance($manualBank), 'Scenario B Total');
        transferAssert((int)$withFee['fee_transaction']['category_id'] === $feeCategory, 'Scenario B fee category');
        $service->deleteTransaction($withFee['transfer_id'], $owner);
        transferClose(15000, $balance($manualCash), 'Scenario C Cash restore');
        transferClose(5000, $balance($manualBank), 'Scenario C Bank restore');
        transferAssert((new Transaction())->findTransferFee($withFee['transfer_id'], $owner) === false, 'Scenario C fee remains');
    });

    $run('bulk deletion through a fee ID removes the whole group', function () use ($db, $service, $owner, $a, $b, $balance, $payload, $requestId): void {
        $resource = $service->createTransaction($owner, $payload($a, $b, 250, $requestId('bulk_fee'), 10));
        $feeId = (int)$resource['fee_transaction']['id'];
        $service->bulkDeleteTransactions([$feeId], $owner);
        $stmt = $db->prepare('SELECT COUNT(*) FROM transactions WHERE id = ? OR transfer_parent_id = ?');
        $stmt->execute([$resource['transfer_id'], $resource['transfer_id']]);
        transferAssert((int)$stmt->fetchColumn() === 0, 'bulk deletion left a transfer group member');
        transferClose(10000, $balance($a), 'bulk delete source restore');
        transferClose(2000, $balance($b), 'bulk delete destination restore');
    });

    foreach (['after_source_record', 'after_destination_record'] as $failurePoint) {
        $run("rollback at {$failurePoint}", function () use ($db, $owner, $a, $b, $payload, $requestId, $failurePoint): void {
            $probe = new AccountingService();
            $probe->setTransferFailureInjector(function (string $point) use ($failurePoint): void {
                if ($point === $failurePoint) throw new RuntimeException('Injected transfer failure');
            });
            $key = $requestId($failurePoint);
            transferExpect(RuntimeException::class, fn() => $probe->createTransaction($owner, $payload($a, $b, 100, $key)), 'Injected');
            $stmt = $db->prepare('SELECT COUNT(*) FROM transactions WHERE user_id = ? AND client_request_id = ?');
            $stmt->execute([$owner, $key]);
            transferAssert((int)$stmt->fetchColumn() === 0, 'partial transfer survived rollback');
        });
    }

    $run('optimistic version prevents competing edits and foreign mutations', function () use ($service, $owner, $foreign, $a, $b, $feeCategory, $payload, $requestId): void {
        $resource = $service->createTransaction($owner, $payload($a, $b, 400, $requestId('conflict')));
        $version = (int)$resource['transfer']['version'];
        $service->updateTransaction($resource['transfer_id'], $owner, ['amount' => 450, 'fee_amount' => 5, 'fee_category_id' => $feeCategory], $version);
        transferExpect(TransactionConflictException::class, fn() => $service->updateTransaction($resource['transfer_id'], $owner, ['amount' => 475], $version));
        transferExpect(Exception::class, fn() => $service->updateTransaction($resource['transfer_id'], $foreign, ['amount' => 1]));
        transferExpect(Exception::class, fn() => $service->deleteTransaction($resource['transfer_id'], $foreign));
        $current = (new Transaction())->findById($resource['transfer_id'], $owner);
        transferClose(450, (float)$current['amount'], 'conflicting edit changed transfer');
        $service->deleteTransaction($resource['transfer_id'], $owner, (int)$current['version']);
    });
} finally {
    foreach (array_reverse($users) as $userId) {
        try {
            $stmt = $db->prepare('DELETE FROM users WHERE id = ?');
            $stmt->execute([$userId]);
        } catch (Throwable $ignored) {}
    }
}

echo "RESULT: {$passed} passed, {$failed} failed, 0 skipped\n";
exit($failed === 0 ? 0 : 1);
