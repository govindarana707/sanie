<?php
$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../controllers/AnalysisController.php';

function amAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: {$message}\n";
}
function amClose(float $expected, float $actual, string $message): void {
    amAssert(abs($expected - $actual) < .001, $message);
}

$db = (new Database())->getConnection();
$userId = null;
$emptyUserId = null;
try {
    foreach ([['Trend User','analysis-trend-'],['Empty Trend','analysis-empty-']] as [$name,$prefix]) {
        $db->prepare('INSERT INTO users(email,password,first_name) VALUES(?,?,?)')->execute([$prefix . bin2hex(random_bytes(5)) . '@example.invalid', password_hash('fixture', PASSWORD_DEFAULT), $name]);
        if ($userId === null) $userId = (int)$db->lastInsertId(); else $emptyUserId = (int)$db->lastInsertId();
    }
    $db->prepare("INSERT INTO categories(user_id,name,type,status,is_default) VALUES(?,'Income','income','active',0)")->execute([$userId]);
    $incomeCategory = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO categories(user_id,name,type,status,is_default) VALUES(?,'Expense','expense','active',0)")->execute([$userId]);
    $expenseCategory = (int)$db->lastInsertId();
    $insert = $db->prepare('INSERT INTO transactions(user_id,type,amount,date,category_id,description) VALUES(?,?,?,?,?,?)');
    $rows = [
        ['income',999,'2025-08-25',$incomeCategory,'same month older year'],
        ['income',50,'2025-12-10',$incomeCategory,'December income only'],
        ['expense',30,'2026-01-10',$expenseCategory,'January expense only'],
        ['income',20,'2026-03-10',$incomeCategory,'March income'],
        ['expense',10,'2026-03-11',$expenseCategory,'March expense'],
        ['income',100,'2026-08-10',$incomeCategory,'current August income'],
        ['expense',80,'2026-08-11',$expenseCategory,'current August expense']
    ];
    foreach ($rows as [$type,$amount,$date,$category,$description]) $insert->execute([$userId,$type,$amount,$date,$category,$description]);

    $controller = new AnalysisController();
    $monthly = new ReflectionMethod($controller, 'getMonthlyData');
    $monthly->setAccessible(true);
    $augustWindow = $monthly->invoke($controller, $userId, new DateTimeImmutable('2026-08-24'));
    $august = $augustWindow[5];
    amAssert($august['month'] === 'Aug', 'friendly month label remains unchanged');
    amClose(100, (float)$august['income'], 'same month name from an older year does not replace current-year income');
    amClose(80, (float)$august['expense'], 'same month name from an older year does not replace current-year expense');

    $boundary = $monthly->invoke($controller, $userId, new DateTimeImmutable('2026-03-15'));
    amAssert(array_column($boundary, 'month') === ['Oct','Nov','Dec','Jan','Feb','Mar'], 'six-month output crosses December to January in order');
    amClose(50, (float)$boundary[2]['income'], 'December income-only month is retained');
    amClose(0, (float)$boundary[2]['expense'], 'December missing expense is zero-filled');
    amClose(0, (float)$boundary[3]['income'], 'January missing income is zero-filled');
    amClose(30, (float)$boundary[3]['expense'], 'January expense-only month is retained');
    amClose(20, (float)$boundary[5]['income'], 'month containing both types retains income');
    amClose(10, (float)$boundary[5]['expense'], 'month containing both types retains expense');
    amAssert($boundary[0]['income'] == 0 && $boundary[0]['expense'] == 0 && $boundary[1]['income'] == 0 && $boundary[1]['expense'] == 0, 'months without transactions are zero-filled');
    $empty = $monthly->invoke($controller, $emptyUserId, new DateTimeImmutable('2026-08-24'));
    amAssert(count($empty) === 6 && array_sum(array_column($empty,'income')) == 0 && array_sum(array_column($empty,'expense')) == 0, 'empty result produces six zero-valued months');

    $summary = new ReflectionMethod($controller, 'queryTransactions');
    $summary->setAccessible(true);
    $totals = $summary->invoke($controller, $userId, '2026-08-01', '2026-08-31');
    amClose(100, (float)$totals['total_income'], 'existing period income summary remains unchanged');
    amClose(80, (float)$totals['total_expense'], 'existing period expense summary remains unchanged');
} finally {
    if ($userId) $db->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);
    if ($emptyUserId) $db->prepare('DELETE FROM users WHERE id=?')->execute([$emptyUserId]);
}
