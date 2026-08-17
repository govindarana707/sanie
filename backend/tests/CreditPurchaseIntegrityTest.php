<?php

$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/KarobarService.php';
require_once __DIR__ . '/../services/BalanceService.php';

function cpAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function cpClose(float $expected, float $actual, string $message): void {
    if (abs($expected - $actual) > 0.001) throw new RuntimeException("{$message}: expected {$expected}, got {$actual}");
}
function cpExpect(string $class, callable $operation, string $contains = ''): void {
    try { $operation(); } catch (Throwable $e) {
        if (!is_a($e, $class)) throw new RuntimeException("Expected {$class}, got " . get_class($e) . ': ' . $e->getMessage());
        if ($contains !== '' && stripos($e->getMessage(), $contains) === false) throw new RuntimeException("Missing '{$contains}' in: {$e->getMessage()}");
        return;
    }
    throw new RuntimeException("Expected {$class}, but operation succeeded");
}

$db = (new Database())->getConnection();
if (!$db) exit(1);
$users = [];
$passed = 0;
$failed = 0;
$seq = 0;
$run = function (string $name, callable $test) use (&$passed, &$failed): void {
    try { $test(); $passed++; echo "PASS: {$name}\n"; }
    catch (Throwable $e) { $failed++; fwrite(STDERR, "FAIL: {$name} - {$e->getMessage()}\n"); }
};
$request = function (string $label) use (&$seq): string { return 'req_credit_' . (++$seq) . '_' . $label; };

try {
    foreach (['owner', 'foreign'] as $label) {
        $stmt = $db->prepare("INSERT INTO users (email,password,first_name) VALUES (?,?,'Credit Test')");
        $stmt->execute(['credit-' . $label . '-' . bin2hex(random_bytes(5)) . '@example.invalid', password_hash('fixture', PASSWORD_DEFAULT)]);
        $users[$label] = (int)$db->lastInsertId();
    }
    $owner = $users['owner'];
    $foreign = $users['foreign'];
    $makePerson = function (int $user, string $name) use ($db): int {
        $stmt = $db->prepare("INSERT INTO people (user_id,name,type) VALUES (?,?,'vendor')");
        $stmt->execute([$user, $name]);
        return (int)$db->lastInsertId();
    };
    $makeCategory = function (int $user, string $name) use ($db): int {
        $stmt = $db->prepare("INSERT INTO categories (user_id,name,type,status,is_default) VALUES (?,?,'expense','active',0)");
        $stmt->execute([$user, $name]);
        return (int)$db->lastInsertId();
    };
    $makeAccount = function (int $user, float $opening) use ($db): int {
        $stmt = $db->prepare("INSERT INTO accounts (user_id,name,type,balance,opening_balance,is_active,is_default) VALUES (?,'Credit Cash','cash',?,?,1,0)");
        $stmt->execute([$user, $opening, $opening]);
        return (int)$db->lastInsertId();
    };
    $personA = $makePerson($owner, 'Vendor A');
    $personB = $makePerson($owner, 'Vendor B');
    $personValid = $makePerson($owner, 'Vendor Valid Repayment');
    $personInvalid = $makePerson($owner, 'Vendor Invalid Repayment');
    $personIdempotent = $makePerson($owner, 'Vendor Idempotent');
    $personRollback = $makePerson($owner, 'Vendor Rollback');
    $personDelete = $makePerson($owner, 'Vendor Delete');
    $foreignPerson = $makePerson($foreign, 'Foreign Vendor');
    $categoryA = $makeCategory($owner, 'Credit Supplies');
    $categoryB = $makeCategory($owner, 'Credit Equipment');
    $foreignCategory = $makeCategory($foreign, 'Foreign Expense');
    $account = $makeAccount($owner, 20000);
    $service = new KarobarService();
    $balances = new BalanceService();
    $payload = fn($amount, string $rid, int $person = null, int $category = null, string $date = '2026-08-01'): array => [
        'amount' => $amount, 'creditor_id' => $person ?? $personA,
        'category_id' => $category ?? $categoryA, 'subcategory_id' => null,
        'date' => $date, 'description' => 'Office supplies', 'due_date' => '2026-09-01',
        'client_request_id' => $rid,
    ];

    $run('create recognizes expense and payable without cash movement', function () use ($service, $payload, $request, $owner, $db, $balances, $account): void {
        $r = $service->processCreditPurchase($payload(5000, $request('create')), $owner);
        cpAssert((int)$r['transaction']['karobar_transaction_id'] === $r['karobar_id'], 'transaction link missing');
        cpAssert((int)$r['payable']['expense_transaction_id'] === $r['transaction_id'], 'reverse payable link missing');
        cpClose(5000, (float)$r['transaction']['amount'], 'expense amount');
        cpClose(5000, (float)$r['payable']['amount'], 'payable amount');
        cpClose(5000, (float)$r['outstanding'], 'outstanding');
        cpClose(20000, $balances->calculateAccountBalance($account, $owner), 'credit creation changed cash');
        $stmt = $db->prepare("SELECT SUM(amount) FROM transactions WHERE user_id=? AND type='expense' AND payment_method='credit'");
        $stmt->execute([$owner]);
        cpClose(5000, (float)$stmt->fetchColumn(), 'credit expense reporting');
        $service->deleteCreditPurchase($r['karobar_id'], $owner, $r['version']);
    });

    $run('amount, person, date, category and description edit as one pair', function () use ($service, $payload, $request, $owner, $personB, $categoryB): void {
        $r = $service->processCreditPurchase($payload(5000, $request('edit_fields')), $owner);
        $r = $service->updateCreditPurchase($r['karobar_id'], [
            'amount' => 7500, 'person_id' => $personB, 'date' => '2026-08-02',
            'category_id' => $categoryB, 'description' => 'Equipment on credit', 'due_date' => null,
        ], $owner, $r['version']);
        cpClose(7500, (float)$r['transaction']['amount'], 'edited expense');
        cpClose(7500, (float)$r['payable']['amount'], 'edited payable');
        cpAssert((int)$r['payable']['person_id'] === $personB, 'person did not move');
        cpAssert($r['transaction']['date'] === '2026-08-02' && $r['payable']['transaction_date'] === '2026-08-02', 'dates diverged');
        cpAssert((int)$r['transaction']['category_id'] === $categoryB, 'category did not change');
        cpAssert($r['transaction']['description'] === $r['payable']['description'], 'descriptions diverged');
        cpAssert($r['payable']['due_date'] === null, 'due date was not cleared');
        $service->deleteCreditPurchase($r['karobar_id'], $owner, $r['version']);
    });

    $run('valid reduction after repayment updates outstanding', function () use ($service, $payload, $request, $owner, $personValid, $account): void {
        $r = $service->processCreditPurchase($payload(10000, $request('valid_reduce'), $personValid), $owner);
        $service->processRepayment(['person_id'=>$personValid,'account_id'=>$account,'amount'=>4000,'transaction_date'=>'2026-08-05','client_request_id'=>$request('repay_valid')], $owner);
        $r = $service->updateCreditPurchase($r['karobar_id'], ['amount'=>7000], $owner, $r['version']);
        cpClose(7000, (float)$r['transaction']['amount'], 'expense after valid reduction');
        cpClose(7000, (float)$r['payable']['amount'], 'payable after valid reduction');
        cpClose(3000, (float)$r['outstanding'], 'outstanding after valid reduction');
        cpExpect(KarobarConflictException::class, fn() => $service->deleteCreditPurchase($r['karobar_id'], $owner, $r['version']), 'repayment history');
        cpClose(3000, (float)$service->getCreditPurchaseResource($r['karobar_id'], $owner)['outstanding'], 'delete conflict changed repayment history');
    });

    $run('invalid reduction, person change and contradictory date roll back', function () use ($service, $payload, $request, $owner, $personInvalid, $personB, $account): void {
        $r = $service->processCreditPurchase($payload(10000, $request('invalid_reduce'), $personInvalid), $owner);
        $service->processRepayment(['person_id'=>$r['payable']['person_id'],'account_id'=>$account,'amount'=>6000,'transaction_date'=>'2026-08-06','client_request_id'=>$request('repay_invalid')], $owner);
        cpExpect(KarobarConflictException::class, fn() => $service->updateCreditPurchase($r['karobar_id'], ['amount'=>5000], $owner, $r['version']), 'lower');
        cpExpect(KarobarConflictException::class, fn() => $service->updateCreditPurchase($r['karobar_id'], ['person_id'=>$personB], $owner, $r['version']), 'creditor');
        cpExpect(KarobarConflictException::class, fn() => $service->updateCreditPurchase($r['karobar_id'], ['date'=>'2026-08-07'], $owner, $r['version']), 'repayment date');
        $unchanged = $service->getCreditPurchaseResource($r['karobar_id'], $owner);
        cpClose(10000, (float)$unchanged['transaction']['amount'], 'expense changed after rejection');
        cpClose(10000, (float)$unchanged['payable']['amount'], 'payable changed after rejection');
        cpClose(4000, (float)$unchanged['outstanding'], 'outstanding changed after rejection');
    });

    $run('idempotent retry returns one pair and mismatch conflicts', function () use ($service, $payload, $request, $owner, $db, $personIdempotent): void {
        $rid = $request('idempotent');
        $first = $service->processCreditPurchase($payload(1200, $rid, $personIdempotent), $owner);
        $second = $service->processCreditPurchase($payload(1200, $rid, $personIdempotent), $owner);
        cpAssert($first['transaction_id'] === $second['transaction_id'] && $first['karobar_id'] === $second['karobar_id'], 'retry returned another pair');
        $stmt = $db->prepare('SELECT COUNT(*) FROM transactions WHERE user_id=? AND client_request_id=?');
        $stmt->execute([$owner, $rid]);
        cpAssert((int)$stmt->fetchColumn() === 1, 'retry duplicated expense');
        cpExpect(KarobarConflictException::class, fn() => $service->processCreditPurchase($payload(1201, $rid, $personIdempotent), $owner), 'different');
        $service->deleteCreditPurchase($first['karobar_id'], $owner, $first['version']);
    });

    foreach (['after_payable_create', 'after_expense_create'] as $point) {
        $run("create rollback at {$point}", function () use ($payload, $request, $owner, $db, $point): void {
            $probe = new KarobarService();
            $probe->setCreditPurchaseFailureInjector(fn(string $at) => $at === $point ? throw new RuntimeException('Injected failure') : null);
            $rid = $request($point);
            cpExpect(RuntimeException::class, fn() => $probe->processCreditPurchase($payload(333, $rid), $owner), 'Injected');
            $stmt = $db->prepare('SELECT COUNT(*) FROM transactions WHERE user_id=? AND client_request_id=?');
            $stmt->execute([$owner, $rid]);
            cpAssert((int)$stmt->fetchColumn() === 0, 'expense survived create rollback');
            $stmt = $db->prepare('SELECT COUNT(*) FROM karobar_transactions WHERE user_id=? AND client_request_id=?');
            $stmt->execute([$owner, $rid]);
            cpAssert((int)$stmt->fetchColumn() === 0, 'payable survived create rollback');
        });
    }

    $run('edit rollback and stale version keep pair consistent', function () use ($service, $payload, $request, $owner, $personRollback): void {
        $r = $service->processCreditPurchase($payload(900, $request('edit_rollback'), $personRollback), $owner);
        $probe = new KarobarService();
        $probe->setCreditPurchaseFailureInjector(fn(string $at) => $at === 'after_payable_update' ? throw new RuntimeException('Injected edit failure') : null);
        cpExpect(RuntimeException::class, fn() => $probe->updateCreditPurchase($r['karobar_id'], ['amount'=>1000], $owner, $r['version']), 'Injected');
        $same = $service->getCreditPurchaseResource($r['karobar_id'], $owner);
        cpClose(900, (float)$same['transaction']['amount'], 'expense changed on edit rollback');
        cpClose(900, (float)$same['payable']['amount'], 'payable changed on edit rollback');
        $updated = $service->updateCreditPurchase($r['karobar_id'], ['amount'=>950], $owner, $r['version']);
        cpExpect(KarobarConflictException::class, fn() => $service->updateCreditPurchase($r['karobar_id'], ['amount'=>975], $owner, $r['version']), 'changed elsewhere');
        $service->deleteCreditPurchase($updated['karobar_id'], $owner, $updated['version']);
    });

    $run('foreign references and invalid amounts are rejected', function () use ($service, $payload, $request, $owner, $foreignPerson, $foreignCategory): void {
        foreach ([0, -1, 'NaN', 'INF', '1.001'] as $amount) {
            cpExpect(KarobarValidationException::class, fn() => $service->processCreditPurchase($payload($amount, $request('bad_amount')), $owner));
        }
        cpExpect(KarobarAuthorizationException::class, fn() => $service->processCreditPurchase($payload(1, $request('foreign_person'), $foreignPerson), $owner));
        cpExpect(KarobarAuthorizationException::class, fn() => $service->processCreditPurchase($payload(1, $request('foreign_category'), null, $foreignCategory), $owner));
    });

    $run('delete without repayments removes both linked rows', function () use ($service, $payload, $request, $owner, $db, $personDelete): void {
        $r = $service->processCreditPurchase($payload(444, $request('delete'), $personDelete), $owner);
        $service->deleteCreditPurchaseByTransaction($r['transaction_id'], $owner, $r['version']);
        $stmt = $db->prepare('SELECT COUNT(*) FROM transactions WHERE id=?'); $stmt->execute([$r['transaction_id']]);
        cpAssert((int)$stmt->fetchColumn() === 0, 'expense survived delete');
        $stmt = $db->prepare('SELECT COUNT(*) FROM karobar_transactions WHERE id=?'); $stmt->execute([$r['karobar_id']]);
        cpAssert((int)$stmt->fetchColumn() === 0, 'payable survived delete');
    });
} finally {
    foreach ($users as $userId) {
        try { $db->prepare('DELETE FROM transactions WHERE user_id=?')->execute([$userId]); } catch (Throwable $ignored) {}
        try { $db->prepare('DELETE FROM karobar_transactions WHERE user_id=?')->execute([$userId]); } catch (Throwable $ignored) {}
        try { $db->prepare('DELETE FROM people WHERE user_id=?')->execute([$userId]); } catch (Throwable $ignored) {}
        try { $db->prepare('DELETE FROM categories WHERE user_id=?')->execute([$userId]); } catch (Throwable $ignored) {}
        try { $db->prepare('DELETE FROM accounts WHERE user_id=?')->execute([$userId]); } catch (Throwable $ignored) {}
        try { $db->prepare('DELETE FROM users WHERE id=?')->execute([$userId]); } catch (Throwable $ignored) {}
    }
}
echo "RESULT: {$passed} passed, {$failed} failed, 0 skipped\n";
exit($failed === 0 ? 0 : 1);
