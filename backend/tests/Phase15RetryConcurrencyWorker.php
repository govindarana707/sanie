<?php

$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/../services/AccountingService.php';
require_once __DIR__ . '/../services/KarobarService.php';

[$script, $workflow, $userId, $accountId, $referenceId, $requestId, $barrier] = $argv;
$deadline = microtime(true) + 10;
while (!file_exists($barrier) && microtime(true) < $deadline) usleep(10000);

try {
    if ($workflow === 'ordinary') {
        $result = (new AccountingService())->createOrdinaryTransaction((int)$userId, [
            'type' => 'expense', 'amount' => '25.00', 'account_id' => (int)$accountId,
            'category_id' => (int)$referenceId, 'date' => '2026-08-25',
            'description' => 'Concurrent ordinary', 'payment_method' => 'cash',
            'client_request_id' => $requestId,
        ]);
        echo json_encode(['ok' => true, 'id' => (int)$result['transaction_id'], 'replayed' => (bool)$result['replayed']]);
    } else {
        $id = (new KarobarService())->createTransaction([
            'person_id' => (int)$referenceId, 'account_id' => (int)$accountId,
            'type' => 'lent', 'amount' => '40.00', 'transaction_date' => '2026-08-25',
            'description' => 'Concurrent Karobar', 'client_request_id' => $requestId,
        ], (int)$userId);
        echo json_encode(['ok' => true, 'id' => (int)$id]);
    }
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'class' => get_class($e), 'message' => $e->getMessage()]);
    exit(1);
}
