<?php

$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/jwt.php';

function creditorHttp(string $method, string $path, int $userId, ?array $body = null): array {
    $base = rtrim(getenv('TEST_API_BASE') ?: 'http://localhost/sanie/backend/api', '/');
    $token = JWT::encode(['user_id' => $userId]);
    $options = [
        'method' => $method,
        'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$token}\r\n",
        'ignore_errors' => true,
        'timeout' => 10,
    ];
    if ($body !== null) $options['content'] = json_encode($body);
    $raw = file_get_contents("{$base}/{$path}", false, stream_context_create(['http' => $options]));
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $match);
    return [(int)($match[1] ?? 0), json_decode($raw ?: 'null', true)];
}

function creditorAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$db = (new Database())->getConnection();
$users = [];
$failed = false;

try {
    foreach (['owner', 'foreign'] as $label) {
        $stmt = $db->prepare("INSERT INTO users (email,password,first_name) VALUES (?,?,'Creditor Test')");
        $stmt->execute(['creditor-' . $label . '-' . bin2hex(random_bytes(5)) . '@example.invalid', password_hash('fixture', PASSWORD_DEFAULT)]);
        $users[$label] = (int)$db->lastInsertId();
    }

    $owner = $users['owner'];
    $types = ['person', 'friend', 'family', 'shop', 'vendor', 'business', 'other'];
    $personIds = [];
    $insertPerson = $db->prepare('INSERT INTO people (user_id,name,type,status) VALUES (?,?,?,?)');
    foreach ($types as $type) {
        $insertPerson->execute([$owner, ucfirst($type) . ' Creditor', $type, 'active']);
        $personIds[$type] = (int)$db->lastInsertId();
    }
    $insertPerson->execute([$owner, 'Archived Creditor', 'shop', 'archived']);
    $archivedId = (int)$db->lastInsertId();
    $insertPerson->execute([$users['foreign'], 'Foreign Creditor', 'vendor', 'active']);
    $foreignId = (int)$db->lastInsertId();

    $stmt = $db->prepare("INSERT INTO categories (user_id,name,type,status,is_default) VALUES (?,'Creditor Eligibility','expense','active',0)");
    $stmt->execute([$owner]);
    $categoryId = (int)$db->lastInsertId();

    [$peopleStatus, $peopleResponse] = creditorHttp('GET', 'people?status=active&page=1&limit=200', $owner);
    creditorAssert($peopleStatus === 200 && ($peopleResponse['success'] ?? false), "People endpoint returned HTTP {$peopleStatus}");
    creditorAssert(is_array($peopleResponse['data']['people'] ?? null), 'People response is not exposed as data.people');
    $eligible = $peopleResponse['data']['people'];
    $eligibleIds = array_map(fn(array $person): int => (int)$person['id'], $eligible);
    foreach ($personIds as $type => $personId) {
        creditorAssert(in_array($personId, $eligibleIds, true), "Active {$type} is missing from creditor eligibility");
    }
    creditorAssert(!in_array($archivedId, $eligibleIds, true), 'Archived person appeared in active creditor eligibility');
    creditorAssert(!in_array($foreignId, $eligibleIds, true), 'Foreign person appeared in creditor eligibility');
    $settled = array_values(array_filter($eligible, fn(array $person): bool => (int)$person['id'] === $personIds['shop']))[0] ?? null;
    creditorAssert($settled !== null && abs((float)$settled['balance']) < 0.001, 'Zero-balance active shop was excluded');

    $expectedPayable = 0.0;
    foreach ($personIds as $type => $personId) {
        $amount = 100 + strlen($type);
        $expectedPayable += $amount;
        [$createStatus, $created] = creditorHttp('POST', 'transactions', $owner, [
            'type' => 'expense',
            'amount' => $amount,
            'date' => '2026-09-02',
            'category_id' => $categoryId,
            'payment_method' => 'credit',
            'creditor_id' => $personId,
            'due_date' => '2026-09-30',
            'description' => "{$type} eligibility purchase",
            'client_request_id' => "req_creditor_{$type}_eligibility",
        ]);
        creditorAssert($createStatus === 201 && ($created['success'] ?? false), "Credit purchase failed for active {$type}");
        creditorAssert((int)$created['data']['payable']['person_id'] === $personId, "Credit purchase linked the wrong {$type}");
        creditorAssert($created['data']['payable']['due_date'] === '2026-09-30', "Due date was not saved for {$type}");
    }

    [$missingStatus] = creditorHttp('POST', 'transactions', $owner, [
        'type' => 'expense', 'amount' => 1, 'date' => '2026-09-02',
        'category_id' => $categoryId, 'payment_method' => 'credit',
        'client_request_id' => 'req_creditor_missing_eligibility',
    ]);
    creditorAssert($missingStatus === 422, "Missing creditor returned HTTP {$missingStatus}");

    [$archivedStatus] = creditorHttp('POST', 'transactions', $owner, [
        'type' => 'expense', 'amount' => 1, 'date' => '2026-09-02',
        'category_id' => $categoryId, 'payment_method' => 'credit', 'creditor_id' => $archivedId,
        'client_request_id' => 'req_creditor_archived_eligibility',
    ]);
    creditorAssert($archivedStatus === 422, "Archived creditor returned HTTP {$archivedStatus}");

    [$foreignStatus] = creditorHttp('POST', 'transactions', $owner, [
        'type' => 'expense', 'amount' => 1, 'date' => '2026-09-02',
        'category_id' => $categoryId, 'payment_method' => 'credit', 'creditor_id' => $foreignId,
        'client_request_id' => 'req_creditor_foreign_eligibility',
    ]);
    creditorAssert($foreignStatus === 403, "Foreign creditor returned HTTP {$foreignStatus}");

    [$dashboardStatus, $dashboard] = creditorHttp('GET', 'karobar/dashboard', $owner);
    creditorAssert($dashboardStatus === 200, "Dashboard returned HTTP {$dashboardStatus}");
    creditorAssert(abs((float)$dashboard['data']['total_payable'] - $expectedPayable) < 0.001, 'Dashboard payable does not include all typed creditors');

    echo "PASS: all active person types, including settled people, are eligible creditors while archived and foreign people are rejected\n";
} catch (Throwable $error) {
    $failed = true;
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
} finally {
    foreach (array_reverse($users) as $userId) {
        foreach (['transactions', 'karobar_transactions', 'people', 'categories', 'accounts'] as $table) {
            try { $db->prepare("DELETE FROM {$table} WHERE user_id=?")->execute([$userId]); } catch (Throwable $ignored) {}
        }
        try { $db->prepare('DELETE FROM users WHERE id=?')->execute([$userId]); } catch (Throwable $ignored) {}
    }
}

exit($failed ? 1 : 0);
