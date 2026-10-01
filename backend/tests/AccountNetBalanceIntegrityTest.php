<?php

$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Account.php';
require_once __DIR__ . '/../services/BalanceService.php';

function nbAssert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: {$message}\n";
}
function nbClose(float $expected, float $actual, string $message): void {
    nbAssert(abs($expected - $actual) < .001, "{$message}: expected {$expected}, got {$actual}");
}

$db = (new Database())->getConnection();
if (!$db) { fwrite(STDERR, "FAIL: database unavailable\n"); exit(1); }
$service = new BalanceService();
$accounts = new Account();
$userId = null;

try {
    $db->prepare('INSERT INTO users(email,password,first_name) VALUES(?,?,?)')
        ->execute(['net-balance-' . bin2hex(random_bytes(5)) . '@example.invalid', password_hash('fixture', PASSWORD_DEFAULT), 'Net Balance']);
    $userId = (int)$db->lastInsertId();

    $create = function(string $name, float $opening, ?bool $included = null) use ($db, $userId): int {
        $sql = $included === null
            ? "INSERT INTO accounts(user_id,name,type,balance,opening_balance,is_active,is_default) VALUES(?,?, 'cash',?,?,1,0)"
            : "INSERT INTO accounts(user_id,name,type,balance,opening_balance,is_active,is_default,include_in_net_balance) VALUES(?,?, 'cash',?,?,1,0,?)";
        $stmt = $db->prepare($sql);
        $included === null ? $stmt->execute([$userId, $name, $opening, $opening]) : $stmt->execute([$userId, $name, $opening, $opening, $included ? 1 : 0]);
        return (int)$db->lastInsertId();
    };
    $transfer = function(int $from, int $to, float $amount) use ($db, $userId): void {
        $db->prepare("INSERT INTO transactions(user_id,from_account_id,to_account_id,amount,type,date,description) VALUES(?,?,?,?, 'transfer',CURDATE(),?)")
            ->execute([$userId, $from, $to, $amount, 'Net Balance transfer fixture']);
    };
    $normal = $create('Normal ON', 1000);
    $defaulted = $create('Default ON', 500);
    $off = $create('Emergency OFF', 10000, false);
    $onDest = $create('ON Destination', 0, true);
    $offDest = $create('OFF Destination', 0, false);

    $stmt = $db->prepare('SELECT include_in_net_balance FROM accounts WHERE id=?');
    $stmt->execute([$defaulted]);
    nbAssert((int)$stmt->fetchColumn() === 1, 'new and migrated-default accounts include Net Balance by default');
    nbClose(1500, $service->getNetBalance($userId), 'mixed ON/OFF accounts count only included balances');

    $transfer($normal, $onDest, 100);
    nbClose(1500, $service->getNetBalance($userId), 'ON to ON transfer preserves Net Balance');
    $transfer($normal, $offDest, 200);
    nbClose(1300, $service->getNetBalance($userId), 'ON to OFF transfer decreases Net Balance');
    $transfer($off, $onDest, 150);
    nbClose(1450, $service->getNetBalance($userId), 'OFF to ON transfer increases Net Balance');
    $transfer($off, $offDest, 75);
    nbClose(1450, $service->getNetBalance($userId), 'OFF to OFF transfer preserves Net Balance');

    $db->prepare("INSERT INTO transactions(user_id,account_id,amount,type,date,description) VALUES(?,?,?, 'income',CURDATE(),?)")
        ->execute([$userId, $off, 800, 'Income to excluded account']);
    $db->prepare("INSERT INTO transactions(user_id,account_id,amount,type,date,description) VALUES(?,?,?, 'expense',CURDATE(),?)")
        ->execute([$userId, $off, 300, 'Expense from excluded account']);
    nbClose(1450, $service->getNetBalance($userId), 'income and expense in an excluded account leave Net Balance unchanged');

    $normalAccount = $accounts->findById($normal, $userId);
    $accounts->update($normal, $userId, [
        'name'=>$normalAccount['name'], 'type'=>$normalAccount['type'], 'account_number'=>$normalAccount['account_number'],
        'currency'=>$normalAccount['currency'], 'color'=>$normalAccount['color'], 'icon'=>$normalAccount['icon'],
        'opening_balance'=>$normalAccount['opening_balance'], 'is_active'=>$normalAccount['is_active'],
        'is_default'=>$normalAccount['is_default'], 'include_in_savings'=>$normalAccount['include_in_savings'], 'include_in_net_balance'=>false,
    ]);
    nbClose(750, $service->getNetBalance($userId), 'toggle ON to OFF removes the current account balance without a transaction');
    $updated = $accounts->findById($normal, $userId);
    nbAssert((int)$updated['include_in_net_balance'] === 0 && $accounts->getTransactionCount($normal, $userId) === 2, 'toggle persists without changing account history');

    $updated['include_in_net_balance'] = true;
    $accounts->update($normal, $userId, $updated);
    nbClose(1450, $service->getNetBalance($userId), 'toggle OFF to ON restores the current account balance immediately');

    $idleOff = $create('Deleteable OFF', 999, false);
    nbClose(1450, $service->getNetBalance($userId), 'excluded no-transaction account remains outside Net Balance before deletion');
    nbAssert($accounts->delete($idleOff, $userId), 'account deletion remains compatible with the inclusion setting');
    nbClose(1450, $service->getNetBalance($userId), 'deleting excluded account leaves Net Balance unchanged');

    $db->prepare('UPDATE accounts SET is_active=0 WHERE id=?')->execute([$onDest]);
    nbClose(1200, $service->getNetBalance($userId), 'inactive included accounts are excluded from Net Balance');
} finally {
    if ($userId) {
        $db->beginTransaction();
        $db->prepare('DELETE FROM transactions WHERE user_id=?')->execute([$userId]);
        $db->prepare('DELETE FROM accounts WHERE user_id=?')->execute([$userId]);
        $db->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);
        $db->commit();
    }
}
