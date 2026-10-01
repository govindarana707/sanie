<?php

$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/TransactionImportService.php';
require_once __DIR__ . '/../services/BalanceService.php';
require_once __DIR__ . '/../services/ReportingPaginationService.php';
require_once __DIR__ . '/../models/Budget.php';

function ciAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function ciClose(float $expected, float $actual, string $message): void { ciAssert(abs($expected - $actual) < .001, "{$message}: expected {$expected}, got {$actual}"); }

$db = (new Database())->getConnection();
$users = [];
try {
    foreach (['owner', 'foreign'] as $label) {
        $stmt = $db->prepare('INSERT INTO users(email,password,first_name) VALUES(?,?,?)');
        $stmt->execute(['csv-' . $label . '-' . bin2hex(random_bytes(5)) . '@example.invalid', password_hash('fixture', PASSWORD_DEFAULT), 'CSV Import']);
        $users[$label] = (int)$db->lastInsertId();
    }
    $owner = $users['owner'];
    $foreign = $users['foreign'];
    $account = function (int $user, string $name, float $opening) use ($db): int {
        $stmt = $db->prepare("INSERT INTO accounts(user_id,name,type,balance,opening_balance,is_active,is_default) VALUES(?,?,'cash',?,?,1,0)");
        $stmt->execute([$user, $name, $opening, $opening]);
        return (int)$db->lastInsertId();
    };
    $category = function (int $user, string $name, string $type) use ($db): int {
        $stmt = $db->prepare("INSERT INTO categories(user_id,name,type,status,is_default) VALUES(?,?,?,'active',0)");
        $stmt->execute([$user, $name, $type]);
        return (int)$db->lastInsertId();
    };

    $cash = $account($owner, 'CSV Cash', 1000);
    $bank = $account($owner, 'CSV Bank', 0);
    $expenseCategory = $category($owner, 'CSV Food', 'expense');
    $incomeCategory = $category($owner, 'CSV Salary', 'income');
    $transportCategory = $category($owner, 'CSV Transport', 'expense');
    $stmt = $db->prepare("INSERT INTO subcategories(category_id,user_id,name,status,sort_order) VALUES(?,?,'CSV Groceries','active',0)");
    $stmt->execute([$expenseCategory, $owner]);
    $stmt = $db->prepare("INSERT INTO subcategories(category_id,user_id,name,status,sort_order) VALUES(?,?,'CSV Bus','active',0)");
    $stmt->execute([$transportCategory, $owner]);
    $account($foreign, 'Foreign Wallet', 1000);
    $category($foreign, 'Foreign Category', 'expense');

    $monthStart = date('Y-m-01');
    $monthEnd = date('Y-m-t');
    $today = date('Y-m-d');
    $budgetId = (int)(new Budget())->create([
        'user_id' => $owner, 'name' => 'CSV Food Budget', 'amount' => 500, 'period' => 'monthly',
        'category_id' => $expenseCategory, 'subcategory_id' => null,
        'start_date' => $monthStart, 'end_date' => $monthEnd, 'alert_threshold' => 80, 'is_active' => 1,
    ]);
    $service = new TransactionImportService();
    $header = "Date,Type,Amount,Account,From Account,To Account,Category,Subcategory,Payment Method,Description\n";

    $main = $header
        . "{$today},income,200,CSV Bank,,,CSV Salary,,cash,Imported salary\n"
        . "{$today},expense,100,CSV Cash,,,CSV Food,CSV Groceries,cash,Imported food\n"
        . "{$today},transfer,50,,CSV Cash,CSV Bank,,,,Imported transfer\n"
        . "{$today},expense,25,CSV Cash,,,CSV Food,,cash,Duplicate-looking expense\n"
        . "{$today},expense,25,CSV Cash,,,CSV Food,,cash,Duplicate-looking expense\n";
    $mainHash = hash('sha256', $main);
    $preview = $service->preview($owner, $main, $mainHash);
    ciAssert($preview['counts'] === ['total_rows' => 5, 'ready' => 5, 'invalid' => 0, 'unsupported' => 0], 'valid preview classification failed');
    $first = $service->process($owner, $main, $mainHash);
    ciAssert($first['counts']['imported'] === 5 && $first['counts']['failed'] === 0, 'basic income/expense/transfer import failed');
    ciAssert(count(array_unique(array_column($first['rows'], 'transaction_id'))) === 5, 'identical rows at different positions collapsed');
    $second = $service->process($owner, $main, $mainHash);
    ciAssert($second['counts']['imported'] === 0 && $second['counts']['already_imported'] === 5, 'unchanged entire-file retry was not idempotent');

    $balance = new BalanceService();
    ciClose(800, $balance->calculateAccountBalance($cash, $owner), 'cash balance after unique imported rows');
    ciClose(250, $balance->calculateAccountBalance($bank, $owner), 'bank balance after income and transfer');
    $progress = (new Budget())->getBudgetProgress($budgetId, $owner);
    ciClose(150, (float)$progress['spent'], 'budget consumed imported expenses exactly once');
    $report = (new ReportingPaginationService())->incomeExpensePage($owner, ['start_date' => $monthStart, 'end_date' => $monthEnd], 1, 50);
    ciClose(150, (float)$report['summary']['total_expense'], 'report expense total');
    ciClose(200, (float)$report['summary']['total_income'], 'report income total');
    $ledger = (new ReportingPaginationService())->ledgerPage($owner, ['start_date' => $monthStart, 'end_date' => $monthEnd], 1, 50);
    ciAssert($ledger['pagination']['total_rows'] === 5, 'ledger did not contain each unique imported event exactly once');

    $invalid = $header
        . "{$today},expense,1.999,CSV Cash,,,CSV Food,,cash,Bad precision\n"
        . "2026-02-30,expense,10,CSV Cash,,,CSV Food,,cash,Bad date\n"
        . "{$today},expense,10,Missing Wallet,,,CSV Food,,cash,Missing account\n"
        . "{$today},expense,10,CSV Cash,,,Missing Category,,cash,Missing category\n"
        . "{$today},expense,10,CSV Cash,,,CSV Food,CSV Bus,cash,Wrong subcategory\n"
        . "{$today},goal_contribution,10,CSV Cash,,,CSV Food,,cash,Unsupported type\n"
        . "{$today},transfer,10,,CSV Cash,CSV Cash,,,,Same account\n"
        . "{$today},expense,10,Foreign Wallet,,,CSV Food,,cash,Foreign account\n"
        . "{$today},expense,10,CSV Cash,,,Foreign Category,,cash,Foreign category\n";
    $invalidPreview = $service->preview($owner, $invalid, hash('sha256', $invalid));
    ciAssert($invalidPreview['counts']['invalid'] === 8 && $invalidPreview['counts']['unsupported'] === 1, 'validation/ownership preview counts are wrong');
    foreach ($invalidPreview['rows'] as $row) ciAssert(!empty($row['reason']) && !empty($row['field']), 'invalid row lacks field-specific diagnostics');

    $partial = $header
        . "{$today},expense,30,CSV Cash,,,CSV Food,,cash,Partial valid\n"
        . "{$today},expense,40,CSV Cash,,,CSV Later,,cash,Partial mapping failure\n";
    $partialHash = hash('sha256', $partial);
    $partialFirst = $service->process($owner, $partial, $partialHash);
    ciAssert($partialFirst['counts']['imported'] === 1 && $partialFirst['counts']['failed'] === 1, 'partial import did not preserve independent results');
    $category($owner, 'CSV Later', 'expense');
    $partialRetry = $service->process($owner, $partial, $partialHash);
    ciAssert($partialRetry['counts']['already_imported'] === 1 && $partialRetry['counts']['imported'] === 1, 'partial retry did not resume only the unresolved row');

    $hundred = $header;
    for ($index = 1; $index <= 100; $index++) {
        $categoryName = $index <= 80 ? 'CSV Food' : 'CSV Bulk Later';
        $hundred .= "{$today},expense,1,CSV Cash,,,{$categoryName},,cash,Bulk row {$index}\n";
    }
    $hundredHash = hash('sha256', $hundred);
    $hundredFirst = $service->process($owner, $hundred, $hundredHash);
    ciAssert($hundredFirst['counts']['imported'] === 80 && $hundredFirst['counts']['failed'] === 20, '100-row first pass did not produce 80 success and 20 failure');
    $category($owner, 'CSV Bulk Later', 'expense');
    $hundredRetry = $service->process($owner, $hundred, $hundredHash);
    ciAssert($hundredRetry['counts']['already_imported'] === 80 && $hundredRetry['counts']['imported'] === 20, '100-row retry duplicated prior success or missed resolved rows');
    $prefix = 'req_csv_' . substr($hundredHash, 0, 40) . '_%';
    $stmt = $db->prepare('SELECT COUNT(*) FROM transactions WHERE user_id=? AND client_request_id LIKE ?');
    $stmt->execute([$owner, $prefix]);
    ciAssert((int)$stmt->fetchColumn() === 100, '100-row retry did not finish with exactly 100 unique transactions');

    $interrupted = $header
        . "{$today},expense,11,CSV Cash,,,CSV Food,,cash,Interrupted first\n"
        . "{$today},expense,12,CSV Cash,,,CSV Food,,cash,Interrupted second\n";
    $interruptedHash = hash('sha256', $interrupted);
    $requestId = 'req_csv_' . substr($interruptedHash, 0, 40) . '_2';
    (new AccountingService())->createTransaction($owner, [
        'type' => 'expense', 'amount' => '11.00', 'account_id' => $cash,
        'category_id' => $expenseCategory, 'subcategory_id' => null,
        'date' => $today, 'description' => 'Interrupted first', 'payment_method' => 'cash',
        'client_request_id' => $requestId,
    ]);
    $resumed = $service->process($owner, $interrupted, $interruptedHash);
    ciAssert($resumed['counts']['already_imported'] === 1 && $resumed['counts']['imported'] === 1, 'interrupted import did not resume safely');

    $fileA = $header . "{$today},expense,13,CSV Cash,,,CSV Food,,cash,File A\n";
    $fileB = $header . "{$today},expense,13,CSV Cash,,,CSV Food,,cash,File B modified\n";
    ciAssert(hash('sha256', $fileA) !== hash('sha256', $fileB), 'modified file identity did not change');
    ciAssert($service->process($owner, $fileA, hash('sha256', $fileA))['counts']['imported'] === 1, 'first distinct file failed');
    ciAssert($service->process($owner, $fileB, hash('sha256', $fileB))['counts']['imported'] === 1, 'same row number in a different file collided');

    try {
        $service->preview($owner, $main, str_repeat('0', 64));
        throw new RuntimeException('mismatched client batch identity was accepted');
    } catch (InvalidArgumentException $expected) {
        ciAssert(str_contains($expected->getMessage(), 'does not match'), 'batch identity mismatch error is unclear');
    }

    echo "PASS: CSV import is owner-scoped, diagnosable, resumable, financially idempotent, and transfer-safe\n";
} finally {
    foreach (array_reverse($users) as $userId) {
        try {
            foreach (['notifications', 'transactions', 'budgets', 'subcategories', 'categories', 'accounts'] as $table) $db->prepare("DELETE FROM {$table} WHERE user_id=?")->execute([$userId]);
            $db->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);
        } catch (Throwable $error) { fwrite(STDERR, 'Cleanup warning: ' . $error->getMessage() . "\n"); }
    }
}
