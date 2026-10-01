<?php

$_SERVER['HTTP_HOST'] = 'localhost';

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Category.php';

$connection = (new Database())->getConnection();
if (!$connection) {
    fwrite(STDERR, "FAIL: database connection unavailable\n");
    exit(1);
}

$userId = (int)$connection->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
if ($userId < 1) {
    fwrite(STDOUT, "SKIP: no user is available for category creation testing\n");
    exit(0);
}

$model = new Category();
$name = 'Category boolean binding test ' . bin2hex(random_bytes(4));
$categoryId = null;

try {
    $categoryId = $model->create([
        'user_id' => $userId,
        'name' => $name,
        'type' => 'expense',
        'icon' => 'tag',
        'color' => '#EF4444',
        'description' => '',
        'is_default' => false,
        'status' => 'active'
    ]);
    if (!$categoryId) throw new RuntimeException('category insert returned no ID');

    $created = $model->findById((int)$categoryId, $userId);
    if (!$created || (int)$created['is_default'] !== 0 || (int)$created['is_pinned'] !== 0 || (int)$created['sort_order'] !== 999) {
        throw new RuntimeException('created category did not retain priority defaults');
    }

    fwrite(STDOUT, "PASS: user category creation binds boolean and priority defaults safely\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    if ($categoryId) $model->delete((int)$categoryId, $userId);
}
