<?php

$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/AccountingService.php';

$db = (new Database())->getConnection();
$userId = null;

try {
    $stmt = $db->prepare("INSERT INTO users (email, password, first_name) VALUES (?, ?, 'Bulk Test')");
    $stmt->execute(['bulk-' . bin2hex(random_bytes(6)) . '@example.invalid', password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT)]);
    $userId = (int)$db->lastInsertId();

    $stmt = $db->prepare("INSERT INTO accounts (user_id, name, type, balance, opening_balance, is_active, is_default) VALUES (?, 'Cash', 'cash', 1000, 1000, 1, 1)");
    $stmt->execute([$userId]);
    $accountId = (int)$db->lastInsertId();

    $stmt = $db->prepare("INSERT INTO categories (user_id, name, type, is_default) VALUES (?, 'Test Expense', 'expense', 0)");
    $stmt->execute([$userId]);
    $categoryId = (int)$db->lastInsertId();

    $service = new AccountingService();
    $common = ['account_id' => $accountId, 'from_account_id' => $accountId, 'category_id' => $categoryId, 'date' => date('Y-m-d')];
    $first = $service->createTransaction($userId, $common + ['type' => 'expense', 'amount' => 100]);
    $second = $service->createTransaction($userId, $common + ['type' => 'expense', 'amount' => 50]);

    $deleted = $service->bulkDeleteTransactions([(int)$first, (int)$second], $userId);
    $stmt = $db->prepare('SELECT balance FROM accounts WHERE id = ?');
    $stmt->execute([$accountId]);
    $balance = (float)$stmt->fetchColumn();
    if ($deleted !== 2 || abs($balance - 1000) > 0.001) {
        throw new RuntimeException("Expected 2 deletes and Rs 1000 balance; got {$deleted} and {$balance}");
    }

    echo "Transaction bulk-delete integration test passed.\n";
} finally {
    if ($userId) {
        $stmt = $db->prepare('DELETE FROM users WHERE id = ?');
        $stmt->execute([$userId]);
    }
}
