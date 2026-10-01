<?php

$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/AccountingService.php';
require_once __DIR__ . '/../services/BalanceService.php';

function ifAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: {$message}\n";
}
function ifClose(float $expected, float $actual, string $message): void {
    if (abs($expected - $actual) > .001) throw new RuntimeException("{$message}: expected {$expected}, got {$actual}");
    echo "PASS: {$message}\n";
}
function ifInsufficient(callable $operation, string $message): void {
    try {
        $operation();
        throw new RuntimeException($message);
    } catch (InvalidArgumentException $error) {
        ifAssert(str_starts_with($error->getMessage(), 'Insufficient balance. Available balance: Rs '), 'insufficient-funds error is explicit');
    }
}

$db = (new Database())->getConnection();
$userId = null;
try {
    $db->prepare("INSERT INTO users(email,password,first_name) VALUES(?,?,'Funds Test')")
        ->execute(['funds-' . bin2hex(random_bytes(5)) . '@example.invalid', password_hash('fixture', PASSWORD_DEFAULT)]);
    $userId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO accounts(user_id,name,type,balance,opening_balance,is_active,is_default) VALUES(?,?,'cash',?,?,1,0)")
        ->execute([$userId, 'Funds Cash', 1000, 1000]);
    $cash = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO accounts(user_id,name,type,balance,opening_balance,is_active,is_default) VALUES(?,?,'bank',?,?,1,0)")
        ->execute([$userId, 'Funds Bank', 200, 200]);
    $bank = (int)$db->lastInsertId();
    foreach ([['Funds Expense', 'expense'], ['Funds Income', 'income']] as [$name, $type]) {
        $db->prepare("INSERT INTO categories(user_id,name,type,status,is_default) VALUES(?,?,?,'active',0)")->execute([$userId, $name, $type]);
        $categories[$type] = (int)$db->lastInsertId();
    }

    $service = new AccountingService();
    $balances = new BalanceService();
    $expense = fn(int $accountId, float $amount, string $key) => [
        'type'=>'expense', 'amount'=>$amount, 'account_id'=>$accountId, 'category_id'=>$categories['expense'],
        'date'=>'2026-10-01', 'description'=>'Funds test', 'client_request_id'=>$key,
    ];

    ifInsufficient(fn() => $service->createTransaction($userId, $expense($cash, 1000.01, 'funds-overdraft')), 'expense exceeding balance was accepted');
    ifClose(1000, $balances->calculateAccountBalance($cash, $userId), 'rejected expense leaves balance unchanged');

    $exactId = (int)$service->createTransaction($userId, $expense($cash, 1000, 'funds-exact'));
    ifClose(0, $balances->calculateAccountBalance($cash, $userId), 'expense equal to available balance is allowed');
    $service->deleteTransaction($exactId, $userId);
    ifClose(1000, $balances->calculateAccountBalance($cash, $userId), 'deleting an expense restores its account balance');

    $editId = (int)$service->createTransaction($userId, $expense($cash, 400, 'funds-edit'));
    ifClose(600, $balances->calculateAccountBalance($cash, $userId), 'expense decreases the selected account balance');
    $version = (int)$db->query("SELECT version FROM transactions WHERE id={$editId}")->fetchColumn();
    $service->updateTransaction($editId, $userId, ['amount'=>500], $version);
    ifClose(500, $balances->calculateAccountBalance($cash, $userId), 'same-account expense edit uses the original expense as available funds');
    $version = (int)$db->query("SELECT version FROM transactions WHERE id={$editId}")->fetchColumn();
    ifInsufficient(fn() => $service->updateTransaction($editId, $userId, ['amount'=>1001], $version), 'expense edit exceeding effective balance was accepted');

    $version = (int)$db->query("SELECT version FROM transactions WHERE id={$editId}")->fetchColumn();
    ifInsufficient(fn() => $service->updateTransaction($editId, $userId, ['account_id'=>$bank], $version), 'moving an expense to an underfunded account was accepted');
    $service->updateTransaction($editId, $userId, ['account_id'=>$bank, 'amount'=>150], $version);
    ifClose(1000, $balances->calculateAccountBalance($cash, $userId), 'moving an expense restores the original account');
    ifClose(50, $balances->calculateAccountBalance($bank, $userId), 'moving an expense debits the replacement account');
    $service->deleteTransaction($editId, $userId);
    ifClose(200, $balances->calculateAccountBalance($bank, $userId), 'deleting a moved expense restores the replacement account');

    $incomeId = (int)$service->createTransaction($userId, [
        'type'=>'income', 'amount'=>300, 'account_id'=>$bank, 'category_id'=>$categories['income'],
        'date'=>'2026-10-02', 'description'=>'Funds income', 'client_request_id'=>'funds-income',
    ]);
    ifAssert($incomeId > 0, 'income transaction is created');
    ifClose(500, $balances->calculateAccountBalance($bank, $userId), 'income increases the selected account balance');

    ifInsufficient(fn() => $service->createTransaction($userId, [
        'type'=>'transfer', 'amount'=>501, 'from_account_id'=>$bank, 'to_account_id'=>$cash,
        'date'=>'2026-10-03', 'description'=>'Overdrawn transfer', 'client_request_id'=>'funds-transfer-over',
    ]), 'transfer exceeding source balance was accepted');
    $transfer = $service->createTransaction($userId, [
        'type'=>'transfer', 'amount'=>500, 'from_account_id'=>$bank, 'to_account_id'=>$cash,
        'date'=>'2026-10-03', 'description'=>'Exact transfer', 'client_request_id'=>'funds-transfer-exact',
    ]);
    ifAssert(!empty($transfer['transfer_id']), 'transfer is created');
    ifClose(0, $balances->calculateAccountBalance($bank, $userId), 'transfer decreases its source account');
    ifClose(1500, $balances->calculateAccountBalance($cash, $userId), 'transfer increases its destination account');
} finally {
    if ($userId) {
        $db->prepare('DELETE FROM transactions WHERE user_id=?')->execute([$userId]);
        $db->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);
    }
}
