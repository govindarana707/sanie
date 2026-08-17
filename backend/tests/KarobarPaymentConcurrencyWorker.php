<?php

$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/../services/KarobarService.php';

[$script, $userId, $personId, $accountId, $amount, $requestId, $barrier] = $argv;

$deadline = microtime(true) + 10;
while (!file_exists($barrier) && microtime(true) < $deadline) {
    usleep(10000);
}
try {
    $service = new KarobarService();
    $id = $service->processRepayment([
        'person_id' => (int)$personId,
        'account_id' => (int)$accountId,
        'amount' => (float)$amount,
        'transaction_date' => '2026-01-11',
        'client_request_id' => $requestId,
    ], (int)$userId);
    echo json_encode(['ok' => true, 'id' => (int)$id]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'class' => get_class($e), 'message' => $e->getMessage()]);
}
