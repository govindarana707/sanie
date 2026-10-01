<?php

$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Account.php';

function alAssert(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$db = (new Database())->getConnection();
if (!$db) {
    exit(1);
}

$passed = 0;
$failed = 0;
$userId = null;
$run = function (string $name, callable $test) use (&$passed, &$failed): void {
    try {
        $test();
        $passed++;
        echo "PASS: $name\n";
    } catch (Throwable $e) {
        $failed++;
        fwrite(STDERR, "FAIL: $name - {$e->getMessage()}\n");
    }
};

try {
    $stmt = $db->prepare("INSERT INTO users(email,password,first_name) VALUES(?,?,?)");
    $stmt->execute([
        'account-lifecycle-' . bin2hex(random_bytes(5)) . '@example.invalid',
        password_hash('fixture', PASSWORD_DEFAULT),
        'Account Lifecycle',
    ]);
    $userId = (int) $db->lastInsertId();

    $createAccount = function (string $name) use ($db, $userId): int {
        $stmt = $db->prepare("INSERT INTO accounts(user_id,name,type,balance,opening_balance,is_active,is_default) VALUES(?,?,'cash',0,0,1,0)");
        $stmt->execute([$userId, $name]);
        return (int) $db->lastInsertId();
    };
    $model = new Account();

    $run('unused account remains deletable', function () use ($createAccount, $model, $userId): void {
        $accountId = $createAccount('Unused account');
        alAssert($model->getTransactionCount($accountId, $userId) === 0, 'unused account has transaction references');
        alAssert($model->getRecurringTransactionCount($accountId, $userId) === 0, 'unused account has recurring references');
        alAssert($model->delete($accountId, $userId), 'unused account was not deleted');
        alAssert($model->findById($accountId, $userId) === false, 'deleted account still exists');
    });

    $run('ordinary transaction reference is detected', function () use ($db, $createAccount, $model, $userId): void {
        $accountId = $createAccount('Transaction account');
        $stmt = $db->prepare("INSERT INTO transactions(user_id,account_id,type,amount,date,description) VALUES(?,?,'income',100,?,'Account lifecycle fixture')");
        $stmt->execute([$userId, $accountId, date('Y-m-d')]);
        alAssert($model->getTransactionCount($accountId, $userId) === 1, 'ordinary transaction reference was not detected');
    });

    $run('recurring-only reference is detected before delete', function () use ($db, $createAccount, $model, $userId): void {
        $accountId = $createAccount('Recurring account');
        $stmt = $db->prepare("INSERT INTO categories(user_id,name,type,status,is_default) VALUES(?,'Account lifecycle recurring','expense','active',0)");
        $stmt->execute([$userId]);
        $categoryId = (int) $db->lastInsertId();
        $today = date('Y-m-d');
        $stmt = $db->prepare("INSERT INTO recurring_transactions(user_id,type,amount,account_id,category_id,frequency,start_date,next_occurrence,is_active) VALUES(?,'expense',100,?,?,'monthly',?,?,1)");
        $stmt->execute([$userId, $accountId, $categoryId, $today, $today]);

        alAssert($model->getTransactionCount($accountId, $userId) === 0, 'recurring template was incorrectly counted as financial history');
        alAssert($model->getRecurringTransactionCount($accountId, $userId) === 1, 'recurring account reference was not detected');
        alAssert($model->findById($accountId, $userId) !== false, 'recurring account disappeared');
    });

    $run('recurring reference count is owner scoped', function () use ($db, $createAccount, $model, $userId): void {
        $accountId = $createAccount('Owner scoped account');
        alAssert($model->getRecurringTransactionCount($accountId, $userId + 999999) === 0, 'foreign user can see recurring references');
    });

    $run('Karobar-only financial history is detected before delete', function () use ($db, $createAccount, $model, $userId): void {
        $accountId = $createAccount('Karobar account');
        $stmt = $db->prepare("INSERT INTO people(user_id,name,type,status) VALUES(?,'Account lifecycle person','person','active')");
        $stmt->execute([$userId]);
        $personId = (int) $db->lastInsertId();
        $stmt = $db->prepare("INSERT INTO karobar_transactions(user_id,person_id,type,amount,account_id,description,transaction_date) VALUES(?,?,'lent',100,?,'Account lifecycle fixture',?)");
        $stmt->execute([$userId, $personId, $accountId, date('Y-m-d')]);

        alAssert($model->getTransactionCount($accountId, $userId) === 0, 'Karobar history was incorrectly counted as an ordinary transaction');
        alAssert($model->getKarobarTransactionCount($accountId, $userId) === 1, 'Karobar account history was not detected');
        alAssert($model->getKarobarTransactionCount($accountId, $userId + 999999) === 0, 'foreign user can see Karobar account history');
    });
} finally {
    if ($userId !== null) {
        try {
            $db->prepare('DELETE FROM recurring_transactions WHERE user_id=?')->execute([$userId]);
            $db->prepare('DELETE FROM transactions WHERE user_id=?')->execute([$userId]);
            $db->prepare('DELETE FROM karobar_transactions WHERE user_id=?')->execute([$userId]);
            $db->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);
        } catch (Throwable $e) {
            fwrite(STDERR, "Cleanup warning: {$e->getMessage()}\n");
        }
    }
}

echo "RESULT: $passed passed, $failed failed, 0 skipped\n";
exit($failed ? 1 : 0);
