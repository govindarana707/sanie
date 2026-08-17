<?php

$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/jwt.php';
require_once __DIR__ . '/../services/KarobarService.php';

function httpPaymentRequest(string $path, int $userId, array $body): array {
    $base = rtrim(getenv('TEST_API_BASE') ?: 'http://localhost/sanie/backend/api', '/');
    $token = JWT::encode(['user_id' => $userId]);
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$token}\r\n",
            'content' => json_encode($body),
            'ignore_errors' => true,
            'timeout' => 10,
        ],
    ]);
    $raw = file_get_contents("{$base}/karobar/{$path}", false, $context);
    $statusLine = $http_response_header[0] ?? '';
    preg_match('/\s(\d{3})\s/', $statusLine, $match);
    return [(int)($match[1] ?? 0), json_decode($raw ?: 'null', true)];
}

function httpKarobarGet(string $path, int $userId): array {
    $base = rtrim(getenv('TEST_API_BASE') ?: 'http://localhost/sanie/backend/api', '/');
    $token = JWT::encode(['user_id' => $userId]);
    $context = stream_context_create(['http'=>[
        'method'=>'GET','header'=>"Authorization: Bearer {$token}\r\n",
        'ignore_errors'=>true,'timeout'=>10,
    ]]);
    $raw=file_get_contents("{$base}/{$path}",false,$context);
    $statusLine=$http_response_header[0]??'';preg_match('/\s(\d{3})\s/',$statusLine,$match);
    return [(int)($match[1]??0),json_decode($raw?:'null',true)];
}

function requireHttp(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$db = (new Database())->getConnection();
$userIds = [];

try {
    foreach (['owner', 'foreign'] as $label) {
        $stmt = $db->prepare("INSERT INTO users (email, password, first_name) VALUES (?, ?, 'Karobar HTTP')");
        $stmt->execute([
            'karobar-http-' . $label . '-' . bin2hex(random_bytes(6)) . '@example.invalid',
            password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
        ]);
        $userIds[$label] = (int)$db->lastInsertId();
    }

    $accounts = [];
    $people = [];
    foreach ($userIds as $label => $userId) {
        $stmt = $db->prepare(
            "INSERT INTO accounts (user_id, name, type, balance, opening_balance, is_active, is_default)
             VALUES (?, ?, 'cash', 5000, 5000, 1, 1)"
        );
        $stmt->execute([$userId, ucfirst($label) . ' HTTP Cash']);
        $accounts[$label] = (int)$db->lastInsertId();

        $stmt = $db->prepare("INSERT INTO people (user_id, name, type) VALUES (?, ?, 'person')");
        $stmt->execute([$userId, ucfirst($label) . ' HTTP Person']);
        $people[$label] = (int)$db->lastInsertId();
    }

    $service = new KarobarService();
    $service->createTransaction([
        'person_id' => $people['owner'],
        'account_id' => $accounts['owner'],
        'type' => 'borrowed',
        'amount' => 1000,
        'transaction_date' => '2026-01-10',
        'due_date' => '2026-01-20',
    ], $userIds['owner']);

    $base = [
        'person_id' => $people['owner'],
        'account_id' => $accounts['owner'],
        'transaction_date' => '2026-01-11',
    ];

    [$status, $body] = httpPaymentRequest('repayment', $userIds['owner'], $base + [
        'amount' => 0,
        'client_request_id' => 'http-zero-repayment',
    ]);
    requireHttp($status === 422 && ($body['success'] ?? true) === false, 'Zero repayment did not return 422');

    [$status, $body] = httpPaymentRequest('repayment', $userIds['owner'], $base + [
        'amount' => 1001,
        'client_request_id' => 'http-over-repayment',
    ]);
    requireHttp($status === 422 && stripos($body['message'] ?? '', 'exceeds') !== false, 'Overpayment did not return a clear 422');

    [$status] = httpPaymentRequest('repayment', $userIds['owner'], array_merge($base, [
        'person_id' => $people['foreign'],
        'amount' => 100,
        'client_request_id' => 'http-foreign-person',
    ]));
    requireHttp($status === 403, 'Foreign person did not return 403: ' . $status);

    [$status] = httpPaymentRequest('repayment', $userIds['owner'], array_merge($base, [
        'account_id' => $accounts['foreign'],
        'amount' => 100,
        'client_request_id' => 'http-foreign-account',
    ]));
    requireHttp($status === 403, 'Foreign account did not return 403: ' . $status);

    $valid = $base + ['amount' => 400, 'client_request_id' => 'http-valid-repayment'];
    [$status, $body] = httpPaymentRequest('repayment', $userIds['owner'], $valid);
    requireHttp($status === 201 && ($body['success'] ?? false) === true, 'Valid repayment did not return 201');
    requireHttp(abs((float)($body['data']['remaining_outstanding'] ?? -1) - 600) < 0.001, 'Response has wrong remaining outstanding');
    $paymentId = (int)($body['data']['id'] ?? 0);
    requireHttp($paymentId > 0, 'Response omitted payment ID');

    [$retryStatus, $retryBody] = httpPaymentRequest('repayment', $userIds['owner'], $valid);
    requireHttp($retryStatus === 201 && (int)($retryBody['data']['id'] ?? 0) === $paymentId, 'HTTP retry was not idempotent');

    $service->createTransaction([
        'person_id' => $people['owner'],
        'account_id' => $accounts['owner'],
        'type' => 'lent',
        'amount' => 800,
        'transaction_date' => '2026-01-10',
        'due_date' => '2026-01-20',
    ], $userIds['owner']);
    [$status, $body] = httpPaymentRequest('receiving', $userIds['owner'], $base + [
        'amount' => 300,
        'client_request_id' => 'http-valid-receiving',
    ]);
    requireHttp($status === 201 && abs((float)($body['data']['remaining_outstanding'] ?? -1) - 500) < 0.001, 'Valid receiving response is incorrect');

    [$status,$dashboard]=httpKarobarGet('karobar/dashboard',$userIds['owner']);
    requireHttp($status===200&&($dashboard['success']??false),'Dashboard endpoint failed');
    requireHttp(abs((float)$dashboard['data']['total_payable']-600)<.001,'Dashboard payable is stale');
    requireHttp(abs((float)$dashboard['data']['total_receivable']-500)<.001,'Dashboard receivable is stale');
    requireHttp(abs((float)$dashboard['data']['overdue_amount']-1100)<.001,'Dashboard overdue did not use remaining amounts');

    [$status,$reports]=httpKarobarGet('karobar/reports?report_type=all',$userIds['owner']);
    requireHttp($status===200&&($reports['success']??false),'Reports endpoint failed');
    requireHttp(abs((float)$reports['data']['payable_report'][0]['outstanding_amount']-600)<.001,'Payable report differs from dashboard');
    requireHttp(abs((float)$reports['data']['receivable_report'][0]['outstanding_amount']-500)<.001,'Receivable report differs from dashboard');

    [$status,$ledger]=httpKarobarGet('people/'.$people['owner'].'/ledger',$userIds['owner']);
    requireHttp($status===200&&($ledger['success']??false),'Person endpoint failed');
    requireHttp(abs((float)$ledger['data']['person']['payable_outstanding']-600)<.001,'Person payable differs from dashboard');
    requireHttp(abs((float)$ledger['data']['person']['receivable_outstanding']-500)<.001,'Person receivable differs from dashboard');

    echo "PASS: payment HTTP validation, authorization, idempotency, and dashboard/report/person parity\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
    $failed = true;
} finally {
    foreach ($userIds as $userId) {
        try {
            $stmt = $db->prepare('DELETE FROM users WHERE id = ?');
            $stmt->execute([$userId]);
        } catch (Throwable $cleanupError) {
            fwrite(STDERR, 'Cleanup warning: ' . $cleanupError->getMessage() . "\n");
        }
    }
}

exit(!empty($failed) ? 1 : 0);
