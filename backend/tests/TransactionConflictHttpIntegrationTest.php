<?php

$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/jwt.php';

$connection = (new Database())->getConnection();
$fixture = $connection?->query(
    "SELECT id, user_id, version FROM transactions
     WHERE type IN ('income', 'expense')
       AND COALESCE(payment_method, '') <> 'credit'
       AND karobar_transaction_id IS NULL
     ORDER BY id ASC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
if (!$fixture) {
    fwrite(STDOUT, "SKIP: no offline-eligible transaction exists for HTTP conflict testing\n");
    exit(0);
}

$apiBase = rtrim(getenv('TEST_API_BASE') ?: 'http://localhost/sanie/backend/api', '/');
$request = static function ($method, $userId, $id, $body) use ($apiBase) {
    $token = JWT::encode(['user_id' => (int)$userId]);
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$token}\r\n",
            'content' => json_encode($body),
            'ignore_errors' => true,
            'timeout' => 10
        ]
    ]);
    $response = file_get_contents("{$apiBase}/transactions/{$id}", false, $context);
    $statusLine = $http_response_header[0] ?? '';
    preg_match('/\s(\d{3})\s/', $statusLine, $match);
    return [(int)($match[1] ?? 0), json_decode($response ?: 'null', true)];
};

[$status, $body] = $request(
    'PUT',
    $fixture['user_id'],
    $fixture['id'],
    ['base_version' => (int)$fixture['version'] + 99]
);
if ($status !== 409 || ($body['code'] ?? null) !== 'CONFLICT' || (int)($body['serverData']['version'] ?? 0) !== (int)$fixture['version']) {
    fwrite(STDERR, "FAIL: stale HTTP update did not return a structured 409 conflict\n");
    exit(1);
}

[$deleteStatus, $deleteBody] = $request(
    'DELETE',
    $fixture['user_id'],
    $fixture['id'],
    [
        'base_version' => (int)$fixture['version'] + 99,
        'client_request_id' => '66666666-6666-4666-a666-666666666666'
    ]
);
if ($deleteStatus !== 409 || ($deleteBody['code'] ?? null) !== 'CONFLICT') {
    fwrite(STDERR, "FAIL: stale HTTP delete did not return a structured 409 conflict\n");
    exit(1);
}

$otherUser = $connection->prepare('SELECT id FROM users WHERE id <> :user_id ORDER BY id ASC LIMIT 1');
$otherUser->execute([':user_id' => $fixture['user_id']]);
$otherUserId = $otherUser->fetchColumn();
if ($otherUserId) {
    [$authorizationStatus] = $request('PUT', $otherUserId, $fixture['id'], ['base_version' => $fixture['version']]);
    if ($authorizationStatus !== 404) {
        fwrite(STDERR, "FAIL: cross-user HTTP update was not rejected\n");
        exit(1);
    }
    [$deleteAuthorizationStatus] = $request('DELETE', $otherUserId, $fixture['id'], [
        'base_version' => $fixture['version'],
        'client_request_id' => '77777777-7777-4777-a777-777777777777'
    ]);
    if ($deleteAuthorizationStatus !== 404) {
        fwrite(STDERR, "FAIL: cross-user HTTP delete was not rejected\n");
        exit(1);
    }
}

$unchanged = $connection->prepare('SELECT version FROM transactions WHERE id = :id');
$unchanged->execute([':id' => $fixture['id']]);
if ((int)$unchanged->fetchColumn() !== (int)$fixture['version']) {
    fwrite(STDERR, "FAIL: conflict probe changed the server transaction\n");
    exit(1);
}

fwrite(STDOUT, "PASS: HTTP 409 conflicts are structured and unauthorized writes are rejected\n");
