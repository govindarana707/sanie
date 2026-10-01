<?php

$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Budget.php';

function bdpAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: {$message}\n";
}

function bdpRejected(callable $operation, string $message): void {
    try {
        $operation();
        throw new RuntimeException($message);
    } catch (BudgetValidationException $error) {
        bdpAssert(
            $error->getMessage() === 'A budget already exists for this category and subcategory for the selected period.',
            'duplicate rejection returns the validation message'
        );
    }
}

$db = (new Database())->getConnection();
$users = [];
try {
    foreach (['owner', 'other'] as $label) {
        $db->prepare("INSERT INTO users(email,password,first_name) VALUES(?,?,'Budget Duplicate')")
            ->execute(['budget-duplicate-' . $label . '-' . bin2hex(random_bytes(5)) . '@example.invalid', password_hash('fixture', PASSWORD_DEFAULT)]);
        $users[$label] = (int)$db->lastInsertId();
    }

    $category = function (int $userId, string $name) use ($db): int {
        $db->prepare("INSERT INTO categories(user_id,name,type,status,is_default) VALUES(?,?,'expense','active',0)")
            ->execute([$userId, $name]);
        return (int)$db->lastInsertId();
    };
    $subcategory = function (int $userId, int $categoryId, string $name) use ($db): int {
        $db->prepare("INSERT INTO subcategories(user_id,category_id,name,status) VALUES(?,?,?,'active')")
            ->execute([$userId, $categoryId, $name]);
        return (int)$db->lastInsertId();
    };

    $ownerCategory = $category($users['owner'], 'Duplicate Food');
    $ownerGroceries = $subcategory($users['owner'], $ownerCategory, 'Duplicate Groceries');
    $ownerEatingOut = $subcategory($users['owner'], $ownerCategory, 'Duplicate Eating Out');
    $otherCategory = $category($users['other'], 'Duplicate Food');
    $otherGroceries = $subcategory($users['other'], $otherCategory, 'Duplicate Groceries');
    $model = new Budget();

    $budget = function (int $userId, int $categoryId, ?int $subcategoryId, string $start, string $end, string $period = 'monthly', string $name = 'Food') {
        return [
            'user_id' => $userId, 'name' => $name, 'amount' => 500,
            'period' => $period, 'category_id' => $categoryId, 'subcategory_id' => $subcategoryId,
            'start_date' => $start, 'end_date' => $end, 'alert_threshold' => 80, 'is_active' => 1,
        ];
    };

    $october = $budget($users['owner'], $ownerCategory, $ownerGroceries, '2026-10-01', '2026-10-31');
    $octoberId = (int)$model->create($october);

    bdpRejected(fn() => $model->create(array_merge($october, ['name' => 'Second October Food'])), 'same scope and overlapping period was accepted');

    $eatingOutId = (int)$model->create($budget($users['owner'], $ownerCategory, $ownerEatingOut, '2026-10-01', '2026-10-31', 'monthly', 'Eating Out'));
    bdpAssert($eatingOutId > 0, 'same category with a different subcategory is allowed');

    $november = $budget($users['owner'], $ownerCategory, $ownerGroceries, '2026-11-01', '2026-11-30', 'monthly', 'November Food');
    $novemberId = (int)$model->create($november);
    bdpAssert($novemberId > 0, 'same scope in a non-overlapping period is allowed');

    $otherId = (int)$model->create($budget($users['other'], $otherCategory, $otherGroceries, '2026-10-01', '2026-10-31'));
    bdpAssert($otherId > 0, 'another user can use the same scope and period');

    bdpAssert($model->update($octoberId, $users['owner'], array_merge($october, ['amount' => 650])), 'editing a budget without changing its scope is allowed');
    bdpRejected(fn() => $model->update($novemberId, $users['owner'], array_merge($november, ['start_date' => '2026-10-01', 'end_date' => '2026-10-31'])), 'an edit that conflicts with another budget was accepted');
    bdpRejected(fn() => $model->create(array_merge($october, ['period' => 'daily', 'start_date' => '2026-10-05', 'end_date' => '2026-10-05', 'name' => 'Daily overlap'])), 'a differently labeled but overlapping period was accepted');

    // Simulate legacy data imported before this validation. The application
    // must reject new duplicates without modifying or deleting those records.
    $db->prepare('INSERT INTO budgets(user_id,category_id,subcategory_id,name,amount,period,start_date,end_date,alert_threshold,is_active) VALUES(?,?,?,?,?,?,?,?,?,?)')
        ->execute([$users['owner'], $ownerCategory, $ownerGroceries, 'Legacy duplicate', 500, 'monthly', '2026-10-01', '2026-10-31', 80, 1]);
    $count = $db->prepare('SELECT COUNT(*) FROM budgets WHERE user_id=? AND category_id=? AND subcategory_id=? AND start_date=? AND end_date=?');
    $count->execute([$users['owner'], $ownerCategory, $ownerGroceries, '2026-10-01', '2026-10-31']);
    $before = (int)$count->fetchColumn();
    bdpRejected(fn() => $model->create(array_merge($october, ['name' => 'Direct backend retry'])), 'a direct backend create bypassed duplicate validation');
    $count->execute([$users['owner'], $ownerCategory, $ownerGroceries, '2026-10-01', '2026-10-31']);
    bdpAssert((int)$count->fetchColumn() === $before, 'existing duplicate records are preserved without deletion');
} finally {
    foreach ($users as $userId) {
        $db->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);
    }
}
