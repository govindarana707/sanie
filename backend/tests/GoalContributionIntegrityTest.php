<?php

$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/AccountingService.php';
require_once __DIR__ . '/../services/BalanceService.php';

function goalAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function goalClose(float $expected, float $actual, string $message): void {
    if (abs($expected - $actual) > 0.001) throw new RuntimeException("{$message}: expected {$expected}, got {$actual}");
}
function goalExpect(string $class, callable $operation, string $contains = ''): void {
    try { $operation(); } catch (Throwable $e) {
        if (!is_a($e, $class)) throw new RuntimeException("Expected {$class}, got " . get_class($e) . ': ' . $e->getMessage());
        if ($contains !== '' && stripos($e->getMessage(), $contains) === false) throw new RuntimeException("Missing '{$contains}' in: {$e->getMessage()}");
        return;
    }
    throw new RuntimeException("Expected {$class}, but operation succeeded");
}

$db = (new Database())->getConnection();
if (!$db) exit(1);
$users = [];
$passed = 0;
$failed = 0;
$seq = 0;
$run = function (string $name, callable $test) use (&$passed, &$failed): void {
    try { $test(); $passed++; echo "PASS: {$name}\n"; }
    catch (Throwable $e) { $failed++; fwrite(STDERR, "FAIL: {$name} - {$e->getMessage()}\n"); }
};
$key = function (string $label) use (&$seq): string { return 'req_goal_' . (++$seq) . '_' . $label; };

try {
    foreach (['owner', 'foreign'] as $label) {
        $stmt = $db->prepare("INSERT INTO users (email,password,first_name) VALUES (?,?,'Goal Test')");
        $stmt->execute(['goal-' . $label . '-' . bin2hex(random_bytes(5)) . '@example.invalid', password_hash('fixture', PASSWORD_DEFAULT)]);
        $users[$label] = (int)$db->lastInsertId();
    }
    $account = function (int $user, string $name, float $opening) use ($db): int {
        $stmt = $db->prepare("INSERT INTO accounts (user_id,name,type,balance,opening_balance,is_active,is_default) VALUES (?,?,'cash',?,?,1,0)");
        $stmt->execute([$user, $name, $opening, $opening]);
        return (int)$db->lastInsertId();
    };
    $goal = function (int $user, string $name, float $target, float $initial) use ($db): int {
        $stmt = $db->prepare("INSERT INTO goals (user_id,name,target_amount,initial_amount,current_amount,status) VALUES (?,?,?,?,?,'active')");
        $stmt->execute([$user, $name, $target, $initial, $initial]);
        return (int)$db->lastInsertId();
    };
    $owner = $users['owner'];
    $foreign = $users['foreign'];
    $a = $account($owner, 'Cash A', 10000);
    $b = $account($owner, 'Cash B', 6000);
    $foreignAccount = $account($foreign, 'Foreign cash', 9000);
    $goalId = $goal($owner, 'Laptop', 5000, 2000);
    $foreignGoal = $goal($foreign, 'Foreign goal', 5000, 1000);
    $service = new AccountingService();
    $balances = new BalanceService();
    $txModel = new Transaction();
    $goalModel = new Goal();
    $balance = fn(int $id): float => $balances->calculateAccountBalance($id, $owner);
    $goalSaved = function (int $id, int $user = null) use ($goalModel, $owner): float {
        return (float)$goalModel->findById($id, $user ?? $owner)['current_amount'];
    };
    $payload = fn(int $accountId, $amount, string $requestId, string $note = 'Goal contribution'): array => [
        'account_id' => $accountId, 'amount' => $amount, 'date' => '2026-08-17',
        'description' => $note, 'client_request_id' => $requestId,
    ];

    $run('create is internal movement and not expense', function () use ($service, $owner, $goalId, $a, $b, $payload, $key, $balance, $goalSaved, $balances, $txModel): void {
        $assetsBefore = $balance($a) + $balance($b) + $goalSaved($goalId);
        $resource = $service->createGoalContribution($goalId, $owner, $payload($a, 1500, $key('create')));
        goalClose(8500, $balance($a), 'account after create');
        goalClose(3500, $goalSaved($goalId), 'goal after create');
        goalClose($assetsBefore, $balance($a) + $balance($b) + $goalSaved($goalId), 'assets after create');
        goalAssert($resource['contribution']['type'] === 'goal_contribution', 'wrong contribution classification');
        goalAssert((int)$resource['contribution']['goal_id'] === $goalId, 'missing durable goal link');
        $statementRows = (new Account())->getStatement($owner, $a);
        $statement = $balances->buildStatement($statementRows, 10000, $a);
        $entry = array_values(array_filter($statement['entries'], fn($row) => ($row['id'] ?? null) == $resource['contribution_id']))[0] ?? null;
        goalAssert($entry && (float)$entry['money_out'] === 1500.0, 'account statement omitted contribution money-out');
        $summary = (new Account())->getStatementSummary($owner, $a, '2026-08-01', '2026-08-31');
        goalClose(0, (float)$summary['total_expense'], 'account statement expense');
        goalClose(1500, (float)$summary['goal_contributions_out'], 'account statement goal movement');
        $stats = $balances->getStatistics($owner, '2026-08-17', '2026-08-17');
        goalClose(0, (float)$stats['total_expense'], 'daily/monthly expense');
        goalClose(0, (float)$txModel->getStatistics($owner, '2026-08-01', '2026-08-31')['total_expense'], 'overall transaction expense');
        goalAssert($txModel->getCategoryBreakdown($owner, '2026-08-01', '2026-08-31', 'expense') === [], 'category expense changed');
        $service->deleteGoalContribution($resource['contribution_id'], $owner, (int)$resource['contribution']['version']);
    });

    $run('edit upward/downward applies only delta and account switch reverses old source', function () use ($service, $owner, $goalId, $a, $b, $payload, $key, $balance, $goalSaved): void {
        $r = $service->createGoalContribution($goalId, $owner, $payload($a, 1000, $key('edit')));
        $r = $service->updateGoalContribution($r['contribution_id'], $owner, ['amount' => 1600], (int)$r['contribution']['version']);
        goalClose(8400, $balance($a), 'upward account delta');
        goalClose(3600, $goalSaved($goalId), 'upward goal delta');
        $r = $service->updateGoalContribution($r['contribution_id'], $owner, ['amount' => 900], (int)$r['contribution']['version']);
        goalClose(9100, $balance($a), 'downward account delta');
        goalClose(2900, $goalSaved($goalId), 'downward goal delta');
        $r = $service->updateGoalContribution($r['contribution_id'], $owner, ['account_id' => $b], (int)$r['contribution']['version']);
        goalClose(10000, $balance($a), 'old source restored');
        goalClose(5100, $balance($b), 'new source reduced');
        goalClose(2900, $goalSaved($goalId), 'goal unchanged on account switch');
        $service->deleteGoalContribution($r['contribution_id'], $owner, (int)$r['contribution']['version']);
        goalClose(6000, $balance($b), 'delete restored new source');
        goalClose(2000, $goalSaved($goalId), 'delete restored goal');
    });

    $run('manual scenarios A B C preserve exact 15000 assets', function () use ($db, $account, $goal, $owner, $service, $payload, $key, $balances, $goalModel): void {
        $cash = $account($owner, 'Manual Cash', 12000);
        $manualGoal = $goal($owner, 'Manual Goal', 20000, 3000);
        $saved = fn(): float => (float)$goalModel->findById($manualGoal, $owner)['current_amount'];
        $r = $service->createGoalContribution($manualGoal, $owner, $payload($cash, 2000, $key('manual')));
        goalClose(10000, $balances->calculateAccountBalance($cash, $owner), 'Scenario A cash');
        goalClose(5000, $saved(), 'Scenario A goal');
        goalClose(15000, $balances->calculateAccountBalance($cash, $owner) + $saved(), 'Scenario A net worth');
        $r = $service->updateGoalContribution($r['contribution_id'], $owner, ['amount' => 3000], (int)$r['contribution']['version']);
        goalClose(9000, $balances->calculateAccountBalance($cash, $owner), 'Scenario B cash');
        goalClose(6000, $saved(), 'Scenario B goal');
        goalClose(15000, $balances->calculateAccountBalance($cash, $owner) + $saved(), 'Scenario B net worth');
        $service->deleteGoalContribution($r['contribution_id'], $owner, (int)$r['contribution']['version']);
        goalClose(12000, $balances->calculateAccountBalance($cash, $owner), 'Scenario C cash');
        goalClose(3000, $saved(), 'Scenario C goal');
    });

    $run('invalid amounts and foreign entities are rejected', function () use ($service, $owner, $goalId, $foreignGoal, $a, $foreignAccount, $payload, $key): void {
        foreach ([0, -1, 'NaN', 'INF', '1.001'] as $value) {
            goalExpect(InvalidArgumentException::class, fn() => $service->createGoalContribution($goalId, $owner, $payload($a, $value, $key('invalid'))));
        }
        $badDate = $payload($a, 1, $key('bad_date'));
        $badDate['date'] = '2026-02-30';
        goalExpect(InvalidArgumentException::class, fn() => $service->createGoalContribution($goalId, $owner, $badDate), 'date');
        goalExpect(GoalContributionAuthorizationException::class, fn() => $service->createGoalContribution($foreignGoal, $owner, $payload($a, 1, $key('foreign_goal'))));
        goalExpect(GoalContributionAuthorizationException::class, fn() => $service->createGoalContribution($goalId, $owner, $payload($foreignAccount, 1, $key('foreign_account'))));
    });

    $run('idempotency creates one effect and mismatched retry conflicts', function () use ($db, $service, $owner, $goalId, $a, $payload, $key, $balance, $goalSaved): void {
        $request = $key('retry');
        $data = $payload($a, 500, $request);
        $first = $service->createGoalContribution($goalId, $owner, $data);
        $second = $service->createGoalContribution($goalId, $owner, $data);
        goalAssert($first['contribution_id'] === $second['contribution_id'], 'retry returned another contribution');
        $stmt = $db->prepare('SELECT COUNT(*) FROM transactions WHERE user_id=? AND client_request_id=?');
        $stmt->execute([$owner, $request]);
        goalAssert((int)$stmt->fetchColumn() === 1, 'retry duplicated transaction');
        goalClose(9500, $balance($a), 'retry account effect');
        goalClose(2500, $goalSaved($goalId), 'retry goal effect');
        goalExpect(TransactionConflictException::class, fn() => $service->createGoalContribution($goalId, $owner, $payload($a, 501, $request)), 'payload');
        $service->deleteGoalContribution($first['contribution_id'], $owner);
    });

    foreach (['after_contribution_record', 'after_goal_progress'] as $point) {
        $run("rollback at {$point}", function () use ($db, $owner, $goalId, $a, $payload, $key, $point, $balance, $goalSaved): void {
            $probe = new AccountingService();
            $probe->setGoalContributionFailureInjector(function (string $at) use ($point): void { if ($at === $point) throw new RuntimeException('Injected failure'); });
            $request = $key($point);
            goalExpect(RuntimeException::class, fn() => $probe->createGoalContribution($goalId, $owner, $payload($a, 100, $request)), 'Injected');
            $stmt = $db->prepare('SELECT COUNT(*) FROM transactions WHERE user_id=? AND client_request_id=?');
            $stmt->execute([$owner, $request]);
            goalAssert((int)$stmt->fetchColumn() === 0, 'orphan contribution survived rollback');
            goalClose(10000, $balance($a), 'rollback account');
            goalClose(2000, $goalSaved($goalId), 'rollback goal');
        });
    }

    $run('stale and foreign mutations cannot corrupt contribution', function () use ($service, $owner, $foreign, $goalId, $a, $payload, $key): void {
        $r = $service->createGoalContribution($goalId, $owner, $payload($a, 300, $key('stale')));
        $version = (int)$r['contribution']['version'];
        $service->updateGoalContribution($r['contribution_id'], $owner, ['amount' => 350], $version);
        goalExpect(TransactionConflictException::class, fn() => $service->updateGoalContribution($r['contribution_id'], $owner, ['amount' => 400], $version));
        goalExpect(GoalContributionAuthorizationException::class, fn() => $service->updateGoalContribution($r['contribution_id'], $foreign, ['amount' => 1]));
        goalExpect(GoalContributionAuthorizationException::class, fn() => $service->deleteGoalContribution($r['contribution_id'], $foreign));
        $current = (new Transaction())->findById($r['contribution_id'], $owner);
        goalClose(350, (float)$current['amount'], 'stale mutation changed amount');
        $service->deleteGoalContribution($r['contribution_id'], $owner, (int)$current['version']);
    });

    $run('bulk delete reverses contribution and goal deletion is protected', function () use ($db, $service, $owner, $goalId, $a, $payload, $key, $balance, $goalSaved, $goalModel): void {
        $r = $service->createGoalContribution($goalId, $owner, $payload($a, 200, $key('bulk')));
        goalExpect(PDOException::class, fn() => $goalModel->delete($goalId, $owner));
        $service->bulkDeleteTransactions([$r['contribution_id']], $owner);
        goalClose(10000, $balance($a), 'bulk account restore');
        goalClose(2000, $goalSaved($goalId), 'bulk goal restore');
        goalAssert((new Transaction())->findById($r['contribution_id'], $owner) === false, 'bulk contribution survived');
    });

    $run('existing over-target behavior remains allowed', function () use ($service, $owner, $goalId, $a, $payload, $key, $goalSaved): void {
        $r = $service->createGoalContribution($goalId, $owner, $payload($a, 4000, $key('over_target')));
        goalClose(6000, $goalSaved($goalId), 'over-target contribution was not preserved');
        $service->deleteGoalContribution($r['contribution_id'], $owner);
    });
} finally {
    foreach ($users as $userId) {
        try { $db->prepare('DELETE FROM transactions WHERE user_id=?')->execute([$userId]); } catch (Throwable $ignored) {}
        try { $db->prepare('DELETE FROM goals WHERE user_id=?')->execute([$userId]); } catch (Throwable $ignored) {}
        try { $db->prepare('DELETE FROM accounts WHERE user_id=?')->execute([$userId]); } catch (Throwable $ignored) {}
        try { $db->prepare('DELETE FROM users WHERE id=?')->execute([$userId]); } catch (Throwable $ignored) {}
    }
}
echo "RESULT: {$passed} passed, {$failed} failed, 0 skipped\n";
exit($failed === 0 ? 0 : 1);
