<?php

$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/AccountingService.php';
require_once __DIR__ . '/../services/BalanceService.php';
require_once __DIR__ . '/../services/KarobarService.php';
require_once __DIR__ . '/../services/KarobarOutstandingService.php';
require_once __DIR__ . '/../services/ReportingPaginationService.php';

function utsAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function utsClose(float $expected, float $actual, string $message): void {
    if (abs($expected - $actual) > 0.001) throw new RuntimeException("{$message}: expected {$expected}, got {$actual}");
}

$db = (new Database())->getConnection();
if (!$db) exit(1);
$users = [];
$passed = 0;
$failed = 0;
$run = function (string $name, callable $test) use (&$passed, &$failed): void {
    try { $test(); $passed++; echo "PASS: {$name}\n"; }
    catch (Throwable $error) { $failed++; fwrite(STDERR, "FAIL: {$name} - {$error->getMessage()}\n"); }
};

try {
    foreach (['owner', 'foreign'] as $label) {
        $stmt = $db->prepare("INSERT INTO users(email,password,first_name) VALUES(?,?,'Unified Transactions')");
        $stmt->execute(['unified-' . $label . '-' . bin2hex(random_bytes(5)) . '@example.invalid', password_hash('fixture', PASSWORD_DEFAULT)]);
        $users[$label] = (int)$db->lastInsertId();
    }
    $owner = $users['owner'];
    $foreign = $users['foreign'];
    $account = function (int $user, string $name) use ($db): int {
        $stmt = $db->prepare("INSERT INTO accounts(user_id,name,type,balance,opening_balance,is_active,is_default) VALUES(?,?,'cash',10000,10000,1,1)");
        $stmt->execute([$user, $name]);
        return (int)$db->lastInsertId();
    };
    $person = function (int $user, string $name) use ($db): int {
        $stmt = $db->prepare("INSERT INTO people(user_id,name,type,status) VALUES(?,?,'vendor','active')");
        $stmt->execute([$user, $name]);
        return (int)$db->lastInsertId();
    };
    $category = function (int $user, string $name) use ($db): int {
        $stmt = $db->prepare("INSERT INTO categories(user_id,name,type,status,is_default) VALUES(?,?,'expense','active',0)");
        $stmt->execute([$user, $name]);
        return (int)$db->lastInsertId();
    };

    $ownerAccount = $account($owner, 'Unified Cash');
    $foreignAccount = $account($foreign, 'Foreign Cash');
    $ownerPerson = $person($owner, 'Unified Vendor');
    $foreignPerson = $person($foreign, 'Foreign Vendor');
    $ownerCategory = $category($owner, 'Unified Expense');
    $foreignCategory = $category($foreign, 'Foreign Expense');

    $accounting = new AccountingService();
    $karobar = new KarobarService();
    $outstanding = new KarobarOutstandingService();
    $reporting = new ReportingPaginationService($db);
    $balances = new BalanceService();
    $date = '2026-09-02';

    $personalId = (int)$accounting->createTransaction($owner, [
        'account_id' => $ownerAccount, 'category_id' => $ownerCategory,
        'type' => 'expense', 'amount' => 200, 'date' => $date,
        'description' => 'Personal groceries', 'payment_method' => 'cash',
        'client_request_id' => 'uts-personal-' . bin2hex(random_bytes(4)),
    ]);
    $lentId = (int)$karobar->createTransaction([
        'person_id' => $ownerPerson, 'account_id' => $ownerAccount,
        'type' => 'lent', 'amount' => 1000, 'transaction_date' => $date,
        'due_date' => '2026-10-02', 'description' => 'Vendor advance',
        'client_request_id' => 'uts-lent-' . bin2hex(random_bytes(4)),
    ], $owner);
    $credit = $karobar->processCreditPurchase([
        'creditor_id' => $ownerPerson, 'category_id' => $ownerCategory,
        'amount' => 300, 'date' => $date, 'due_date' => '2026-10-05',
        'description' => 'Inventory on credit',
        'client_request_id' => 'uts-credit-' . bin2hex(random_bytes(4)),
    ], $owner);
    $karobar->createTransaction([
        'person_id' => $foreignPerson, 'account_id' => $foreignAccount,
        'type' => 'borrowed', 'amount' => 9999, 'transaction_date' => $date,
        'description' => 'Foreign private row',
        'client_request_id' => 'uts-foreign-' . bin2hex(random_bytes(4)),
    ], $foreign);
    $accounting->createTransaction($foreign, [
        'account_id' => $foreignAccount, 'category_id' => $foreignCategory,
        'type' => 'expense', 'amount' => 8888, 'date' => $date,
        'description' => 'Foreign private transaction',
        'client_request_id' => 'uts-foreign-personal-' . bin2hex(random_bytes(4)),
    ]);

    $run('all scope returns one row per business event', function () use ($reporting, $owner, $personalId, $lentId, $credit): void {
        $page = $reporting->transactionPage($owner, ['scope' => 'all'], 1, 20);
        utsAssert($page['pagination']['total_rows'] === 3, 'combined list did not contain exactly three events');
        $ids = array_column($page['transactions'], 'id');
        utsAssert(count($ids) === count(array_unique($ids)), 'combined list contains duplicate identities');
        utsAssert(in_array((string)$personalId, $ids, true), 'personal transaction missing');
        utsAssert(in_array('karobar:' . $lentId, $ids, true), 'standalone Karobar transaction missing');
        utsAssert(in_array((string)$credit['transaction_id'], $ids, true), 'linked credit transaction missing');
        utsAssert(!in_array('karobar:' . $credit['karobar_id'], $ids, true), 'linked payable appeared as a duplicate row');
    });

    $run('personal and Karobar scopes split cleanly', function () use ($reporting, $owner): void {
        $personal = $reporting->transactionPage($owner, ['scope' => 'personal'], 1, 20);
        $karobar = $reporting->transactionPage($owner, ['scope' => 'karobar'], 1, 20);
        utsAssert($personal['pagination']['total_rows'] === 1, 'personal scope count is incorrect');
        utsAssert($karobar['pagination']['total_rows'] === 2, 'Karobar scope count is incorrect');
        utsAssert(array_reduce($personal['transactions'], fn($ok, $row) => $ok && $row['scope'] === 'personal', true), 'personal scope leaked Karobar');
        utsAssert(array_reduce($karobar['transactions'], fn($ok, $row) => $ok && $row['scope'] === 'karobar', true), 'Karobar scope leaked personal rows');
    });

    $run('filters and tenant isolation apply to both sources', function () use ($reporting, $owner, $ownerAccount): void {
        $personSearch = $reporting->transactionPage($owner, ['scope' => 'all', 'search' => 'Unified Vendor'], 1, 20);
        utsAssert($personSearch['pagination']['total_rows'] === 2, 'person search did not find both Karobar events');
        $accountRows = $reporting->transactionPage($owner, ['scope' => 'all', 'account_id' => $ownerAccount], 1, 20);
        utsAssert($accountRows['pagination']['total_rows'] === 2, 'account filter did not combine personal and standalone Karobar rows');
        $all = $reporting->transactionPage($owner, ['scope' => 'all'], 1, 20);
        utsAssert(!array_filter($all['transactions'], fn($row) => str_contains((string)$row['description'], 'Foreign')), 'foreign tenant data leaked');
    });

    $run('pagination remains exact across mixed sources', function () use ($reporting, $owner): void {
        $seen = [];
        for ($pageNumber = 1; $pageNumber <= 3; $pageNumber++) {
            $page = $reporting->transactionPage($owner, ['scope' => 'all'], $pageNumber, 1);
            utsAssert(count($page['transactions']) === 1, "page {$pageNumber} size is incorrect");
            $seen[] = $page['transactions'][0]['id'];
        }
        utsAssert(count(array_unique($seen)) === 3, 'mixed-source pagination repeated an event');
    });

    $run('scoped reports distinguish sources without double counting', function () use ($reporting, $owner): void {
        $all = $reporting->incomeExpensePage($owner, ['scope' => 'all'], 1, 20);
        $personal = $reporting->incomeExpensePage($owner, ['scope' => 'personal'], 1, 20);
        $karobar = $reporting->incomeExpensePage($owner, ['scope' => 'karobar'], 1, 20);
        utsClose(1500, (float)$all['summary']['total_expense'], 'combined report expense');
        utsClose(200, (float)$personal['summary']['total_expense'], 'personal report expense');
        utsClose(1300, (float)$karobar['summary']['total_expense'], 'Karobar report expense');
        utsAssert($karobar['pagination']['total_rows'] === 2, 'Karobar report duplicated the linked purchase');
    });

    $run('account balance counts each cash movement once', function () use ($balances, $ownerAccount, $owner): void {
        utsClose(8800, $balances->calculateAccountBalance($ownerAccount, $owner), 'mixed account balance');
    });

    $run('Karobar balances count each business event once', function () use ($outstanding, $owner, $ownerPerson): void {
        $position = $outstanding->getPersonPosition($owner, $ownerPerson);
        utsClose(1000, (float)$position['receivable_outstanding'], 'Karobar receivable');
        utsClose(300, (float)$position['payable_outstanding'], 'Karobar payable');
    });

    $run('standalone edit is reflected in unified list and balance', function () use ($karobar, $reporting, $balances, $lentId, $ownerPerson, $ownerAccount, $owner, $date): void {
        $karobar->updateTransaction($lentId, [
            'person_id' => $ownerPerson, 'account_id' => $ownerAccount,
            'type' => 'lent', 'amount' => 1200, 'transaction_date' => $date,
            'due_date' => '2026-10-02', 'description' => 'Vendor advance updated',
        ], $owner);
        $page = $reporting->transactionPage($owner, ['scope' => 'karobar', 'search' => 'updated'], 1, 20);
        utsAssert($page['pagination']['total_rows'] === 1 && (float)$page['transactions'][0]['amount'] === 1200.0, 'edited Karobar event was stale');
        utsClose(8600, $balances->calculateAccountBalance($ownerAccount, $owner), 'balance after Karobar edit');
    });

    $run('standalone delete removes one event and restores balance', function () use ($karobar, $reporting, $balances, $lentId, $ownerAccount, $owner): void {
        $karobar->deleteTransaction($lentId, $owner);
        $page = $reporting->transactionPage($owner, ['scope' => 'all'], 1, 20);
        utsAssert($page['pagination']['total_rows'] === 2, 'deleted Karobar event remained visible');
        utsAssert(!array_filter($page['transactions'], fn($row) => $row['id'] === 'karobar:' . $lentId), 'deleted Karobar identity remained visible');
        utsClose(9800, $balances->calculateAccountBalance($ownerAccount, $owner), 'balance after Karobar delete');
    });

    $run('linked delete removes both source records and visible event', function () use ($karobar, $reporting, $credit, $owner, $db): void {
        $karobar->deleteCreditPurchase($credit['karobar_id'], $owner, $credit['version']);
        $page = $reporting->transactionPage($owner, ['scope' => 'all'], 1, 20);
        utsAssert($page['pagination']['total_rows'] === 1, 'deleted linked event remained in combined list');
        $stmt = $db->prepare('SELECT COUNT(*) FROM transactions WHERE id=?');
        $stmt->execute([$credit['transaction_id']]);
        utsAssert((int)$stmt->fetchColumn() === 0, 'linked expense survived delete');
        $stmt = $db->prepare('SELECT COUNT(*) FROM karobar_transactions WHERE id=?');
        $stmt->execute([$credit['karobar_id']]);
        utsAssert((int)$stmt->fetchColumn() === 0, 'linked payable survived delete');
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
