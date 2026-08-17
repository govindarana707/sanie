<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Transaction.php';

$connection = (new Database())->getConnection();
if (!$connection) {
    fwrite(STDERR, "Database connection unavailable\n");
    exit(1);
}

$fixture = $connection->query('SELECT * FROM transactions ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if (!$fixture) {
    fwrite(STDOUT, "SKIP: no transaction is available for rollback-only concurrency testing\n");
    exit(0);
}

$model = new Transaction();
$id = (int)$fixture['id'];
$userId = (int)$fixture['user_id'];
$baseVersion = (int)$fixture['version'];
$updateData = [
    'account_id' => $fixture['account_id'],
    'from_account_id' => $fixture['from_account_id'],
    'to_account_id' => $fixture['to_account_id'],
    'category_id' => $fixture['category_id'],
    'subcategory_id' => $fixture['subcategory_id'],
    'amount' => $fixture['amount'],
    'type' => $fixture['type'],
    'payment_method' => $fixture['payment_method'],
    'date' => $fixture['date'],
    'description' => $fixture['description']
];

$connection->beginTransaction();
try {
    if ($model->update($id, $userId, $updateData, $baseVersion) !== 1) {
        throw new RuntimeException('Matching version did not update exactly one row');
    }
    $updated = $model->findById($id, $userId);
    if ((int)$updated['version'] !== $baseVersion + 1) {
        throw new RuntimeException('Successful update did not increment the version');
    }
    if ($model->update($id, $userId, $updateData, $baseVersion) !== 0) {
        throw new RuntimeException('Stale update was not rejected');
    }
    if ($model->delete($id, $userId + 999999, $baseVersion + 1) !== 0) {
        throw new RuntimeException('Cross-user delete was not rejected');
    }
    if ($model->delete($id, $userId, $baseVersion) !== 0) {
        throw new RuntimeException('Stale delete was not rejected');
    }
    if ($model->delete($id, $userId, $baseVersion + 1) !== 1) {
        throw new RuntimeException('Current-version delete did not affect exactly one row');
    }
    $connection->rollBack();
    fwrite(STDOUT, "PASS: versioned update/delete reject stale and cross-user writes\n");
} catch (Throwable $error) {
    if ($connection->inTransaction()) $connection->rollBack();
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
    exit(1);
}
