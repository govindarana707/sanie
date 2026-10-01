<?php

$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/jwt.php';

function csvHttp(string $path, int $userId, array $body): array {
    $base = rtrim(getenv('TEST_API_BASE') ?: 'http://localhost/sanie/backend/api', '/');
    $token = JWT::encode(['user_id' => $userId]);
    $options = [
        'method' => 'POST', 'ignore_errors' => true, 'timeout' => 30,
        'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$token}\r\n",
        'content' => json_encode($body),
    ];
    $raw = file_get_contents("{$base}/{$path}", false, stream_context_create(['http' => $options]));
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $match);
    return [(int)($match[1] ?? 0), json_decode($raw ?: 'null', true)];
}
function chAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }

$db = (new Database())->getConnection();
$user = null;
try {
    $stmt = $db->prepare('INSERT INTO users(email,password,first_name) VALUES(?,?,?)');
    $stmt->execute(['csv-http-' . bin2hex(random_bytes(5)) . '@example.invalid', password_hash('fixture', PASSWORD_DEFAULT), 'CSV HTTP']);
    $user = (int)$db->lastInsertId();
    $stmt = $db->prepare("INSERT INTO accounts(user_id,name,type,balance,opening_balance,is_active,is_default) VALUES(?,'HTTP Cash','cash',100,100,1,0)");
    $stmt->execute([$user]);
    $stmt = $db->prepare("INSERT INTO categories(user_id,name,type,status,is_default) VALUES(?,'HTTP Food','expense','active',0)");
    $stmt->execute([$user]);

    $today = date('Y-m-d');
    $csv = "Date,Type,Amount,Account,From Account,To Account,Category,Subcategory,Payment Method,Description\n{$today},expense,10,HTTP Cash,,,HTTP Food,,cash,HTTP import\n";
    $identity = hash('sha256', $csv);
    [$status, $preview] = csvHttp('transactions/import/preview', $user, ['csv_content' => $csv, 'batch_identity' => $identity]);
    chAssert($status === 200 && ($preview['data']['counts']['ready'] ?? 0) === 1, 'HTTP preview contract failed');
    [$status, $first] = csvHttp('transactions/import', $user, ['csv_content' => $csv, 'batch_identity' => $identity]);
    chAssert($status === 200 && ($first['data']['counts']['imported'] ?? 0) === 1, 'HTTP import contract failed');
    [$status, $retry] = csvHttp('transactions/import', $user, ['csv_content' => $csv, 'batch_identity' => $identity]);
    chAssert($status === 200 && ($retry['data']['counts']['already_imported'] ?? 0) === 1, 'HTTP retry was not idempotent');
    [$status, $mismatch] = csvHttp('transactions/import/preview', $user, ['csv_content' => $csv, 'batch_identity' => str_repeat('0', 64)]);
    chAssert($status === 422 && !($mismatch['success'] ?? true), 'HTTP endpoint trusted a mismatched batch identity');
    echo "PASS: CSV import HTTP preview, execution, retry, and fingerprint verification contracts\n";
} finally {
    if ($user) {
        foreach (['notifications', 'transactions', 'categories', 'accounts'] as $table) $db->prepare("DELETE FROM {$table} WHERE user_id=?")->execute([$user]);
        $db->prepare('DELETE FROM users WHERE id=?')->execute([$user]);
    }
}
