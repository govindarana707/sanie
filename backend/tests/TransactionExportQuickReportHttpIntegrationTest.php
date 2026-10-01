<?php

$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/jwt.php';

function teqhRequest(string $path, int $userId): array {
    $base = rtrim(getenv('TEST_API_BASE') ?: 'http://localhost/sanie/backend/api', '/');
    $token = JWT::encode(['user_id' => $userId]);
    $raw = file_get_contents("{$base}/{$path}", false, stream_context_create(['http' => ['method' => 'GET', 'header' => "Authorization: Bearer {$token}\r\n", 'ignore_errors' => true, 'timeout' => 10]]));
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $match);
    return [(int)($match[1] ?? 0), json_decode($raw ?: 'null', true)];
}
function teqhAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }

$db = (new Database())->getConnection();
$users = [];
$failed = false;
try {
    foreach (['owner', 'foreign'] as $label) {
        $stmt = $db->prepare("INSERT INTO users(email,password,first_name) VALUES(?,?,'TEQ HTTP')");
        $stmt->execute(['teqh-' . $label . '-' . bin2hex(random_bytes(5)) . '@example.invalid', password_hash('fixture', PASSWORD_DEFAULT)]);
        $users[$label] = (int)$db->lastInsertId();
    }
    $account = function (int $user, string $name) use ($db): int { $stmt = $db->prepare("INSERT INTO accounts(user_id,name,type,balance,opening_balance,is_active,is_default) VALUES(?,?,'cash',0,0,1,0)"); $stmt->execute([$user, $name]); return (int)$db->lastInsertId(); };
    $ownedAccount = $account($users['owner'], 'TEQH Owned');
    $foreignAccount = $account($users['foreign'], 'TEQH Foreign');
    $stmt = $db->prepare("INSERT INTO categories(user_id,name,type,status,is_default) VALUES(?,'TEQH Expense','expense','active',0)");
    $stmt->execute([$users['owner']]); $category = (int)$db->lastInsertId();
    $insert = $db->prepare("INSERT INTO transactions(user_id,account_id,from_account_id,category_id,amount,type,payment_method,date,description) VALUES(?,?,?,?,?,'expense','cash','2026-08-25',?)");
    for ($i = 1; $i <= 51; $i++) $insert->execute([$users['owner'], $ownedAccount, $ownedAccount, $category, $i, "TEQH row {$i}"]);
    $stmt = $db->prepare("INSERT INTO people(user_id,name,type,status) VALUES(?,'TEQH Shop','shop','active')");
    $stmt->execute([$users['owner']]); $person = (int)$db->lastInsertId();
    $stmt = $db->prepare("INSERT INTO karobar_transactions(user_id,person_id,type,amount,transaction_date,description) VALUES(?,?,'borrowed',77,'2026-08-25','TEQH Karobar row')");
    $stmt->execute([$users['owner'], $person]);

    [$status, $response] = teqhRequest("transactions/query?start_date=2026-08-01&end_date=2026-08-31&account_id={$ownedAccount}&page=2&limit=50", $users['owner']);
    teqhAssert($status === 200 && $response['success'] === true, 'query endpoint failed');
    teqhAssert($response['data']['pagination']['total_rows'] === 51 && count($response['data']['transactions']) === 1, 'HTTP pagination is incomplete');
    teqhAssert((float)$response['data']['summary']['total_expense'] === 1326.0, 'HTTP summary used the second page only');
    [$status, $response] = teqhRequest("transactions/query?account_id={$foreignAccount}", $users['owner']);
    teqhAssert($status === 200 && $response['data']['pagination']['total_rows'] === 0, 'foreign account records leaked');
    [$status] = teqhRequest('transactions/query?start_date=2026-08-32', $users['owner']);
    teqhAssert($status === 422, 'invalid date was accepted');
    [$status, $response] = teqhRequest('transactions?scope=karobar', $users['owner']);
    teqhAssert($status === 200 && $response['data']['pagination']['total_rows'] === 1, 'direct transaction endpoint did not expose Karobar scope');
    [$status, $response] = teqhRequest('transactions?scope=personal&limit=100', $users['owner']);
    teqhAssert($status === 200 && $response['data']['pagination']['total_rows'] === 51, 'direct transaction endpoint personal scope is incorrect');
    [$status, $response] = teqhRequest('transactions?limit=50', $users['owner']);
    teqhAssert($status === 200 && array_is_list($response['data']) && count($response['data']) === 50, 'legacy unscoped response contract changed');
    [$status] = teqhRequest('transactions?scope=business', $users['owner']);
    teqhAssert($status === 422, 'invalid scope was accepted');
    echo "PASS: transaction query HTTP pagination, unified scopes, legacy compatibility, validation, and ownership are authoritative\n";
} catch (Throwable $error) {
    $failed = true; fwrite(STDERR, "FAIL: {$error->getMessage()}\n");
} finally {
    foreach (array_reverse($users) as $id) {
        try { foreach (['transactions', 'karobar_transactions', 'people', 'categories', 'accounts'] as $table) $db->prepare("DELETE FROM {$table} WHERE user_id=?")->execute([$id]); $db->prepare('DELETE FROM users WHERE id=?')->execute([$id]); }
        catch (Throwable $ignored) {}
    }
}
exit($failed ? 1 : 0);
