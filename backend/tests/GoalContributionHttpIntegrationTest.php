<?php

$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/jwt.php';

function goalHttp(string $method, string $path, int $userId, ?array $body = null): array {
    $base = rtrim(getenv('TEST_API_BASE') ?: 'http://localhost/sanie/backend/api', '/');
    $token = JWT::encode(['user_id' => $userId]);
    $options = ['method' => $method, 'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$token}\r\n", 'ignore_errors' => true, 'timeout' => 10];
    if ($body !== null) $options['content'] = json_encode($body);
    $raw = file_get_contents("{$base}/{$path}", false, stream_context_create(['http' => $options]));
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $match);
    return [(int)($match[1] ?? 0), json_decode($raw ?: 'null', true)];
}

$db = (new Database())->getConnection();
$users = [];
try {
    foreach (['owner', 'foreign'] as $label) {
        $stmt = $db->prepare("INSERT INTO users (email,password,first_name) VALUES (?,?,'Goal HTTP')");
        $stmt->execute(['goal-http-' . $label . '-' . bin2hex(random_bytes(5)) . '@example.invalid', password_hash('fixture', PASSWORD_DEFAULT)]);
        $users[$label] = (int)$db->lastInsertId();
    }
    $stmt = $db->prepare("INSERT INTO accounts (user_id,name,type,balance,opening_balance,is_active,is_default) VALUES (?,'HTTP Cash','cash',10000,10000,1,0)");
    $stmt->execute([$users['owner']]);
    $accountId = (int)$db->lastInsertId();
    $stmt->execute([$users['foreign']]);
    $foreignAccount = (int)$db->lastInsertId();
    $stmt = $db->prepare("INSERT INTO goals (user_id,name,target_amount,initial_amount,current_amount,status) VALUES (?,'HTTP Goal',10000,2000,2000,'active')");
    $stmt->execute([$users['owner']]);
    $goalId = (int)$db->lastInsertId();

    [$beforeStatus, $before] = goalHttp('GET', 'dashboard?start_date=2026-08-01&end_date=2026-08-31', $users['owner']);
    if ($beforeStatus !== 200 || abs((float)$before['data']['net_worth'] - 12000) > 0.001) throw new RuntimeException('Dashboard omitted initial goal assets');
    $payload = ['amount' => 1500, 'account_id' => $accountId, 'date' => '2026-08-17', 'description' => 'HTTP contribution', 'client_request_id' => 'req_goal_http_integrity_001'];
    [$status, $created] = goalHttp('POST', "goals/{$goalId}/contribute", $users['owner'], $payload);
    if ($status !== 201 || !($created['success'] ?? false)) throw new RuntimeException("Contribution create returned HTTP {$status}");
    $id = (int)($created['data']['contribution_id'] ?? 0);
    $version = (int)($created['data']['contribution']['version'] ?? 0);
    if (!$id || ($created['data']['contribution']['type'] ?? '') !== 'goal_contribution') throw new RuntimeException('Contribution response is incomplete');
    [$retryStatus, $retry] = goalHttp('POST', "goals/{$goalId}/contribute", $users['owner'], $payload);
    if ($retryStatus !== 201 || (int)($retry['data']['contribution_id'] ?? 0) !== $id) throw new RuntimeException('HTTP retry duplicated contribution');
    [$dashboardStatus, $dashboard] = goalHttp('GET', 'dashboard?start_date=2026-08-01&end_date=2026-08-31', $users['owner']);
    if ($dashboardStatus !== 200 || abs((float)$dashboard['data']['net_worth'] - 12000) > 0.001 || abs((float)$dashboard['data']['statistics']['total_expense']) > 0.001) {
        throw new RuntimeException('Dashboard net-worth or expense invariant failed');
    }
    [$foreignStatus] = goalHttp('POST', "goals/{$goalId}/contribute", $users['foreign'], $payload + ['account_id' => $foreignAccount, 'client_request_id' => 'req_goal_http_foreign_001']);
    if ($foreignStatus !== 403) throw new RuntimeException("Foreign goal returned HTTP {$foreignStatus}");
    [$updateStatus, $updated] = goalHttp('PUT', "goals/{$goalId}/contributions/{$id}", $users['owner'], ['amount' => 2000, 'base_version' => $version]);
    if ($updateStatus !== 200 || (float)($updated['data']['contribution']['amount'] ?? 0) !== 2000.0) throw new RuntimeException('HTTP edit failed');
    $updatedVersion = (int)$updated['data']['contribution']['version'];
    [$staleStatus] = goalHttp('PUT', "goals/{$goalId}/contributions/{$id}", $users['owner'], ['amount' => 2100, 'base_version' => $version]);
    if ($staleStatus !== 409) throw new RuntimeException("Stale edit returned HTTP {$staleStatus}");
    [$deleteStatus] = goalHttp('DELETE', "goals/{$goalId}/contributions/{$id}", $users['owner'], ['base_version' => $updatedVersion]);
    if ($deleteStatus !== 200) throw new RuntimeException("Contribution delete returned HTTP {$deleteStatus}");
    [$afterStatus, $after] = goalHttp('GET', 'dashboard?start_date=2026-08-01&end_date=2026-08-31', $users['owner']);
    if ($afterStatus !== 200 || abs((float)$after['data']['net_worth'] - 12000) > 0.001) throw new RuntimeException('Delete did not restore dashboard invariant');
    echo "PASS: goal contribution HTTP lifecycle preserves expense and net worth\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
    $failed = true;
} finally {
    foreach ($users as $userId) {
        try { $db->prepare('DELETE FROM transactions WHERE user_id=?')->execute([$userId]); } catch (Throwable $ignored) {}
        try { $db->prepare('DELETE FROM goals WHERE user_id=?')->execute([$userId]); } catch (Throwable $ignored) {}
        try { $db->prepare('DELETE FROM accounts WHERE user_id=?')->execute([$userId]); } catch (Throwable $ignored) {}
        try { $db->prepare('DELETE FROM users WHERE id=?')->execute([$userId]); } catch (Throwable $ignored) {}
    }
}
exit(!empty($failed) ? 1 : 0);
