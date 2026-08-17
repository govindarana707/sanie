<?php

$_SERVER['HTTP_HOST'] = 'localhost';

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/AccountingService.php';

$connection = (new Database())->getConnection();
if (!$connection) {
    fwrite(STDERR, "FAIL: database connection unavailable\n");
    exit(1);
}

$fixture = $connection->query(
    "SELECT c.user_id, c.id AS category_id, c.name AS category_name,
            sc.id AS subcategory_id, sc.name AS subcategory_name
     FROM categories c
     JOIN subcategories sc ON sc.category_id = c.id
     WHERE c.status = 'active' AND sc.status = 'active' AND c.user_id IS NOT NULL
     ORDER BY (c.name = 'Addiction' AND sc.name = 'Smoking') DESC, c.id, sc.id
     LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

if (!$fixture) {
    fwrite(STDOUT, "SKIP: no active user-owned category/subcategory fixture is available\n");
    exit(0);
}

$service = new AccountingService();
$method = new ReflectionMethod(AccountingService::class, 'validateSubcategoryOwnership');

try {
    $method->invoke(
        $service,
        (int)$fixture['subcategory_id'],
        (int)$fixture['category_id'],
        (int)$fixture['user_id']
    );
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: valid scoped subcategory was rejected: ' . $error->getMessage() . "\n");
    exit(1);
}

$crossUserRejected = false;
try {
    $method->invoke(
        $service,
        (int)$fixture['subcategory_id'],
        (int)$fixture['category_id'],
        (int)$fixture['user_id'] + 999999
    );
} catch (InvalidArgumentException $error) {
    $crossUserRejected = true;
}

if (!$crossUserRejected) {
    fwrite(STDERR, "FAIL: cross-user subcategory access was accepted\n");
    exit(1);
}

fwrite(
    STDOUT,
    "PASS: scoped category/subcategory validation accepts owner and rejects another user"
    . " ({$fixture['category_name']} / {$fixture['subcategory_name']})\n"
);
