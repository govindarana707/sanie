<?php

$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/jwt.php';

function transferHttpRequest(int $userId, array $body): array {
    $base = rtrim(getenv('TEST_API_BASE') ?: 'http://localhost/sanie/backend/api', '/');
    $token = JWT::encode(['user_id' => $userId]);
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$token}\r\n",
        'content' => json_encode($body),
        'ignore_errors' => true,
        'timeout' => 10,
    ]]);
    $raw = file_get_contents("{$base}/transactions", false, $context);
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $match);
    return [(int)($match[1] ?? 0), json_decode($raw ?: 'null', true)];
}

$db = (new Database())->getConnection();
$userId = null;
try {
    $stmt = $db->prepare("INSERT INTO users (email, password, first_name) VALUES (?, ?, 'Transfer HTTP')");
    $stmt->execute(['transfer-http-' . bin2hex(random_bytes(6)) . '@example.invalid', password_hash('fixture', PASSWORD_DEFAULT)]);
    $userId = (int)$db->lastInsertId();
    $accounts = [];
    foreach ([['HTTP Cash', 10000], ['HTTP Bank', 2000]] as [$name, $opening]) {
        $stmt = $db->prepare("INSERT INTO accounts (user_id, name, type, balance, opening_balance, is_active, is_default) VALUES (?, ?, 'cash', ?, ?, 1, 0)");
        $stmt->execute([$userId, $name, $opening, $opening]);
        $accounts[] = (int)$db->lastInsertId();
    }
    $stmt = $db->prepare("INSERT INTO categories (user_id, name, type, status) VALUES (?, 'HTTP transfer fee', 'expense', 'active')");
    $stmt->execute([$userId]);
    $categoryId = (int)$db->lastInsertId();

    $body = [
        'type' => 'transfer', 'amount' => 3000, 'fee_amount' => 100,
        'fee_category_id' => $categoryId, 'from_account_id' => $accounts[0],
        'to_account_id' => $accounts[1], 'date' => '2026-08-16',
        'description' => 'HTTP transfer',
        'client_request_id' => 'req_transfer_http_response_001',
    ];
    [$status, $response] = transferHttpRequest($userId, $body);
    if ($status !== 201 || !($response['success'] ?? false)) throw new RuntimeException("Create returned HTTP {$status}");
    $data = $response['data'] ?? [];
    if (empty($data['transfer_id']) || ($data['source_transaction']['direction'] ?? null) !== 'out'
        || ($data['destination_transaction']['direction'] ?? null) !== 'in'
        || (float)($data['fee_transaction']['amount'] ?? 0) !== 100.0) {
        throw new RuntimeException('Transfer response does not identify the full operation');
    }
    [$retryStatus, $retry] = transferHttpRequest($userId, $body);
    if ($retryStatus !== 201 || (int)($retry['data']['transfer_id'] ?? 0) !== (int)$data['transfer_id']) {
        throw new RuntimeException('HTTP retry did not return the original transfer group');
    }
    $stmt = $db->prepare('SELECT COUNT(*) FROM transactions WHERE user_id = ? AND (client_request_id = ? OR transfer_parent_id = ?)');
    $stmt->execute([$userId, $body['client_request_id'], $data['transfer_id']]);
    if ((int)$stmt->fetchColumn() !== 2) throw new RuntimeException('HTTP retry duplicated the transfer group');
    echo "PASS: transfer HTTP response and retry identify one complete linked group\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
    $failed = true;
} finally {
    if ($userId) {
        try { $db->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]); } catch (Throwable $ignored) {}
    }
}
exit(!empty($failed) ? 1 : 0);
