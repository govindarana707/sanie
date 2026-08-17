<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Transaction.php';

$connection = (new Database())->getConnection();
if (!$connection) {
    fwrite(STDERR, "Database connection unavailable\n");
    exit(1);
}

$source = $connection->query(
    "SELECT user_id, account_id, from_account_id, to_account_id, category_id, subcategory_id,
            amount, type, payment_method, karobar_transaction_id, date, description
     FROM transactions ORDER BY id ASC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

if (!$source) {
    fwrite(STDOUT, "SKIP: no existing transaction is available for a rollback-only fixture\n");
    exit(0);
}

$clientRequestId = sprintf(
    '%08x-%04x-4%03x-a%03x-%012x',
    time(),
    random_int(0, 0xffff),
    random_int(0, 0xfff),
    random_int(0, 0xfff),
    random_int(0, 0xffffffffff)
);
$source['client_request_id'] = $clientRequestId;
$model = new Transaction();

$connection->beginTransaction();
try {
    $createdId = $model->create($source);
    if (!$createdId) throw new RuntimeException('First idempotent fixture insert failed');

    $found = $model->findByClientRequestId($clientRequestId, $source['user_id']);
    if (!$found || (string)$found['id'] !== (string)$createdId) {
        throw new RuntimeException('Client request lookup did not return the created transaction');
    }

    $duplicateRejected = false;
    try {
        $model->create($source);
    } catch (PDOException $error) {
        $duplicateRejected = $error->getCode() === '23000';
    }
    if (!$duplicateRejected) throw new RuntimeException('Duplicate client request was not rejected');

    $count = $connection->prepare(
        'SELECT COUNT(*) FROM transactions WHERE user_id = :user_id AND client_request_id = :client_request_id'
    );
    $count->execute([
        ':user_id' => $source['user_id'],
        ':client_request_id' => $clientRequestId
    ]);
    if ((int)$count->fetchColumn() !== 1) throw new RuntimeException('Duplicate transaction count was not exactly one');

    $connection->rollBack();
    fwrite(STDOUT, "PASS: scoped client request id creates exactly one transaction\n");
} catch (Throwable $error) {
    if ($connection->inTransaction()) $connection->rollBack();
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
    exit(1);
}
