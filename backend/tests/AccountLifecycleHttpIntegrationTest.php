<?php

$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/jwt.php';

function accountDeleteHttp(int $userId, int $accountId): array {
    $base = rtrim(getenv('TEST_API_BASE') ?: 'http://localhost/sanie/backend/api', '/');
    $token = JWT::encode(['user_id' => $userId]);
    $context = stream_context_create(['http' => [
        'method' => 'DELETE',
        'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$token}\r\n",
        'ignore_errors' => true,
        'timeout' => 10,
    ]]);
    $raw = file_get_contents("{$base}/accounts/{$accountId}", false, $context);
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $match);
    return [(int) ($match[1] ?? 0), json_decode($raw ?: 'null', true)];
}

$db = (new Database())->getConnection();
$userId = null;
$failed = false;
try {
    $stmt = $db->prepare("INSERT INTO users(email,password,first_name) VALUES(?,?,?)");
    $stmt->execute([
        'account-lifecycle-http-' . bin2hex(random_bytes(5)) . '@example.invalid',
        password_hash('fixture', PASSWORD_DEFAULT),
        'Account Lifecycle HTTP',
    ]);
    $userId = (int) $db->lastInsertId();
    $stmt = $db->prepare("INSERT INTO accounts(user_id,name,type,balance,opening_balance,is_active,is_default) VALUES(?,'Recurring HTTP account','cash',0,0,1,0)");
    $stmt->execute([$userId]);
    $accountId = (int) $db->lastInsertId();
    $stmt = $db->prepare("INSERT INTO categories(user_id,name,type,status,is_default) VALUES(?,'Recurring HTTP category','expense','active',0)");
    $stmt->execute([$userId]);
    $categoryId = (int) $db->lastInsertId();
    $today = date('Y-m-d');
    $stmt = $db->prepare("INSERT INTO recurring_transactions(user_id,type,amount,account_id,category_id,frequency,start_date,next_occurrence,is_active) VALUES(?,'expense',100,?,?,'monthly',?,?,1)");
    $stmt->execute([$userId, $accountId, $categoryId, $today, $today]);
    $stmt = $db->prepare("INSERT INTO people(user_id,name,type,status) VALUES(?,'Recurring HTTP person','person','active')");
    $stmt->execute([$userId]);
    $personId = (int) $db->lastInsertId();
    $stmt = $db->prepare("INSERT INTO karobar_transactions(user_id,person_id,type,amount,account_id,description,transaction_date) VALUES(?,?,'lent',100,?,'Account lifecycle HTTP fixture',?)");
    $stmt->execute([$userId, $personId, $accountId, $today]);

    [$blockedStatus, $blocked] = accountDeleteHttp($userId, $accountId);
    if ($blockedStatus !== 409 || stripos((string) ($blocked['message'] ?? ''), 'recurring transaction') === false) {
        throw new RuntimeException("recurring account delete was not an intentional 409 (HTTP {$blockedStatus})");
    }
    $stmt = $db->prepare('SELECT COUNT(*) FROM accounts WHERE id=? AND user_id=?');
    $stmt->execute([$accountId, $userId]);
    if ((int) $stmt->fetchColumn() !== 1) {
        throw new RuntimeException('blocked account was deleted');
    }

    $db->prepare('DELETE FROM recurring_transactions WHERE user_id=? AND account_id=?')->execute([$userId, $accountId]);
    [$karobarStatus, $karobarBlocked] = accountDeleteHttp($userId, $accountId);
    if ($karobarStatus !== 409 || stripos((string) ($karobarBlocked['message'] ?? ''), 'Karobar history') === false) {
        throw new RuntimeException("Karobar account delete was not an intentional 409 (HTTP {$karobarStatus})");
    }
    $stmt = $db->prepare('SELECT account_id FROM karobar_transactions WHERE user_id=? AND person_id=?');
    $stmt->execute([$userId, $personId]);
    if ((int) $stmt->fetchColumn() !== $accountId) {
        throw new RuntimeException('blocked deletion removed the Karobar account link');
    }

    $db->prepare('DELETE FROM karobar_transactions WHERE user_id=? AND account_id=?')->execute([$userId, $accountId]);
    [$deletedStatus, $deleted] = accountDeleteHttp($userId, $accountId);
    if ($deletedStatus !== 200 || !($deleted['success'] ?? false)) {
        throw new RuntimeException("unreferenced account delete failed (HTTP {$deletedStatus})");
    }
    echo "PASS: recurring and Karobar account references return controlled conflicts and preserve history\n";
} catch (Throwable $e) {
    $failed = true;
    fwrite(STDERR, "FAIL: {$e->getMessage()}\n");
} finally {
    if ($userId !== null) {
        try {
            $db->prepare('DELETE FROM recurring_transactions WHERE user_id=?')->execute([$userId]);
            $db->prepare('DELETE FROM karobar_transactions WHERE user_id=?')->execute([$userId]);
            $db->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);
        } catch (Throwable $e) {
            fwrite(STDERR, "Cleanup warning: {$e->getMessage()}\n");
        }
    }
}

exit($failed ? 1 : 0);
