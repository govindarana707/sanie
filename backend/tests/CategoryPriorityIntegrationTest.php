<?php

$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Category.php';
require_once __DIR__ . '/../database/SchemaMigrator.php';

function categoryPriorityAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$db = (new Database())->getConnection();
if (!$db) {
    fwrite(STDERR, "FAIL: database connection unavailable\n");
    exit(1);
}

$users = [];
$passed = 0;
$failed = 0;
$run = function (string $name, callable $test) use (&$passed, &$failed): void {
    try {
        $test();
        $passed++;
        echo "PASS: {$name}\n";
    } catch (Throwable $error) {
        $failed++;
        fwrite(STDERR, "FAIL: {$name} — {$error->getMessage()}\n");
    }
};

try {
    foreach (['owner', 'foreign'] as $label) {
        $stmt = $db->prepare('INSERT INTO users (email,password,first_name) VALUES (?,?,?)');
        $stmt->execute([
            'category-priority-' . $label . '-' . bin2hex(random_bytes(5)) . '@example.invalid',
            password_hash('Category-Priority-Test-2026!', PASSWORD_DEFAULT),
            ucfirst($label),
        ]);
        $users[$label] = (int)$db->lastInsertId();
    }
    $owner = $users['owner'];
    $foreign = $users['foreign'];

    $makeCategory = function (int $userId, string $name, string $type, bool $pinned = false, int $order = 999) use ($db): int {
        $stmt = $db->prepare(
            "INSERT INTO categories (user_id,name,type,status,is_default,is_pinned,sort_order)
             VALUES (?, ?, ?, 'active', 0, ?, ?)"
        );
        $stmt->execute([$userId, $name, $type, (int)$pinned, $order]);
        return (int)$db->lastInsertId();
    };
    $addUsage = function (int $userId, int $categoryId, string $type, int $count) use ($db): void {
        $stmt = $db->prepare(
            'INSERT INTO transactions (user_id,category_id,amount,type,date,description) VALUES (?,?,1,?,CURRENT_DATE,?)'
        );
        for ($i = 0; $i < $count; $i++) {
            $stmt->execute([$userId, $categoryId, $type, 'Category priority usage fixture']);
        }
    };

    $expenseFirst = $makeCategory($owner, 'Pinned Alpha', 'expense', true, 1);
    $expenseSecond = $makeCategory($owner, 'Pinned Beta', 'expense', true, 2);
    $room = $makeCategory($owner, 'Room Expense', 'expense');
    $shopping = $makeCategory($owner, 'Shopping', 'expense');
    $fee = $makeCategory($owner, 'Fee', 'expense');
    $salary = $makeCategory($owner, 'Salary', 'income', true, 1);
    $freelance = $makeCategory($owner, 'Freelance', 'income');
    $foreignPinned = $makeCategory($foreign, 'Foreign Pinned', 'expense', true, 1);

    $addUsage($owner, $room, 'expense', 5);
    $addUsage($owner, $shopping, 'expense', 3);
    $addUsage($owner, $fee, 'expense', 1);
    $addUsage($owner, $room, 'income', 7);
    $addUsage($owner, $room, 'goal_contribution', 4);
    $addUsage($owner, $freelance, 'income', 2);
    $addUsage($foreign, $foreignPinned, 'expense', 20);

    $model = new Category();

    $run('pinned order precedes matching-type usage ranking', function () use ($model, $owner): void {
        $rows = $model->findAll($owner, 'expense', 'active');
        $names = array_column($rows, 'name');
        categoryPriorityAssert(
            array_slice($names, 0, 5) === ['Pinned Alpha', 'Pinned Beta', 'Room Expense', 'Shopping', 'Fee'],
            'expense priority order was ' . json_encode($names)
        );
        $room = $rows[array_search('Room Expense', $names, true)];
        categoryPriorityAssert((int)$room['transaction_count'] === 5, 'unrelated transaction types affected usage count');
    });

    $run('expense and income ranking are independent', function () use ($model, $owner): void {
        $income = $model->findAll($owner, 'income', 'active');
        categoryPriorityAssert(array_column($income, 'name') === ['Salary', 'Freelance'], 'income order was not independent');
        categoryPriorityAssert((int)$income[1]['transaction_count'] === 2, 'income usage count is incorrect');
    });

    $run('category search keeps eligible low-priority matches discoverable', function () use ($model, $owner, $fee): void {
        $results = $model->search($owner, 'Fee', 'expense', 'active');
        categoryPriorityAssert(count($results) === 1 && (int)$results[0]['id'] === $fee, 'category search did not return Fee');
    });

    $run('manual pinned reorder is normalized and persisted', function () use ($model, $owner, $expenseFirst, $expenseSecond): void {
        categoryPriorityAssert($model->reorderPinned([
            ['id' => $expenseSecond, 'sort_order' => 99],
            ['id' => $expenseFirst, 'sort_order' => 99],
        ], $owner, 'expense'), 'owned pinned reorder failed');
        $rows = $model->findAll($owner, 'expense', 'active');
        categoryPriorityAssert(array_slice(array_column($rows, 'name'), 0, 2) === ['Pinned Beta', 'Pinned Alpha'], 'manual order did not persist');
        categoryPriorityAssert((int)$rows[0]['sort_order'] === 1 && (int)$rows[1]['sort_order'] === 2, 'orders were not normalized');
    });

    $run('foreign and stale category IDs cannot be reordered', function () use ($model, $owner, $foreignPinned): void {
        categoryPriorityAssert(!$model->reorderPinned([
            ['id' => $foreignPinned, 'sort_order' => 1],
        ], $owner, 'expense'), 'foreign pinned category was accepted');
        categoryPriorityAssert(!$model->reorderPinned([
            ['id' => 2147483647, 'sort_order' => 1],
        ], $owner, 'expense'), 'stale category ID was accepted');
    });

    $run('schema migration is repeat-safe with priority defaults and indexes', function () use ($db): void {
        $result = (new SchemaMigrator($db))->run();
        categoryPriorityAssert($result['applied'] === false, 'current migration reapplied unexpectedly');
        $columns = $db->query(
            "SELECT COLUMN_NAME,IS_NULLABLE,COLUMN_DEFAULT FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='categories' AND COLUMN_NAME IN ('is_pinned','sort_order')"
        )->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
        categoryPriorityAssert(($columns['is_pinned']['IS_NULLABLE'] ?? '') === 'NO' && (string)$columns['is_pinned']['COLUMN_DEFAULT'] === '0', 'is_pinned default is invalid');
        categoryPriorityAssert(($columns['sort_order']['IS_NULLABLE'] ?? '') === 'NO' && (string)$columns['sort_order']['COLUMN_DEFAULT'] === '999', 'sort_order default is invalid');
    });
} finally {
    foreach (array_reverse($users) as $userId) {
        $stmt = $db->prepare('DELETE FROM users WHERE id = ?');
        $stmt->execute([$userId]);
    }
}

echo "Category priority integration: {$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
