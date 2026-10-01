<?php

$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/jwt.php';

function p16rHttp(string $path, int $userId): array {
    $base = rtrim(getenv('TEST_API_BASE') ?: 'http://localhost/sanie/backend/api', '/');
    $token = JWT::encode(['user_id' => $userId]);
    $context = stream_context_create(['http' => [
        'method' => 'GET',
        'header' => "Authorization: Bearer {$token}\r\n",
        'ignore_errors' => true,
        'timeout' => 10,
    ]]);
    $raw = file_get_contents("{$base}/{$path}", false, $context);
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $match);
    return [(int)($match[1] ?? 0), json_decode($raw ?: 'null', true)];
}
function p16rAssert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function p16rClose(float $expected, float $actual, string $message): void { p16rAssert(abs($expected - $actual) < .001, "{$message}: expected {$expected}, got {$actual}"); }

$db = (new Database())->getConnection();
$users = [];
$failed = false;
try {
    foreach (['owner', 'foreign'] as $label) {
        $db->prepare("INSERT INTO users(email,password,first_name) VALUES(?,?,'Phase 16')")
            ->execute(['phase16-'.$label.'-'.bin2hex(random_bytes(5)).'@example.invalid', password_hash('fixture', PASSWORD_DEFAULT)]);
        $users[$label] = (int)$db->lastInsertId();
    }
    foreach ($users as $label => $userId) {
        $db->prepare("INSERT INTO accounts(user_id,name,type,balance,opening_balance,is_active,is_default) VALUES(?,?,'cash',1000,1000,1,0)")
            ->execute([$userId, 'P16 '.$label]);
        $accounts[$label] = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO categories(user_id,name,type,status,is_default) VALUES(?,?,'expense','active',0)")
            ->execute([$userId, 'P16 '.$label.' expense']);
        $categories[$label] = (int)$db->lastInsertId();
    }
    $makeBudget = function(int $userId, int $categoryId, string $name, string $start, string $end) use ($db): int {
        $db->prepare("INSERT INTO budgets(user_id,name,amount,period,category_id,start_date,end_date,alert_threshold,is_active) VALUES(?, ?, 1000, 'yearly', ?, ?, ?, 80, 1)")
            ->execute([$userId, $name, $categoryId, $start, $end]);
        return (int)$db->lastInsertId();
    };
    $budget2025 = $makeBudget($users['owner'], $categories['owner'], 'Owner 2025', '2025-01-01', '2025-12-31');
    $budget2026 = $makeBudget($users['owner'], $categories['owner'], 'Owner 2026', '2026-01-01', '2026-12-31');
    $makeBudget($users['foreign'], $categories['foreign'], 'Foreign 2025', '2025-01-01', '2025-12-31');
    $insertTx = $db->prepare("INSERT INTO transactions(user_id,account_id,category_id,type,amount,date,description) VALUES(?,?,?,'expense',?,?,?)");
    $insertTx->execute([$users['owner'], $accounts['owner'], $categories['owner'], 100, '2025-06-15', 'Owner past range']);
    $insertTx->execute([$users['owner'], $accounts['owner'], $categories['owner'], 200, '2026-06-15', 'Owner current range']);
    $insertTx->execute([$users['owner'], $accounts['owner'], $categories['owner'], 29, '2028-02-29', 'Owner leap range']);
    $insertTx->execute([$users['foreign'], $accounts['foreign'], $categories['foreign'], 999, '2025-06-15', 'Foreign private range']);

    [$status, $health] = p16rHttp('reports/budget-health?start_date=2025-01-01&end_date=2025-12-31', $users['owner']);
    p16rAssert($status === 200, 'custom budget-health range failed');
    $ids = array_map('intval', array_column($health['data']['budgets'], 'id'));
    p16rAssert($ids === [$budget2025] && !in_array($budget2026, $ids, true), 'budget-health did not preserve selected range');
    p16rClose(100, (float)$health['data']['total_spent'], 'budget-health selected-range total');

    [$status, $incomeExpense] = p16rHttp('reports/income-expense?start_date=2025-01-01&end_date=2025-12-31&page=1&limit=50', $users['owner']);
    p16rAssert($status === 200 && (int)$incomeExpense['data']['pagination']['total_rows'] === 1, 'income/expense historical range population is wrong');
    p16rClose(100, (float)$incomeExpense['data']['summary']['total_expense'], 'income/expense historical total');
    [$status, $category] = p16rHttp('reports/category-breakdown?start_date=2025-01-01&end_date=2025-12-31', $users['owner']);
    p16rAssert($status === 200, 'category historical range failed');
    p16rClose(100, (float)$category['data']['total_expense'], 'category historical total or owner isolation');

    [$status, $leap] = p16rHttp('reports/income-expense?start_date=2028-02-29&end_date=2028-02-29', $users['owner']);
    p16rAssert($status === 200 && (int)$leap['data']['pagination']['total_rows'] === 1, 'leap-day report range failed');
    p16rClose(29, (float)$leap['data']['summary']['total_expense'], 'leap-day report total');
    [$status] = p16rHttp('reports/budget-health?start_date=2026-12-31&end_date=2026-01-01', $users['owner']);
    p16rAssert($status === 422, 'reversed report range was accepted');

    echo "PASS: Phase 16 report ranges, budget-health consistency, leap day, and ownership are correct\n";
} catch (Throwable $e) {
    $failed = true;
    fwrite(STDERR, 'FAIL: '.$e->getMessage()."\n");
} finally {
    foreach (array_reverse($users) as $userId) {
        foreach (['transactions', 'budgets', 'categories', 'accounts'] as $table) {
            try { $db->prepare("DELETE FROM {$table} WHERE user_id=?")->execute([$userId]); } catch (Throwable $ignored) {}
        }
        try { $db->prepare('DELETE FROM users WHERE id=?')->execute([$userId]); } catch (Throwable $ignored) {}
    }
}
exit($failed ? 1 : 0);
