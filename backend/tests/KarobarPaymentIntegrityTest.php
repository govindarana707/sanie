<?php

$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/KarobarService.php';

class RollbackProbeKarobarService extends KarobarService {
    protected function afterPaymentRecordCreated($karobarId, $data, $userId): void {
        throw new RuntimeException('Simulated dependent-write failure');
    }
}

function assertClose(float $expected, float $actual, string $message): void {
    if (abs($expected - $actual) > 0.001) {
        throw new RuntimeException("{$message}: expected {$expected}, got {$actual}");
    }
}

function expectException(string $class, callable $operation, string $messageContains = ''): void {
    try {
        $operation();
    } catch (Throwable $e) {
        if (!is_a($e, $class)) {
            throw new RuntimeException("Expected {$class}, got " . get_class($e) . ': ' . $e->getMessage());
        }
        if ($messageContains !== '' && stripos($e->getMessage(), $messageContains) === false) {
            throw new RuntimeException("Exception did not contain '{$messageContains}': " . $e->getMessage());
        }
        return;
    }
    throw new RuntimeException("Expected {$class}, but the operation succeeded");
}

function accountBalance(PDO $db, int $accountId): float {
    $stmt = $db->prepare('SELECT balance FROM accounts WHERE id = ?');
    $stmt->execute([$accountId]);
    return (float)$stmt->fetchColumn();
}

function outstanding(PDO $db, int $userId, int $personId, string $direction): float {
    $source = $direction === 'repayment' ? 'borrowed' : 'lent';
    $payment = $direction === 'repayment' ? 'repaid' : 'returned';
    $stmt = $db->prepare(
        'SELECT COALESCE(SUM(CASE WHEN type = ? THEN amount ELSE 0 END), 0)
              - COALESCE(SUM(CASE WHEN type = ? THEN amount ELSE 0 END), 0)
         FROM karobar_transactions WHERE user_id = ? AND person_id = ?'
    );
    $stmt->execute([$source, $payment, $userId, $personId]);
    return (float)$stmt->fetchColumn();
}

function paymentCount(PDO $db, int $userId, int $personId, string $type): int {
    $stmt = $db->prepare('SELECT COUNT(*) FROM karobar_transactions WHERE user_id = ? AND person_id = ? AND type = ?');
    $stmt->execute([$userId, $personId, $type]);
    return (int)$stmt->fetchColumn();
}

function createPerson(PDO $db, int $userId, string $name): int {
    $stmt = $db->prepare("INSERT INTO people (user_id, name, type) VALUES (?, ?, 'person')");
    $stmt->execute([$userId, $name]);
    return (int)$db->lastInsertId();
}

function paymentPayload(int $personId, int $accountId, $amount, string $requestId, string $date = '2026-01-11'): array {
    return [
        'person_id' => $personId,
        'account_id' => $accountId,
        'amount' => $amount,
        'transaction_date' => $date,
        'client_request_id' => $requestId,
    ];
}

$db = (new Database())->getConnection();
if (!$db) {
    fwrite(STDERR, "FAIL: database is unavailable\n");
    exit(1);
}

$userIds = [];
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
    foreach (['owner', 'foreign'] as $label) {
        $email = 'karobar-integrity-' . $label . '-' . bin2hex(random_bytes(6)) . '@example.invalid';
        $stmt = $db->prepare("INSERT INTO users (email, password, first_name) VALUES (?, ?, 'Karobar Integrity')");
        $stmt->execute([$email, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT)]);
        $userIds[$label] = (int)$db->lastInsertId();
    }

    $accounts = [];
    foreach ($userIds as $label => $userId) {
        $stmt = $db->prepare(
            "INSERT INTO accounts (user_id, name, type, balance, opening_balance, is_active, is_default)
             VALUES (?, ?, 'cash', 25000, 25000, 1, 1)"
        );
        $stmt->execute([$userId, ucfirst($label) . ' Cash']);
        $accounts[$label] = (int)$db->lastInsertId();
    }

    $owner = $userIds['owner'];
    $foreign = $userIds['foreign'];
    $ownerAccount = $accounts['owner'];
    $foreignAccount = $accounts['foreign'];
    $service = new KarobarService();
    $sequence = 0;
    $requestId = function (string $label) use (&$sequence): string {
        return 'karobar-test-' . (++$sequence) . '-' . $label;
    };
    $source = function (int $personId, string $type, float $amount, ?int $accountId = null) use ($service, $owner, $ownerAccount): int {
        return (int)$service->createTransaction([
            'person_id' => $personId,
            'account_id' => $accountId ?? $ownerAccount,
            'type' => $type,
            'amount' => $amount,
            'transaction_date' => '2026-01-10',
        ], $owner);
    };

    $run('partial repayment reduces debt and account exactly once', function () use ($db, $service, $owner, $ownerAccount, $source, $requestId): void {
        $person = createPerson($db, $owner, 'Partial Repayment');
        $source($person, 'borrowed', 10000);
        $before = accountBalance($db, $ownerAccount);
        $payment = $service->processRepayment(paymentPayload($person, $ownerAccount, 4000, $requestId('partial-repay')), $owner);
        assertClose(6000, outstanding($db, $owner, $person, 'repayment'), 'repayment outstanding');
        assertClose($before - 4000, accountBalance($db, $ownerAccount), 'repayment account impact');
        if (paymentCount($db, $owner, $person, 'repaid') !== 1 || !$payment) throw new RuntimeException('Expected one repayment record');
    });

    $run('full repayment settles at zero', function () use ($db, $service, $owner, $ownerAccount, $source, $requestId): void {
        $person = createPerson($db, $owner, 'Full Repayment');
        $source($person, 'borrowed', 6000);
        $before = accountBalance($db, $ownerAccount);
        $service->processRepayment(paymentPayload($person, $ownerAccount, 6000, $requestId('full-repay')), $owner);
        assertClose(0, outstanding($db, $owner, $person, 'repayment'), 'full repayment outstanding');
        assertClose($before - 6000, accountBalance($db, $ownerAccount), 'full repayment account impact');
    });

    $run('repayment rejects invalid and over-limit amounts without writes', function () use ($db, $service, $owner, $ownerAccount, $source, $requestId): void {
        $person = createPerson($db, $owner, 'Invalid Repayment');
        $source($person, 'borrowed', 3000);
        $before = accountBalance($db, $ownerAccount);
        foreach ([0, -1, 'not-a-number', INF, 3000.001, 3500] as $index => $amount) {
            expectException(KarobarValidationException::class, fn() => $service->processRepayment(
                paymentPayload($person, $ownerAccount, $amount, $requestId('invalid-repay-' . $index)),
                $owner
            ));
        }
        assertClose(3000, outstanding($db, $owner, $person, 'repayment'), 'invalid repayment outstanding');
        assertClose($before, accountBalance($db, $ownerAccount), 'invalid repayment account');
        if (paymentCount($db, $owner, $person, 'repaid') !== 0) throw new RuntimeException('Invalid repayment created a record');
    });

    $run('repayment rejects missing, foreign account and foreign person', function () use ($db, $service, $owner, $foreign, $ownerAccount, $foreignAccount, $source, $requestId): void {
        $person = createPerson($db, $owner, 'Ownership Repayment');
        $foreignPerson = createPerson($db, $foreign, 'Foreign Person');
        $source($person, 'borrowed', 1000);
        expectException(KarobarNotFoundException::class, fn() => $service->processRepayment(paymentPayload($person, 999999999, 100, $requestId('missing-account')), $owner));
        expectException(KarobarAuthorizationException::class, fn() => $service->processRepayment(paymentPayload($person, $foreignAccount, 100, $requestId('foreign-account')), $owner));
        expectException(KarobarAuthorizationException::class, fn() => $service->processRepayment(paymentPayload($foreignPerson, $ownerAccount, 100, $requestId('foreign-person')), $owner));
        assertClose(1000, outstanding($db, $owner, $person, 'repayment'), 'ownership rejection outstanding');
    });

    $run('already settled repayment is rejected', function () use ($db, $service, $owner, $ownerAccount, $source, $requestId): void {
        $person = createPerson($db, $owner, 'Settled Repayment');
        $source($person, 'borrowed', 500);
        $service->processRepayment(paymentPayload($person, $ownerAccount, 500, $requestId('settle')), $owner);
        expectException(KarobarConflictException::class, fn() => $service->processRepayment(paymentPayload($person, $ownerAccount, 1, $requestId('after-settle')), $owner), 'settled');
        assertClose(0, outstanding($db, $owner, $person, 'repayment'), 'settled outstanding');
    });

    $run('partial and full receiving reduce receivable and credit account once', function () use ($db, $service, $owner, $ownerAccount, $source, $requestId): void {
        $partial = createPerson($db, $owner, 'Partial Receiving');
        $source($partial, 'lent', 10000);
        $before = accountBalance($db, $ownerAccount);
        $service->processReceiving(paymentPayload($partial, $ownerAccount, 2500, $requestId('partial-receive')), $owner);
        assertClose(7500, outstanding($db, $owner, $partial, 'receiving'), 'receiving outstanding');
        assertClose($before + 2500, accountBalance($db, $ownerAccount), 'receiving account impact');

        $full = createPerson($db, $owner, 'Full Receiving');
        $source($full, 'lent', 8000);
        $beforeFull = accountBalance($db, $ownerAccount);
        $service->processReceiving(paymentPayload($full, $ownerAccount, 8000, $requestId('full-receive')), $owner);
        assertClose(0, outstanding($db, $owner, $full, 'receiving'), 'full receiving outstanding');
        assertClose($beforeFull + 8000, accountBalance($db, $ownerAccount), 'full receiving account impact');
    });

    $run('specified manual balance scenarios produce exact totals', function () use ($db, $service, $owner, $source, $requestId): void {
        $makeAccount = function (string $name, float $opening) use ($db, $owner): int {
            $stmt = $db->prepare(
                "INSERT INTO accounts (user_id, name, type, balance, opening_balance, is_active, is_default)
                 VALUES (?, ?, 'cash', ?, ?, 1, 0)"
            );
            $stmt->execute([$owner, $name, $opening, $opening]);
            return (int)$db->lastInsertId();
        };

        $partialAccount = $makeAccount('Scenario A', 15000);
        $partialPerson = createPerson($db, $owner, 'Scenario A Person');
        $source($partialPerson, 'borrowed', 10000, $partialAccount);
        assertClose(25000, accountBalance($db, $partialAccount), 'scenario A starting account');
        $service->processRepayment(paymentPayload($partialPerson, $partialAccount, 4000, $requestId('scenario-a')), $owner);
        assertClose(6000, outstanding($db, $owner, $partialPerson, 'repayment'), 'scenario A outstanding');
        assertClose(21000, accountBalance($db, $partialAccount), 'scenario A account');

        $fullAccount = $makeAccount('Scenario B', 14000);
        $fullPerson = createPerson($db, $owner, 'Scenario B Person');
        $source($fullPerson, 'borrowed', 6000, $fullAccount);
        assertClose(20000, accountBalance($db, $fullAccount), 'scenario B starting account');
        $service->processRepayment(paymentPayload($fullPerson, $fullAccount, 6000, $requestId('scenario-b')), $owner);
        assertClose(0, outstanding($db, $owner, $fullPerson, 'repayment'), 'scenario B outstanding');
        assertClose(14000, accountBalance($db, $fullAccount), 'scenario B account');

        $receiveAccount = $makeAccount('Scenario D', 15000);
        $receivePerson = createPerson($db, $owner, 'Scenario D Person');
        $source($receivePerson, 'lent', 10000, $receiveAccount);
        assertClose(5000, accountBalance($db, $receiveAccount), 'scenario D starting account');
        $service->processReceiving(paymentPayload($receivePerson, $receiveAccount, 2500, $requestId('scenario-d')), $owner);
        assertClose(7500, outstanding($db, $owner, $receivePerson, 'receiving'), 'scenario D outstanding');
        assertClose(7500, accountBalance($db, $receiveAccount), 'scenario D account');
    });

    $run('receiving rejects invalid, excessive and foreign entities', function () use ($db, $service, $owner, $foreign, $ownerAccount, $foreignAccount, $source, $requestId): void {
        $person = createPerson($db, $owner, 'Invalid Receiving');
        $foreignPerson = createPerson($db, $foreign, 'Foreign Receiving');
        $source($person, 'lent', 1000);
        $before = accountBalance($db, $ownerAccount);
        foreach ([0, -50, 1001] as $index => $amount) {
            expectException(KarobarValidationException::class, fn() => $service->processReceiving(paymentPayload($person, $ownerAccount, $amount, $requestId('invalid-receive-' . $index)), $owner));
        }
        expectException(KarobarAuthorizationException::class, fn() => $service->processReceiving(paymentPayload($person, $foreignAccount, 100, $requestId('foreign-receive-account')), $owner));
        expectException(KarobarAuthorizationException::class, fn() => $service->processReceiving(paymentPayload($foreignPerson, $ownerAccount, 100, $requestId('foreign-receive-person')), $owner));
        assertClose(1000, outstanding($db, $owner, $person, 'receiving'), 'invalid receiving outstanding');
        assertClose($before, accountBalance($db, $ownerAccount), 'invalid receiving account');
        if (paymentCount($db, $owner, $person, 'returned') !== 0) throw new RuntimeException('Invalid receiving created a record');
    });

    $run('dates are strict and cannot precede original debt', function () use ($db, $service, $owner, $ownerAccount, $source, $requestId): void {
        $person = createPerson($db, $owner, 'Date Validation');
        $source($person, 'borrowed', 1000);
        foreach (['2026-02-30', '01/11/2026', '0999-12-31', '2026-01-09'] as $index => $date) {
            expectException(KarobarValidationException::class, fn() => $service->processRepayment(
                paymentPayload($person, $ownerAccount, 100, $requestId('date-' . $index), $date),
                $owner
            ));
        }
        assertClose(1000, outstanding($db, $owner, $person, 'repayment'), 'date rejection outstanding');
    });

    $run('idempotent retry creates one payment and mismatch conflicts', function () use ($db, $service, $owner, $ownerAccount, $source, $requestId): void {
        $person = createPerson($db, $owner, 'Idempotent Repayment');
        $source($person, 'borrowed', 500);
        $before = accountBalance($db, $ownerAccount);
        $key = $requestId('idempotent');
        $payload = paymentPayload($person, $ownerAccount, 200, $key);
        $first = $service->processRepayment($payload, $owner);
        $second = $service->processRepayment($payload, $owner);
        if ($first !== $second) throw new RuntimeException('Retry returned a different transaction ID');
        if (paymentCount($db, $owner, $person, 'repaid') !== 1) throw new RuntimeException('Retry created a duplicate payment');
        assertClose(300, outstanding($db, $owner, $person, 'repayment'), 'idempotent outstanding');
        assertClose($before - 200, accountBalance($db, $ownerAccount), 'idempotent account impact');
        expectException(KarobarConflictException::class, fn() => $service->processRepayment(paymentPayload($person, $ownerAccount, 100, $key), $owner));
    });

    $run('failure after payment insert rolls back all dependent state', function () use ($db, $owner, $ownerAccount, $source, $requestId): void {
        $person = createPerson($db, $owner, 'Atomic Rollback');
        $source($person, 'borrowed', 1000);
        $before = accountBalance($db, $ownerAccount);
        $probe = new RollbackProbeKarobarService();
        expectException(RuntimeException::class, fn() => $probe->processRepayment(paymentPayload($person, $ownerAccount, 300, $requestId('rollback')), $owner), 'Simulated');
        assertClose(1000, outstanding($db, $owner, $person, 'repayment'), 'rollback outstanding');
        assertClose($before, accountBalance($db, $ownerAccount), 'rollback account');
        if (paymentCount($db, $owner, $person, 'repaid') !== 0) throw new RuntimeException('Rollback left an orphan payment');
    });

    $run('payment edit and delete preserve outstanding and account integrity', function () use ($db, $service, $owner, $ownerAccount, $source, $requestId): void {
        $person = createPerson($db, $owner, 'Edit Payment');
        $otherPerson = createPerson($db, $owner, 'Edit Other Person');
        $source($person, 'borrowed', 1000);
        $source($otherPerson, 'borrowed', 1000);
        $baseline = accountBalance($db, $ownerAccount);
        $paymentId = $service->processRepayment(paymentPayload($person, $ownerAccount, 400, $requestId('edit')), $owner);
        expectException(KarobarValidationException::class, fn() => $service->updateTransaction($paymentId, ['amount' => 1001], $owner));
        expectException(KarobarValidationException::class, fn() => $service->updateTransaction($paymentId, ['person_id' => $otherPerson], $owner));
        $service->updateTransaction($paymentId, ['amount' => 300], $owner);
        assertClose(700, outstanding($db, $owner, $person, 'repayment'), 'edited outstanding');
        assertClose($baseline - 300, accountBalance($db, $ownerAccount), 'edited account');
        $service->deleteTransaction($paymentId, $owner);
        assertClose(1000, outstanding($db, $owner, $person, 'repayment'), 'deleted payment outstanding');
        assertClose($baseline, accountBalance($db, $ownerAccount), 'deleted payment account');
    });

    $run('foreign debt payment cannot be edited or deleted', function () use ($db, $service, $owner, $foreign, $foreignAccount, $requestId): void {
        $foreignPerson = createPerson($db, $foreign, 'Foreign Debt Record');
        $foreignService = new KarobarService();
        $foreignService->createTransaction([
            'person_id' => $foreignPerson,
            'account_id' => $foreignAccount,
            'type' => 'borrowed',
            'amount' => 500,
            'transaction_date' => '2026-01-10',
        ], $foreign);
        $paymentId = $foreignService->processRepayment(paymentPayload($foreignPerson, $foreignAccount, 100, $requestId('foreign-payment')), $foreign);
        expectException(KarobarAuthorizationException::class, fn() => $service->updateTransaction($paymentId, ['amount' => 50], $owner));
        expectException(KarobarAuthorizationException::class, fn() => $service->deleteTransaction($paymentId, $owner));
        if (paymentCount($db, $foreign, $foreignPerson, 'repaid') !== 1) throw new RuntimeException('Foreign record was changed');
    });

    $run('concurrent overpayment allows only one competing request', function () use ($db, $service, $owner, $ownerAccount, $source, $requestId): void {
        if (!function_exists('proc_open')) throw new RuntimeException('proc_open is unavailable');
        $person = createPerson($db, $owner, 'Concurrent Repayment');
        $source($person, 'borrowed', 1000);
        $before = accountBalance($db, $ownerAccount);
        $barrier = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sanie-karobar-' . bin2hex(random_bytes(8)) . '.start';
        $worker = __DIR__ . DIRECTORY_SEPARATOR . 'KarobarPaymentConcurrencyWorker.php';
        $processes = [];
        for ($i = 0; $i < 2; $i++) {
            $pipes = [];
            $process = proc_open([
                PHP_BINARY,
                $worker,
                (string)$owner,
                (string)$person,
                (string)$ownerAccount,
                '700',
                $requestId('concurrent-' . $i),
                $barrier,
            ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) throw new RuntimeException('Unable to start concurrency worker');
            $processes[] = [$process, $pipes];
        }
        touch($barrier);
        $results = [];
        foreach ($processes as [$process, $pipes]) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
            $decoded = json_decode($stdout, true);
            if ($exit !== 0 || !is_array($decoded)) throw new RuntimeException("Worker failed: {$stderr} {$stdout}");
            $results[] = $decoded;
        }
        if (file_exists($barrier)) unlink($barrier);
        $successes = array_filter($results, fn($result) => !empty($result['ok']));
        if (count($successes) !== 1) throw new RuntimeException('Expected exactly one successful competing repayment: ' . json_encode($results));
        assertClose(300, outstanding($db, $owner, $person, 'repayment'), 'concurrent outstanding');
        assertClose($before - 700, accountBalance($db, $ownerAccount), 'concurrent account');
        if (paymentCount($db, $owner, $person, 'repaid') !== 1) throw new RuntimeException('Concurrency created duplicate payments');
    });
} finally {
    foreach ($userIds as $userId) {
        try {
            $stmt = $db->prepare('DELETE FROM users WHERE id = ?');
            $stmt->execute([$userId]);
        } catch (Throwable $cleanupError) {
            fwrite(STDERR, 'Cleanup warning: ' . $cleanupError->getMessage() . "\n");
        }
    }
}

echo "RESULT: {$passed} passed, {$failed} failed, 0 skipped\n";
exit($failed === 0 ? 0 : 1);
