<?php
$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../controllers/AnalysisController.php';
require_once __DIR__ . '/../models/Budget.php';
require_once __DIR__ . '/../models/Account.php';
require_once __DIR__ . '/../models/Goal.php';

function arAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: {$message}\n";
}
function arClose(float $expected, float $actual, string $message): void {
    arAssert(abs($expected - $actual) < .001, $message);
}
function arCall(object $controller, string $method, ...$args) {
    $reflection = new ReflectionMethod($controller, $method);
    $reflection->setAccessible(true);
    return $reflection->invoke($controller, ...$args);
}

$db = (new Database())->getConnection();
$userId = null;
try {
    $db->prepare("INSERT INTO users(email,password,first_name) VALUES(?,?,'Analysis Regression')")
        ->execute(['analysis-regression-' . bin2hex(random_bytes(5)) . '@example.invalid', password_hash('fixture', PASSWORD_DEFAULT)]);
    $userId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO categories(user_id,name,type,status,is_default) VALUES(?,'Salary','income','active',0)")->execute([$userId]);
    $incomeCategory = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO categories(user_id,name,type,status,is_default) VALUES(?,'Food','expense','active',0)")->execute([$userId]);
    $expenseCategory = (int)$db->lastInsertId();
    $insert = $db->prepare('INSERT INTO transactions(user_id,type,amount,date,category_id,description) VALUES(?,?,?,?,?,?)');
    foreach ([
        ['income',1000,'2026-08-05',$incomeCategory,'current income'],
        ['expense',400,'2026-08-06',$expenseCategory,'current expense'],
        ['income',800,'2026-07-05',$incomeCategory,'previous income'],
        ['expense',200,'2026-07-06',$expenseCategory,'previous expense']
    ] as $row) $insert->execute(array_merge([$userId], $row));
    (new Budget())->create(['user_id'=>$userId,'name'=>'Food','amount'=>500,'period'=>'monthly','category_id'=>$expenseCategory,'subcategory_id'=>null,'start_date'=>'2026-08-01','end_date'=>'2026-08-31','alert_threshold'=>80,'is_active'=>1]);
    (new Account())->create(['user_id'=>$userId,'name'=>'Cash','type'=>'cash','balance'=>600,'opening_balance'=>600,'is_active'=>1,'is_default'=>1,'include_in_savings'=>0]);
    (new Account())->create(['user_id'=>$userId,'name'=>'Savings','type'=>'savings','balance'=>300,'opening_balance'=>300,'is_active'=>1,'is_default'=>0,'include_in_savings'=>1]);
    (new Goal())->create(['user_id'=>$userId,'name'=>'Emergency','target_amount'=>100,'initial_amount'=>50,'current_amount'=>50,'deadline'=>'2026-12-31','icon'=>'fa-star','color'=>'#10b981','description'=>'fixture','status'=>'active']);

    $controller = new AnalysisController();
    $fixedNow = new DateTimeImmutable('2026-08-20');
    $period = arCall($controller, 'getPeriodDates', 'month', $fixedNow);
    $previous = arCall($controller, 'getPreviousPeriodDates', 'month', $fixedNow);
    arAssert($period === ['start'=>'2026-08-01','end'=>'2026-08-31'] && $previous === ['start'=>'2026-07-01','end'=>'2026-07-31'], 'current and comparison month periods remain correct');
    $currentTotals = arCall($controller, 'queryTransactions', $userId, $period['start'], $period['end']);
    $previousTotals = arCall($controller, 'queryTransactions', $userId, $previous['start'], $previous['end']);
    arClose(1000, (float)$currentTotals['total_income'], 'period income summary remains correct');
    arClose(400, (float)$currentTotals['total_expense'], 'period expense summary remains correct');
    arClose(800, (float)$previousTotals['total_income'], 'previous income comparison remains correct');
    arClose(200, (float)$previousTotals['total_expense'], 'previous expense comparison remains correct');
    $categories = arCall($controller, 'getCategoryBreakdown', $userId, $period['start'], $period['end']);
    $previousCategories = arCall($controller, 'getCategoryBreakdown', $userId, $previous['start'], $previous['end']);
    arAssert(count($categories) === 1 && $categories[0]['category_name'] === 'Food' && (float)$categories[0]['total_amount'] === 400.0, 'category breakdown remains correct');
    $budget = arCall($controller, 'getBudgetData', $userId, $period['start'], $period['end']);
    arClose(20, (float)$budget['health_score'], 'budget health remains based on authoritative progress');
    arAssert($budget['statuses'] === ['warning'], 'budget warning status remains correct');
    $goal = arCall($controller, 'getGoalData', $userId);
    arClose(50, (float)$goal['avg_progress'], 'goal progress remains correct');
    arAssert($goal['total'] === 1, 'active goal count remains correct');
    $savings = arCall($controller, 'getSavingsData', $userId);
    arClose(900, (float)$savings['total_balance'], 'Net Balance includes every active account whose stored inclusion preference is ON');
    arClose(300, (float)$savings['savings_balance'], 'savings balance figure remains correct');
    $summary = arCall($controller, 'generateSummary', 1000, 400, 60, 200, 100, 20, 50);
    arAssert(is_string($summary) && $summary !== '', 'financial summary generation remains available');
    $prevMap = ['Food'=>(float)$previousCategories[0]['total_amount']];
    $insights = arCall($controller, 'generateInsights', 1000, 400, 60, 50, $categories, $prevMap, $budget['statuses'], 50, 1, 20);
    arAssert(count($insights) > 0 && (bool)array_filter($insights, fn($item) => str_contains($item['message'], 'Food spending increased')), 'category trend insights remain correct');
    $recommendations = arCall($controller, 'generateRecommendations', 60, .4, $categories, $budget['statuses'], 50, 1, 20);
    arAssert(count($recommendations) > 0, 'recommendations remain available');
    arAssert((int)$db->query("SELECT COUNT(*) FROM notifications WHERE user_id={$userId}")->fetchColumn() === 0, 'Analysis reads create no budget notification side effects');
} finally {
    if ($userId) $db->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);
}
