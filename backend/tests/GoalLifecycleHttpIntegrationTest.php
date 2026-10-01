<?php

$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/jwt.php';
require_once __DIR__ . '/../services/BalanceService.php';

function glhRequest(string $method, string $path, int $userId, ?array $data = null): array {
    $base = rtrim(getenv('TEST_API_BASE') ?: 'http://localhost/sanie/backend/api', '/');
    $token = JWT::encode(['user_id' => $userId]);
    $options = ['method' => $method, 'header' => "Authorization: Bearer {$token}\r\nContent-Type: application/json\r\n", 'ignore_errors' => true, 'timeout' => 15];
    if ($data !== null) $options['content'] = json_encode($data);
    $raw = file_get_contents("{$base}/{$path}", false, stream_context_create(['http' => $options]));
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $match);
    return [(int)($match[1] ?? 0), json_decode($raw ?: 'null', true)];
}
function glhAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function glhClose(float $expected, float $actual, string $message): void { if (abs($expected - $actual) > .001) throw new RuntimeException("{$message}: expected {$expected}, got {$actual}"); }

$db = (new Database())->getConnection();
$users = [];
$passed = 0;
$failed = 0;
$run = function (string $name, callable $test) use (&$passed, &$failed): void {
    try { $test(); $passed++; echo "PASS: {$name}\n"; }
    catch (Throwable $error) { $failed++; fwrite(STDERR, "FAIL: {$name} - {$error->getMessage()}\n"); }
};

try {
    foreach (['owner', 'foreign'] as $label) {
        $stmt = $db->prepare("INSERT INTO users(email,password,first_name) VALUES(?,?,'Goal Lifecycle')");
        $stmt->execute(['glh-' . $label . '-' . bin2hex(random_bytes(5)) . '@example.invalid', password_hash('fixture', PASSWORD_DEFAULT)]);
        $users[$label] = (int)$db->lastInsertId();
    }
    $owner = $users['owner']; $foreign = $users['foreign'];
    $stmt = $db->prepare("INSERT INTO accounts(user_id,name,type,balance,opening_balance,is_active,is_default) VALUES(?,'GL Cash','cash',10000,10000,1,1)");
    $stmt->execute([$owner]); $account = (int)$db->lastInsertId();
    $stmt->execute([$foreign]); $foreignAccount = (int)$db->lastInsertId();
    $balances = new BalanceService();
    $balance = fn(): float => $balances->calculateAccountBalance($account, $owner);
    $goal = function (int $id) use ($db, $owner): array { $stmt = $db->prepare('SELECT * FROM goals WHERE id=? AND user_id=?'); $stmt->execute([$id, $owner]); return $stmt->fetch(PDO::FETCH_ASSOC) ?: []; };

    $run('creation accepts valid whole and two-decimal definitions', function () use ($owner): void {
        foreach ([['100', '0'], ['100.25', '10.25']] as [$target, $initial]) {
            [$status, $response] = glhRequest('POST', 'goals', $owner, ['name' => 'Valid Goal ' . $target, 'target_amount' => $target, 'current_amount' => $initial, 'deadline' => '2025-01-01']);
            glhAssert($status === 201 && $response['data']['status'] === 'active', "valid target {$target} failed");
            glhClose((float)$initial, (float)$response['data']['current_amount'], 'valid initial amount changed');
        }
        [$status, $funded] = glhRequest('POST', 'goals', $owner, ['name' => 'Already Funded', 'target_amount' => '50.00', 'current_amount' => '50.00']);
        glhAssert($status === 201 && $funded['data']['status'] === 'completed', 'already-funded creation did not complete safely');
    });
    $run('creation rejects invalid targets and initial amounts without rounding', function () use ($owner): void {
        foreach ([0, -1, '1.001', 'NaN', 'INF', '1000000000000'] as $target) {
            [$status] = glhRequest('POST', 'goals', $owner, ['name' => 'Invalid', 'target_amount' => $target]);
            glhAssert($status === 422, 'invalid target was accepted: ' . (string)$target);
        }
        foreach ([-1, '1.001', 'NaN', '1000000000000'] as $initial) {
            [$status] = glhRequest('POST', 'goals', $owner, ['name' => 'Invalid Initial', 'target_amount' => 100, 'current_amount' => $initial]);
            glhAssert($status === 422, 'invalid initial was accepted: ' . (string)$initial);
        }
        [$status] = glhRequest('POST', 'goals', $owner, ['name' => 'Bad Date', 'target_amount' => 100, 'deadline' => '2026-02-30']);
        glhAssert($status === 422, 'invalid deadline was accepted');
    });

    [$status, $created] = glhRequest('POST', 'goals', $owner, ['name' => 'Lifecycle Goal', 'target_amount' => '1000.00', 'current_amount' => '100.00', 'deadline' => '2026-12-31']);
    glhAssert($status === 201, 'lifecycle fixture creation failed');
    $goalId = (int)$created['data']['id'];
    $version = (int)$created['data']['version'];
    $requestId = 'req_goal_lifecycle_create01';
    [$status, $contribution] = glhRequest('POST', "goals/{$goalId}/contribute", $owner, ['amount' => '300.00', 'account_id' => $account, 'date' => '2026-08-25', 'description' => 'Historical', 'client_request_id' => $requestId]);
    glhAssert($status === 201, 'active contribution failed');
    $contributionId = (int)$contribution['data']['contribution_id'];
    $contributionVersion = (int)$contribution['data']['contribution']['version'];
    [, $secondContribution] = glhRequest('POST', "goals/{$goalId}/contribute", $owner, ['amount' => '50.00', 'account_id' => $account, 'date' => '2026-08-25', 'description' => 'Paused delete', 'client_request_id' => 'req_goal_lifecycle_second1']);
    $secondContributionId = (int)$secondContribution['data']['contribution_id'];
    $secondContributionVersion = (int)$secondContribution['data']['contribution']['version'];

    $run('active pause and repeated pause preserve all money', function () use ($owner, $goalId, &$version, $balance, $goal): void {
        $before = $balance();
        $staleVersion = $version;
        [$status] = glhRequest('PUT', "goals/{$goalId}", $owner, ['status' => 'completed', 'base_version' => $version]);
        glhAssert($status === 422, 'manual completion bypassed target rules');
        [$status, $paused] = glhRequest('PUT', "goals/{$goalId}", $owner, ['status' => 'paused', 'base_version' => $version]);
        glhAssert($status === 200 && $paused['data']['status'] === 'paused', 'active to paused failed');
        $version = (int)$paused['data']['version'];
        glhClose($before, $balance(), 'pause moved account money');
        glhClose(450, (float)$goal($goalId)['current_amount'], 'pause changed saved value');
        [$status, $again] = glhRequest('PUT', "goals/{$goalId}", $owner, ['status' => 'paused', 'base_version' => $version]);
        glhAssert($status === 200 && $again['data']['status'] === 'paused', 'repeated pause was not controlled');
        $version = (int)$again['data']['version'];
        [$status] = glhRequest('PUT', "goals/{$goalId}", $owner, ['name' => 'Stale change', 'base_version' => $staleVersion]);
        glhAssert($status === 409, 'stale goal definition update was accepted');
    });
    $run('paused and completed goals reject new contribution identities', function () use ($owner, $goalId, $account): void {
        [$status] = glhRequest('POST', "goals/{$goalId}/contribute", $owner, ['amount' => 1, 'account_id' => $account, 'date' => '2026-08-25', 'client_request_id' => 'req_goal_paused_reject001']);
        glhAssert($status === 422, 'paused contribution was accepted');
    });
    $run('historical contribution edits remain valid while paused', function () use ($owner, $goalId, $contributionId, &$contributionVersion, $goal, $balance): void {
        [$status, $updated] = glhRequest('PUT', "goals/{$goalId}/contributions/{$contributionId}", $owner, ['amount' => '350.00', 'base_version' => $contributionVersion]);
        glhAssert($status === 200, 'paused historical edit failed');
        $contributionVersion = (int)$updated['data']['contribution']['version'];
        glhClose(500, (float)$goal($goalId)['current_amount'], 'paused edit did not recalculate goal');
        glhClose(9600, $balance(), 'paused edit account delta is wrong');
    });
    $run('historical contribution delete remains valid while paused', function () use ($owner, $goalId, $secondContributionId, $secondContributionVersion, $goal, $balance): void {
        [$status] = glhRequest('DELETE', "goals/{$goalId}/contributions/{$secondContributionId}", $owner, ['base_version' => $secondContributionVersion]);
        glhAssert($status === 200, 'paused historical delete failed');
        glhClose(450, (float)$goal($goalId)['current_amount'], 'paused delete did not recalculate goal');
        glhClose(9650, $balance(), 'paused delete account reversal is wrong');
    });
    $run('paused goal remains a savings asset but leaves active progress views', function () use ($owner, $goalId): void {
        [, $savings] = glhRequest('GET', 'savings/data', $owner);
        $savingsIds = array_map(fn($row) => (int)$row['goal']['id'], $savings['data']['goals']);
        glhAssert(in_array($goalId, $savingsIds, true), 'paused goal disappeared from savings assets');
        [, $dashboard] = glhRequest('GET', 'dashboard?start_date=2026-08-01&end_date=2026-08-31', $owner);
        $activeIds = array_map(fn($row) => (int)$row['goal']['id'], $dashboard['data']['goal_progress']);
        glhAssert(!in_array($goalId, $activeIds, true), 'paused goal remained in active Dashboard progress');
    });
    $run('raising target and normal resume preserve history and balance', function () use ($owner, $goalId, &$version, $balance): void {
        $before = $balance();
        [$status, $raised] = glhRequest('PUT', "goals/{$goalId}", $owner, ['target_amount' => '1500.00', 'base_version' => $version]);
        glhAssert($status === 200 && $raised['data']['status'] === 'paused', 'paused target raise failed');
        $version = (int)$raised['data']['version'];
        [$status, $active] = glhRequest('PUT', "goals/{$goalId}", $owner, ['status' => 'active', 'base_version' => $version]);
        glhAssert($status === 200 && $active['data']['status'] === 'active', 'paused to active failed');
        $version = (int)$active['data']['version'];
        glhClose($before, $balance(), 'resume moved account money');
        [$status, $again] = glhRequest('PUT', "goals/{$goalId}", $owner, ['status' => 'active', 'base_version' => $version]);
        glhAssert($status === 200 && $again['data']['status'] === 'active', 'repeated resume was not controlled');
        $version = (int)$again['data']['version'];
    });
    $run('active target lowering completes once and cannot reopen', function () use ($db, $owner, $goalId, &$version, $account): void {
        [$status, $completed] = glhRequest('PUT', "goals/{$goalId}", $owner, ['target_amount' => '400.00', 'base_version' => $version]);
        glhAssert($status === 200 && $completed['data']['status'] === 'completed', 'target lowering did not complete active goal');
        $version = (int)$completed['data']['version'];
        [$status] = glhRequest('PUT', "goals/{$goalId}", $owner, ['status' => 'active', 'base_version' => $version]);
        glhAssert($status === 422, 'completed goal reopened');
        [$status] = glhRequest('PUT', "goals/{$goalId}", $owner, ['target_amount' => '2000.00', 'base_version' => $version]);
        glhAssert($status === 422, 'completed target was changed to imply reopening');
        [$status, $descriptive] = glhRequest('PUT', "goals/{$goalId}", $owner, ['name' => 'Completed History', 'description' => 'Preserved', 'base_version' => $version]);
        glhAssert($status === 200 && $descriptive['data']['status'] === 'completed', 'completed descriptive edit failed');
        $version = (int)$descriptive['data']['version'];
        [$status] = glhRequest('POST', "goals/{$goalId}/contribute", $owner, ['amount' => 1, 'account_id' => $account, 'date' => '2026-08-25', 'client_request_id' => 'req_goal_completed_reject1']);
        glhAssert($status === 422, 'completed contribution was accepted');
        $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND type='goal_achieved' AND reference_id=?");
        $stmt->execute([$owner, $goalId]);
        glhAssert((int)$stmt->fetchColumn() === 1, 'achievement notification was duplicated');
    });
    $run('historical edit and delete remain valid after completion without reopening', function () use ($owner, $goalId, $contributionId, &$contributionVersion, $goal, $balance): void {
        [$status, $updated] = glhRequest('PUT', "goals/{$goalId}/contributions/{$contributionId}", $owner, ['amount' => '200.00', 'base_version' => $contributionVersion]);
        glhAssert($status === 200, 'completed historical edit failed');
        $contributionVersion = (int)$updated['data']['contribution']['version'];
        glhAssert($goal($goalId)['status'] === 'completed', 'completed goal reopened after edit');
        glhClose(300, (float)$goal($goalId)['current_amount'], 'completed edit current amount wrong');
        [$status] = glhRequest('DELETE', "goals/{$goalId}/contributions/{$contributionId}", $owner, ['base_version' => $contributionVersion]);
        glhAssert($status === 200, 'completed historical delete failed');
        glhAssert($goal($goalId)['status'] === 'completed', 'completed goal reopened after delete');
        glhClose(100, (float)$goal($goalId)['current_amount'], 'completed delete current amount wrong');
        glhClose(10000, $balance(), 'completed correction did not restore account');
    });
    $run('paused funded goal completes immediately when resumed', function () use ($owner, $account): void {
        [, $created] = glhRequest('POST', 'goals', $owner, ['name' => 'Resume Edge', 'target_amount' => 500, 'current_amount' => 100]);
        $id = (int)$created['data']['id']; $version = (int)$created['data']['version'];
        [, $contribution] = glhRequest('POST', "goals/{$id}/contribute", $owner, ['amount' => 300, 'account_id' => $account, 'date' => '2026-08-25', 'client_request_id' => 'req_goal_resume_edge_001']);
        [, $current] = glhRequest('GET', "goals/{$id}", $owner); $version = (int)$current['data']['version'];
        [, $paused] = glhRequest('PUT', "goals/{$id}", $owner, ['status' => 'paused', 'base_version' => $version]);
        [, $lowered] = glhRequest('PUT', "goals/{$id}", $owner, ['target_amount' => 350, 'base_version' => (int)$paused['data']['version']]);
        glhAssert($lowered['data']['status'] === 'paused', 'paused target edit completed too early');
        [$status, $resumed] = glhRequest('PUT', "goals/{$id}", $owner, ['status' => 'active', 'base_version' => (int)$lowered['data']['version']]);
        glhAssert($status === 200 && $resumed['data']['status'] === 'completed', 'funded paused goal did not complete on resume');
        glhRequest('DELETE', "goals/{$id}/contributions/{$contribution['data']['contribution_id']}", $owner, ['base_version' => $contribution['data']['contribution']['version']]);
    });
    $run('paused and completed assets remain in savings while progress views stay active-only', function () use ($owner, $goalId): void {
        [, $savings] = glhRequest('GET', 'savings/data', $owner);
        $goalIds = array_map(fn($row) => (int)$row['goal']['id'], $savings['data']['goals']);
        glhAssert(in_array($goalId, $goalIds, true), 'completed goal disappeared from savings history/assets');
        [, $dashboard] = glhRequest('GET', 'dashboard?start_date=2026-08-01&end_date=2026-08-31', $owner);
        $activeIds = array_map(fn($row) => (int)$row['goal']['id'], $dashboard['data']['goal_progress']);
        glhAssert(!in_array($goalId, $activeIds, true), 'completed goal inflated active Dashboard progress');
        [, $analysis] = glhRequest('GET', 'analysis', $owner);
        glhAssert(isset($analysis['data']), 'Analysis failed after lifecycle changes');
    });
    $run('foreign edits transitions and contributions are rejected', function () use ($foreign, $goalId, $account, $version): void {
        [$status] = glhRequest('PUT', "goals/{$goalId}", $foreign, ['name' => 'Foreign edit', 'base_version' => $version]);
        glhAssert($status === 404, 'foreign edit was not isolated');
        [$status] = glhRequest('PUT', "goals/{$goalId}", $foreign, ['status' => 'paused', 'base_version' => $version]);
        glhAssert($status === 404, 'foreign pause was not isolated');
        [$status] = glhRequest('POST', "goals/{$goalId}/contribute", $foreign, ['amount' => 1, 'account_id' => $account, 'date' => '2026-08-25', 'client_request_id' => 'req_goal_foreign_lifecycle1']);
        glhAssert($status === 403, 'foreign contribution was not isolated');
    });
} finally {
    foreach (array_reverse($users) as $id) {
        try { foreach (['notifications', 'transactions', 'goals', 'accounts'] as $table) $db->prepare("DELETE FROM {$table} WHERE user_id=?")->execute([$id]); $db->prepare('DELETE FROM users WHERE id=?')->execute([$id]); }
        catch (Throwable $ignored) {}
    }
}

echo "RESULT: {$passed} passed, {$failed} failed, 0 skipped\n";
exit($failed ? 1 : 0);
