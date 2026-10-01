<?php

$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/ReportingPaginationService.php';

function teqAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function teqClose(float $expected, float $actual, string $message): void { if (abs($expected - $actual) > .001) throw new RuntimeException("{$message}: expected {$expected}, got {$actual}"); }

$db = (new Database())->getConnection();
$users = [];
$passed = 0;
$failed = 0;
$run = function (string $name, callable $test) use (&$passed, &$failed): void {
    try { $test(); $passed++; echo "PASS: {$name}\n"; }
    catch (Throwable $error) { $failed++; fwrite(STDERR, "FAIL: {$name} - {$error->getMessage()}\n"); }
};

try {
    foreach (['owner', 'foreign'] as $label) {
        $stmt = $db->prepare("INSERT INTO users(email,password,first_name) VALUES(?,?,'Phase Ten')");
        $stmt->execute(['teq-' . $label . '-' . bin2hex(random_bytes(5)) . '@example.invalid', password_hash('fixture', PASSWORD_DEFAULT)]);
        $users[$label] = (int)$db->lastInsertId();
    }
    $owner = $users['owner'];
    $foreign = $users['foreign'];
    $account = function (int $user, string $name) use ($db): int {
        $stmt = $db->prepare("INSERT INTO accounts(user_id,name,type,balance,opening_balance,is_active,is_default) VALUES(?,?,'cash',0,0,1,0)");
        $stmt->execute([$user, $name]); return (int)$db->lastInsertId();
    };
    $category = function (int $user, string $name, string $type) use ($db): int {
        $stmt = $db->prepare("INSERT INTO categories(user_id,name,type,status,is_default) VALUES(?,?,?,'active',0)");
        $stmt->execute([$user, $name, $type]); return (int)$db->lastInsertId();
    };
    $cash = $account($owner, 'TEQ Cash');
    $bank = $account($owner, 'TEQ Bank');
    $foreignAccount = $account($foreign, 'TEQ Foreign');
    $expense = $category($owner, 'TEQ Food', 'expense');
    $income = $category($owner, 'TEQ Salary', 'income');
    $foreignCategory = $category($foreign, 'TEQ Foreign Category', 'expense');
    $stmt = $db->prepare("INSERT INTO subcategories(category_id,user_id,name,status,sort_order) VALUES(?,?,'TEQ Groceries','active',0)");
    $stmt->execute([$expense, $owner]); $subcategory = (int)$db->lastInsertId();

    $insert = $db->prepare("INSERT INTO transactions(user_id,account_id,from_account_id,to_account_id,category_id,subcategory_id,amount,type,payment_method,date,description,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,'2026-08-25 10:00:00')");
    for ($i = 1; $i <= 100; $i++) {
        $isIncome = $i % 2 === 0;
        $insert->execute([$owner, $isIncome ? $bank : $cash, $isIncome ? null : $cash, null, $isIncome ? $income : $expense, $isIncome ? null : $subcategory, $isIncome ? 20 : 10, $isIncome ? 'income' : 'expense', 'cash', '2026-08-' . str_pad((string)(1 + (($i - 1) % 20)), 2, '0', STR_PAD_LEFT), $i === 1 ? '=DANGEROUS()' : "TEQ row {$i}"]);
    }
    $stmt = $db->prepare("INSERT INTO recurring_transactions(user_id,type,amount,account_id,category_id,frequency,start_date,next_occurrence,is_active) VALUES(?,'expense',10,?,?,'monthly','2026-08-01','2026-09-01',1)");
    $stmt->execute([$owner, $cash, $expense]); $definition = (int)$db->lastInsertId();
    $db->prepare("UPDATE transactions SET recurring_definition_id=?,recurring_occurrence_date='2026-08-01' WHERE user_id=? AND description='=DANGEROUS()'")->execute([$definition, $owner]);

    $insert->execute([$foreign, $foreignAccount, $foreignAccount, null, $foreignCategory, null, 999999, 'expense', 'cash', '2026-08-10', 'TEQ foreign']);
    $insert->execute([$owner, null, $cash, $bank, null, null, 500, 'transfer', null, '2026-09-01', 'TEQ transfer']);
    $insert->execute([$owner, $cash, $cash, null, null, null, 200, 'goal_contribution', null, '2026-09-02', 'TEQ goal']);
    $insert->execute([$owner, $cash, $cash, null, $expense, null, 5, 'expense', 'cash', '2026-09-03', 'TEQ transfer fee']);

    $service = new ReportingPaginationService($db);
    $august = ['start_date' => '2026-08-01', 'end_date' => '2026-08-31'];

    $run('1, 50, 51 and 100 row boundaries preserve complete counts', function () use ($service, $owner, $august): void {
        $one = $service->transactionPage($owner, array_merge($august, ['search' => '=DANGEROUS()']), 1, 50);
        teqAssert($one['pagination']['total_rows'] === 1 && count($one['transactions']) === 1, 'one-row result is wrong');
        foreach ([50, 51, 100] as $limit) {
            $result = $service->transactionPage($owner, $august, 1, $limit);
            teqAssert($result['pagination']['total_rows'] === 100, "{$limit}-row total changed");
            teqAssert(count($result['transactions']) === $limit, "{$limit}-row page is truncated incorrectly");
        }
    });
    $run('full export page union contains every matching record exactly once', function () use ($service, $owner, $august): void {
        $ids = [];
        for ($page = 1; $page <= 2; $page++) foreach ($service->transactionPage($owner, $august, $page, 50)['transactions'] as $row) $ids[] = (int)$row['id'];
        teqAssert(count($ids) === 100 && count(array_unique($ids)) === 100, 'full page union has omissions or duplicates');
    });
    $run('quick totals are identical on first second and last page', function () use ($service, $owner, $august): void {
        foreach ([1, 2, 4] as $page) {
            $result = $service->transactionPage($owner, $august, $page, 25);
            teqClose(1000, (float)$result['summary']['total_income'], "page {$page} income");
            teqClose(500, (float)$result['summary']['total_expense'], "page {$page} expense");
            teqAssert($result['summary']['transaction_count'] === 100, "page {$page} count");
        }
    });
    $run('combined filters use one predicate for rows count and totals', function () use ($service, $owner, $cash, $expense, $subcategory): void {
        $dateAccount = $service->transactionPage($owner, ['start_date' => '2026-08-01', 'end_date' => '2026-08-10', 'account_id' => $cash], 1, 200);
        teqAssert($dateAccount['pagination']['total_rows'] === 25, 'date + account count is wrong');
        $dateCategory = $service->transactionPage($owner, ['start_date' => '2026-08-01', 'end_date' => '2026-08-10', 'category_id' => $expense], 1, 200);
        teqAssert($dateCategory['pagination']['total_rows'] === 25, 'date + category count is wrong');
        $searchType = $service->transactionPage($owner, ['search' => 'row 20', 'type' => 'income'], 1, 200);
        teqAssert($searchType['pagination']['total_rows'] === 1 && $searchType['transactions'][0]['type'] === 'income', 'search + type mismatch');
        $subPayment = $service->transactionPage($owner, ['subcategory_id' => $subcategory, 'payment_method' => 'cash'], 1, 200);
        teqAssert($subPayment['pagination']['total_rows'] === 50, 'subcategory + payment method mismatch');
    });
    $run('empty and owner-isolated results stay authoritative', function () use ($service, $owner, $foreignAccount): void {
        $empty = $service->transactionPage($owner, ['start_date' => '2040-01-01', 'end_date' => '2040-01-31'], 1, 50);
        teqAssert($empty['transactions'] === [] && $empty['pagination']['total_rows'] === 0, 'empty rows/count are wrong');
        teqClose(0, (float)$empty['summary']['total_income'], 'empty income');
        $foreign = $service->transactionPage($owner, ['account_id' => $foreignAccount], 1, 50);
        teqAssert($foreign['pagination']['total_rows'] === 0, 'foreign account data leaked');
    });
    $run('recurring provenance is retained and internal movements keep existing totals', function () use ($service, $owner, $definition): void {
        $recurring = $service->transactionPage($owner, ['search' => '=DANGEROUS()'], 1, 50);
        teqAssert((int)$recurring['transactions'][0]['recurring_definition_id'] === $definition, 'recurring definition provenance was lost');
        teqAssert($recurring['transactions'][0]['recurring_occurrence_date'] === '2026-08-01', 'scheduled occurrence date was lost');
        $movement = $service->transactionPage($owner, ['start_date' => '2026-09-01', 'end_date' => '2026-09-30'], 1, 50);
        teqAssert($movement['pagination']['total_rows'] === 3, 'internal movement rows disappeared');
        teqClose(0, (float)$movement['summary']['total_income'], 'internal movement income changed');
        teqClose(5, (float)$movement['summary']['total_expense'], 'internal movement expense changed');
    });
} finally {
    if ($db->inTransaction()) $db->rollBack();
    foreach (array_reverse($users) as $id) {
        try {
            foreach (['transactions', 'recurring_transactions', 'subcategories', 'categories', 'accounts'] as $table) $db->prepare("DELETE FROM {$table} WHERE user_id=?")->execute([$id]);
            $db->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
        } catch (Throwable $error) { fwrite(STDERR, "Cleanup warning: {$error->getMessage()}\n"); }
    }
}

echo "RESULT: {$passed} passed, {$failed} failed, 0 skipped\n";
exit($failed ? 1 : 0);
