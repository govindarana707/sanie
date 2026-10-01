<?php
$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Budget.php';

function bcAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: {$message}\n";
}

$db = (new Database())->getConnection();
$userId = null;
$otherUserId = null;
try {
    $db->prepare("INSERT INTO users(email,password,first_name) VALUES(?,?,'Budget Copy')")
        ->execute(['budget-copy-' . bin2hex(random_bytes(5)) . '@example.invalid', password_hash('fixture', PASSWORD_DEFAULT)]);
    $userId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO users(email,password,first_name) VALUES(?,?,'Other User')")
        ->execute(['budget-copy-other-' . bin2hex(random_bytes(5)) . '@example.invalid', password_hash('fixture', PASSWORD_DEFAULT)]);
    $otherUserId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO categories(user_id,name,type,status,is_default) VALUES(?,'Food','expense','active',0)")->execute([$userId]);
    $categoryId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO subcategories(user_id,category_id,name,status) VALUES(?,?,'Restaurant','active')")->execute([$userId, $categoryId]);
    $subcategoryId = (int)$db->lastInsertId();

    $model = new Budget();
    $base = ['user_id'=>$userId,'amount'=>500,'period'=>'monthly','start_date'=>'2026-07-01','end_date'=>'2026-07-31','alert_threshold'=>75,'is_active'=>1];
    $foodId = (int)$model->create(array_merge($base, ['name'=>'Food','category_id'=>$categoryId,'subcategory_id'=>null]));
    $restaurantId = (int)$model->create(array_merge($base, ['name'=>'Restaurant','amount'=>250,'category_id'=>$categoryId,'subcategory_id'=>$subcategoryId]));
    $overallId = (int)$model->create(array_merge($base, ['name'=>'All Expenses','amount'=>900,'category_id'=>null,'subcategory_id'=>null]));
    $model->create(array_merge($base, ['name'=>'Food','start_date'=>'2026-08-01','end_date'=>'2026-08-31','category_id'=>$categoryId,'subcategory_id'=>null]));
    $foreignId = (int)$model->create(['user_id'=>$otherUserId,'name'=>'Foreign','amount'=>100,'period'=>'monthly','start_date'=>'2026-07-01','end_date'=>'2026-07-31','alert_threshold'=>80,'is_active'=>1,'category_id'=>null,'subcategory_id'=>null]);

    $result = $model->copyMonthlyBudgets($userId, [$foodId,$restaurantId,$overallId,$foreignId], '2026-07', '2026-08');
    bcAssert($result['created'] === 2, 'custom month copy creates only missing selected budgets');
    bcAssert(count($result['skipped']) === 1 && $result['skipped'][0]['name'] === 'Food', 'existing destination budget is skipped');
    $august = array_values(array_filter($model->findAll($userId), fn($b) => $b['start_date'] === '2026-08-01' && $b['end_date'] === '2026-08-31'));
    bcAssert(count($august) === 3, 'destination contains existing and copied budgets without duplicates');
    $restaurant = array_values(array_filter($august, fn($b) => $b['name'] === 'Restaurant'))[0] ?? null;
    bcAssert($restaurant && (int)$restaurant['subcategory_id'] === $subcategoryId, 'subcategory scope is preserved');
    bcAssert($restaurant && (float)$restaurant['amount'] === 250.0 && (float)$restaurant['alert_threshold'] === 75.0, 'amount and alert threshold are preserved');
    bcAssert(!array_filter($august, fn($b) => $b['name'] === 'Foreign'), 'another user budget cannot be copied');
    try {
        $model->copyMonthlyBudgets($userId, [$foodId], '2026-07', '2026-07');
        bcAssert(false, 'same-month copy must be rejected');
    } catch (BudgetValidationException $e) {
        bcAssert(true, 'same-month copy is rejected');
    }
} finally {
    if ($userId) $db->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);
    if ($otherUserId) $db->prepare('DELETE FROM users WHERE id=?')->execute([$otherUserId]);
}
