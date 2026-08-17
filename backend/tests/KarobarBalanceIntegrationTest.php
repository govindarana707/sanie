<?php

$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/KarobarService.php';

function assertBalance(PDO $db, int $accountId, float $expected, string $stage): void {
    $stmt = $db->prepare('SELECT balance FROM accounts WHERE id = ?');
    $stmt->execute([$accountId]);
    $actual = (float)$stmt->fetchColumn();
    if (abs($actual - $expected) > 0.001) {
        throw new RuntimeException("{$stage}: expected {$expected}, got {$actual}");
    }
}

$db = (new Database())->getConnection();
$email = 'karobar-test-' . bin2hex(random_bytes(6)) . '@example.invalid';
$userId = null;

try {
    $stmt = $db->prepare("INSERT INTO users (email, password, first_name) VALUES (?, ?, 'Karobar Test')");
    $stmt->execute([$email, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT)]);
    $userId = (int)$db->lastInsertId();

    $stmt = $db->prepare("INSERT INTO accounts (user_id, name, type, balance, opening_balance, is_active, is_default) VALUES (?, 'Test Cash', 'cash', 1000, 1000, 1, 1)");
    $stmt->execute([$userId]);
    $accountId = (int)$db->lastInsertId();

    $stmt = $db->prepare("INSERT INTO people (user_id, name, type) VALUES (?, 'Test Person', 'person')");
    $stmt->execute([$userId]);
    $personId = (int)$db->lastInsertId();

    $service = new KarobarService();
    $base = ['person_id' => $personId, 'account_id' => $accountId, 'transaction_date' => date('Y-m-d')];

    $borrowedId = $service->createTransaction($base + ['type' => 'borrowed', 'amount' => 500], $userId);
    assertBalance($db, $accountId, 1500, 'borrowed');

    $lentId = $service->createTransaction($base + ['type' => 'lent', 'amount' => 200], $userId);
    assertBalance($db, $accountId, 1300, 'lent');

    $service->createTransaction($base + ['type' => 'returned', 'amount' => 50], $userId);
    assertBalance($db, $accountId, 1350, 'returned');

    $service->createTransaction($base + ['type' => 'repaid', 'amount' => 100], $userId);
    assertBalance($db, $accountId, 1250, 'repaid');

    $service->updateTransaction($borrowedId, $base + ['type' => 'borrowed', 'amount' => 300], $userId);
    assertBalance($db, $accountId, 1050, 'update');

    $service->deleteTransaction($lentId, $userId);
    assertBalance($db, $accountId, 1250, 'delete');

    echo "Karobar balance integration test passed.\n";
} finally {
    if ($userId) {
        $stmt = $db->prepare('DELETE FROM users WHERE id = ?');
        $stmt->execute([$userId]);
    }
}
