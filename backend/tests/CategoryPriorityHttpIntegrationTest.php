<?php

$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/jwt.php';

function categoryPriorityHttp(string $method, string $path, int $userId, ?array $body = null): array {
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
    $statusLine = $http_response_header[0] ?? '';
    preg_match('/\s(\d{3})\s/', $statusLine, $match);
    return [(int)($match[1] ?? 0), json_decode($raw ?: 'null', true)];
}

function categoryPriorityHttpAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$db = (new Database())->getConnection();
$users = [];
$failed = false;

try {
    foreach (['owner', 'foreign'] as $label) {
        $stmt = $db->prepare('INSERT INTO users (email,password,first_name) VALUES (?,?,?)');
        $stmt->execute([
            'category-priority-http-' . $label . '-' . bin2hex(random_bytes(5)) . '@example.invalid',
            password_hash('Category-Priority-HTTP-2026!', PASSWORD_DEFAULT),
            'Priority HTTP',
        ]);
        $users[$label] = (int)$db->lastInsertId();
    }
    $owner = $users['owner'];
    $foreign = $users['foreign'];
    $makeCategory = function (int $userId, string $name, string $type = 'expense') use ($db): int {
        $stmt = $db->prepare(
            "INSERT INTO categories (user_id,name,type,status,is_default,is_pinned,sort_order)
             VALUES (?, ?, ?, 'active', 0, 0, 999)"
        );
        $stmt->execute([$userId, $name, $type]);
        return (int)$db->lastInsertId();
    };
    $food = $makeCategory($owner, 'HTTP Food');
    $transport = $makeCategory($owner, 'HTTP Transportation');
    $salary = $makeCategory($owner, 'HTTP Salary', 'income');
    $foreignCategory = $makeCategory($foreign, 'HTTP Foreign');
    $db->exec("UPDATE categories SET is_default = 1 WHERE id = {$food}");

    [$status, $pinned] = categoryPriorityHttp('PUT', "categories/{$food}", $owner, ['is_pinned' => true]);
    categoryPriorityHttpAssert($status === 200 && (int)$pinned['data']['is_pinned'] === 1 && (int)$pinned['data']['sort_order'] === 1, 'default-category pin update failed');

    [$status] = categoryPriorityHttp('PUT', "categories/{$transport}", $owner, ['is_pinned' => true]);
    categoryPriorityHttpAssert($status === 200, 'second pin update failed');
    [$status, $reordered] = categoryPriorityHttp('POST', 'categories/reorder', $owner, [
        'orders_by_type' => [
            'expense' => [
                ['id' => $transport, 'sort_order' => 200],
                ['id' => $food, 'sort_order' => 200],
            ],
        ],
    ]);
    categoryPriorityHttpAssert($status === 200 && ($reordered['success'] ?? false), 'atomic pinned reorder failed');
    [, $expenseList] = categoryPriorityHttp('GET', 'categories?type=expense&status=active', $owner);
    categoryPriorityHttpAssert(
        array_slice(array_map('intval', array_column($expenseList['data'], 'id')), 0, 2) === [$transport, $food],
        'dropdown endpoint did not return manual pinned order'
    );
    [$status] = categoryPriorityHttp('PUT', "categories/{$transport}", $owner, ['is_pinned' => false]);
    categoryPriorityHttpAssert($status === 200, 'unpin failed');
    [$status, $repinned] = categoryPriorityHttp('PUT', "categories/{$transport}", $owner, ['is_pinned' => true]);
    categoryPriorityHttpAssert($status === 200 && (int)$repinned['data']['sort_order'] <= 2, 'repin produced an invalid priority gap');

    [, $incomeList] = categoryPriorityHttp('GET', 'categories?type=income&status=active', $owner);
    categoryPriorityHttpAssert(count($incomeList['data']) === 1 && (int)$incomeList['data'][0]['id'] === $salary, 'income list was affected by expense priorities');

    [$status] = categoryPriorityHttp('PUT', "categories/{$food}", $owner, ['is_pinned' => 'true']);
    categoryPriorityHttpAssert($status === 422, 'non-boolean pin payload was accepted');
    [$status] = categoryPriorityHttp('PUT', "categories/{$foreignCategory}", $owner, ['is_pinned' => true]);
    categoryPriorityHttpAssert($status === 404, 'foreign category pin did not preserve tenant-isolated 404');

    [$status] = categoryPriorityHttp('GET', "categories/{$transport}/archive", $owner);
    categoryPriorityHttpAssert($status === 200, 'pinned category archive failed');
    $row = $db->query("SELECT status,is_pinned,sort_order FROM categories WHERE id = {$transport}")->fetch(PDO::FETCH_ASSOC);
    categoryPriorityHttpAssert($row['status'] === 'archived' && (int)$row['is_pinned'] === 0 && (int)$row['sort_order'] === 999, 'archived category retained stale pin state');

    echo "PASS: category priority HTTP pin, validation, atomic reorder, type isolation, archive, and ownership contracts\n";
} catch (Throwable $error) {
    $failed = true;
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
} finally {
    foreach (array_reverse($users) as $userId) {
        try { $db->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]); } catch (Throwable $ignored) {}
    }
}

exit($failed ? 1 : 0);
