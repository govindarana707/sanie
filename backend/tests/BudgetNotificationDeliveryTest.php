<?php
$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Budget.php';
require_once __DIR__ . '/../models/Notification.php';
require_once __DIR__ . '/../services/NotificationService.php';

function bnAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: {$message}\n";
}

$db = (new Database())->getConnection();
$userId = null;
try {
    $db->prepare("INSERT INTO users(email,password,first_name) VALUES(?,?,'Budget Notification')")
        ->execute(['budget-notification-' . bin2hex(random_bytes(5)) . '@example.invalid', password_hash('fixture', PASSWORD_DEFAULT)]);
    $userId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO categories(user_id,name,type,status,is_default) VALUES(?,'Food','expense','active',0)")->execute([$userId]);
    $categoryId = (int)$db->lastInsertId();
    $budgetId = (int)(new Budget())->create([
        'user_id'=>$userId,'name'=>'Food','amount'=>100,'period'=>'monthly','category_id'=>$categoryId,
        'subcategory_id'=>null,'start_date'=>'2026-08-01','end_date'=>'2026-08-31','alert_threshold'=>80,'is_active'=>1
    ]);
    $insert = $db->prepare("INSERT INTO transactions(user_id,type,amount,date,category_id,description) VALUES(?,'expense',?,?,?,'budget notification fixture')");
    $insert->execute([$userId,50,'2026-08-10',$categoryId]);
    $transactionId = (int)$db->lastInsertId();

    $service = new NotificationService();
    $model = new Notification();
    bnAssert($service->syncUserBudgetAlertStates($userId) === 0, 'below threshold creates no notification');
    $db->prepare('UPDATE transactions SET amount=85 WHERE id=? AND user_id=?')->execute([$transactionId,$userId]);
    bnAssert($service->syncUserBudgetAlertStates($userId) === 1, 'normal to warning creates one notification');
    bnAssert($service->syncUserBudgetAlertStates($userId) === 0, 'repeated warning evaluation is deduplicated');
    $db->prepare('UPDATE transactions SET amount=120 WHERE id=? AND user_id=?')->execute([$transactionId,$userId]);
    bnAssert($service->syncUserBudgetAlertStates($userId) === 1, 'warning to exceeded creates one notification');
    bnAssert($service->syncUserBudgetAlertStates($userId) === 0, 'repeated exceeded evaluation is deduplicated');
    $db->prepare('UPDATE transactions SET amount=40 WHERE id=? AND user_id=?')->execute([$transactionId,$userId]);
    bnAssert($service->syncUserBudgetAlertStates($userId) === 1, 'lowered spending records recovery');
    bnAssert($service->syncUserBudgetAlertStates($userId) === 0, 'repeated normal evaluation is deduplicated');
    $db->prepare('UPDATE transactions SET amount=90 WHERE id=? AND user_id=?')->execute([$transactionId,$userId]);
    bnAssert($service->syncUserBudgetAlertStates($userId) === 1, 'warning can be emitted after a genuine recovery and recrossing');
    $types = $db->query("SELECT type FROM notifications WHERE user_id={$userId} AND reference_type='budget' AND reference_id={$budgetId} ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
    bnAssert($types === ['budget_warning','budget_exceeded','budget_normal','budget_warning'], 'notification history records only state transitions');
    $financial = $db->query("SELECT amount FROM transactions WHERE id={$transactionId}")->fetchColumn();
    bnAssert((float)$financial === 90.0 && (int)$db->query("SELECT COUNT(*) FROM budgets WHERE id={$budgetId}")->fetchColumn() === 1, 'notification synchronization does not mutate financial records');
    $db->prepare("INSERT INTO categories(user_id,name,type,status,is_default) VALUES(?,'Travel','expense','active',0)")->execute([$userId]);
    $travelCategoryId = (int)$db->lastInsertId();
    $db->prepare('UPDATE transactions SET category_id=? WHERE id=? AND user_id=?')->execute([$travelCategoryId,$transactionId,$userId]);
    bnAssert($service->syncUserBudgetAlertStates($userId) === 1, 'moving spending to another category records recovery');
    $db->prepare('UPDATE transactions SET category_id=? WHERE id=? AND user_id=?')->execute([$categoryId,$transactionId,$userId]);
    bnAssert($service->syncUserBudgetAlertStates($userId) === 1, 'moving spending back across threshold records warning');
    $db->prepare("UPDATE transactions SET date='2026-09-01' WHERE id=? AND user_id=?")->execute([$transactionId,$userId]);
    bnAssert($service->syncUserBudgetAlertStates($userId) === 1, 'moving spending to another period records recovery');
    $db->prepare("UPDATE transactions SET date='2026-08-10' WHERE id=? AND user_id=?")->execute([$transactionId,$userId]);
    bnAssert($service->syncUserBudgetAlertStates($userId) === 1, 'moving spending back into the budget period records warning');
    $db->prepare('DELETE FROM transactions WHERE id=? AND user_id=?')->execute([$transactionId,$userId]);
    bnAssert($service->syncUserBudgetAlertStates($userId) === 1, 'deleting threshold spending records recovery');
    bnAssert($service->syncUserBudgetAlertStates($userId) === 0, 'repeated evaluation after deletion is deduplicated');
    bnAssert((int)$db->query("SELECT COUNT(*) FROM notifications WHERE user_id={$userId} AND reference_type='budget' AND reference_id={$budgetId}")->fetchColumn() === 9, 'each genuine budget state transition creates exactly one notification');
} finally {
    if ($userId) $db->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);
}
