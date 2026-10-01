<?php
$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../services/CredentialService.php';
require_once __DIR__ . '/../includes/jwt.php';

function prAssert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function prRejects(callable $fn, int $status = 422): void {
    try { $fn(); throw new RuntimeException('Expected password-reset rejection.'); }
    catch (CredentialValidationException $e) { prAssert($e->status === $status, 'Unexpected rejection status.'); }
}

$db = (new Database())->getConnection();
$userIds = [];
$passed = 0;
$failed = 0;
$run = function (string $name, callable $test) use (&$passed, &$failed): void {
    try { $test(); $passed++; echo "PASS: {$name}\n"; }
    catch (Throwable $e) { $failed++; fwrite(STDERR, "FAIL: {$name} - {$e->getMessage()}\n"); }
};
$create = function (string $label, string $password = 'oldPassword123') use ($db, &$userIds): array {
    $email = 'password-reset-' . $label . '-' . bin2hex(random_bytes(5)) . '@example.invalid';
    $stmt = $db->prepare("INSERT INTO users(email,password,first_name) VALUES(?,?,'Password Reset')");
    $stmt->execute([$email, password_hash($password, PASSWORD_DEFAULT)]);
    $id = (int)$db->lastInsertId();
    $userIds[] = $id;
    return [$id, $email];
};

try {
    $service = new CredentialService($db);
    $run('known and unknown reset requests preserve generic service semantics', function () use ($service, $create): void {
        [, $email] = $create('request');
        $known = $service->createPasswordReset($email);
        $unknown = $service->createPasswordReset('missing-' . bin2hex(random_bytes(5)) . '@example.invalid');
        prAssert(is_array($known) && $unknown === null, 'Known/unknown request behavior is incorrect.');
    });
    $run('token is random, hash-only, expiring, and replacement invalidates the older token', function () use ($db, $service, $create): void {
        [$id, $email] = $create('token');
        $first = $service->createPasswordReset($email);
        $second = $service->createPasswordReset($email);
        prAssert(strlen($first['token']) === 64 && ctype_xdigit($first['token']), 'Reset secret is not a 32-byte hexadecimal token.');
        prAssert($first['token'] !== $second['token'], 'Repeated requests produced the same token.');
        $stmt = $db->prepare('SELECT token,expires_at>CURRENT_TIMESTAMP FROM password_reset_tokens WHERE user_id=?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        prAssert($row['token'] === hash('sha256', $second['token']), 'Database does not contain the token hash.');
        prAssert($row['token'] !== $second['token'] && (int)$row['expires_at>CURRENT_TIMESTAMP'] === 1, 'Raw token was stored or expiry is not fresh.');
        prAssert(!$service->validatePasswordResetToken($first['token']) && $service->validatePasswordResetToken($second['token']), 'Replacement semantics failed.');
    });
    $run('malformed and expired tokens are rejected', function () use ($db, $service, $create): void {
        [$id, $email] = $create('expiry');
        prAssert(!$service->validatePasswordResetToken('not-a-token'), 'Malformed token validated.');
        $reset = $service->createPasswordReset($email);
        $db->prepare('UPDATE password_reset_tokens SET expires_at=DATE_SUB(CURRENT_TIMESTAMP,INTERVAL 1 SECOND) WHERE user_id=?')->execute([$id]);
        prAssert(!$service->validatePasswordResetToken($reset['token']), 'Expired token validated.');
        prRejects(fn() => $service->resetPassword(['token' => $reset['token'], 'new_password' => 'newPassword456']));
    });
    $run('password policy, confirmation, and reuse rules match authenticated password changes', function () use ($service, $create): void {
        [, $email] = $create('policy');
        $reset = $service->createPasswordReset($email);
        prRejects(fn() => $service->resetPassword(['token' => $reset['token'], 'new_password' => 'short']));
        prRejects(fn() => $service->resetPassword(['token' => $reset['token'], 'new_password' => 'newPassword456', 'new_password_confirmation' => 'differentPass789']));
        prRejects(fn() => $service->resetPassword(['token' => $reset['token'], 'new_password' => 'oldPassword123']));
        prAssert($service->validatePasswordResetToken($reset['token']), 'Validation failure consumed a usable token.');
    });
    $run('successful reset changes login, consumes token, and revokes only owner sessions', function () use ($db, $service, $create): void {
        [$id, $email] = $create('success');
        [$foreignId] = $create('foreign', 'foreignPass123');
        $oldSession = JWT::encode(['user_id' => $id]);
        $foreignSession = JWT::encode(['user_id' => $foreignId]);
        $reset = $service->createPasswordReset($email);
        $result = $service->resetPassword(['token' => $reset['token'], 'new_password' => 'newPassword456', 'new_password_confirmation' => 'newPassword456']);
        $model = new User();
        prAssert($model->login($email, 'oldPassword123') === false, 'Old password still logs in.');
        prAssert((int)$model->login($email, 'newPassword456')['id'] === $id, 'New password login failed.');
        prAssert(!$service->validatePasswordResetToken($reset['token']), 'Consumed token remains valid.');
        prRejects(fn() => $service->resetPassword(['token' => $reset['token'], 'new_password' => 'anotherPass789']));
        prAssert(JWT::getUserIdFromToken($oldSession) === false, 'Owner old session remains valid.');
        prAssert(JWT::getUserIdFromToken($foreignSession) === $foreignId, 'Unrelated user session was revoked.');
        prAssert($result['user_id'] === $id, 'Reset mutated or returned the wrong owner.');
    });
    $run('credential-write failure rolls back password, version, and token state', function () use ($db, $create): void {
        [$id, $email] = $create('rollback-write');
        $probe = new CredentialService($db, function ($point): void { if ($point === 'after_reset_credential_update') throw new RuntimeException('Injected write failure.'); });
        $reset = $probe->createPasswordReset($email);
        $before = $db->query("SELECT password,token_version,password_changed_at FROM users WHERE id={$id}")->fetch();
        try { $probe->resetPassword(['token' => $reset['token'], 'new_password' => 'newPassword456']); throw new RuntimeException('Failure hook did not run.'); }
        catch (RuntimeException $e) { prAssert($e->getMessage() === 'Injected write failure.', 'Unexpected injected failure.'); }
        $after = $db->query("SELECT password,token_version,password_changed_at FROM users WHERE id={$id}")->fetch();
        prAssert($before === $after && $probe->validatePasswordResetToken($reset['token']), 'Credential failure left partial reset state.');
    });
    $run('token-consumption failure rolls back password, version, and token deletion', function () use ($db, $create): void {
        [$id, $email] = $create('rollback-consume');
        $probe = new CredentialService($db, function ($point): void { if ($point === 'after_reset_token_consumption') throw new RuntimeException('Injected consumption failure.'); });
        $reset = $probe->createPasswordReset($email);
        $before = $db->query("SELECT password,token_version,password_changed_at FROM users WHERE id={$id}")->fetch();
        try { $probe->resetPassword(['token' => $reset['token'], 'new_password' => 'newPassword456']); throw new RuntimeException('Failure hook did not run.'); }
        catch (RuntimeException $e) { prAssert($e->getMessage() === 'Injected consumption failure.', 'Unexpected injected failure.'); }
        $after = $db->query("SELECT password,token_version,password_changed_at FROM users WHERE id={$id}")->fetch();
        prAssert($before === $after && $probe->validatePasswordResetToken($reset['token']), 'Consumption failure left partial reset state.');
    });
    $run('two concurrent uses of one token allow exactly one reset', function () use ($db, $service, $create): void {
        if (!function_exists('proc_open')) throw new RuntimeException('proc_open unavailable.');
        [$id, $email] = $create('concurrent');
        $reset = $service->createPasswordReset($email);
        $barrier = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sanie-reset-' . bin2hex(random_bytes(8)) . '.start';
        $worker = __DIR__ . DIRECTORY_SEPARATOR . 'PasswordResetConcurrencyWorker.php';
        $processes = [];
        foreach (['concurrentNewA456', 'concurrentNewB456'] as $password) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, $worker, base64_encode($reset['token']), base64_encode($password), $barrier], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) throw new RuntimeException('Worker start failed.');
            $processes[] = [$process, $pipes];
        }
        touch($barrier);
        $results = [];
        foreach ($processes as [$process, $pipes]) {
            $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
            $decoded = json_decode($out, true);
            if ($exit !== 0 || !is_array($decoded)) throw new RuntimeException("Worker failed: {$err} {$out}");
            $results[] = $decoded;
        }
        if (file_exists($barrier)) unlink($barrier);
        prAssert(count(array_filter($results, fn($result) => !empty($result['ok']))) === 1, 'Concurrent token was not single-use: ' . json_encode($results));
        prAssert((int)$db->query("SELECT token_version FROM users WHERE id={$id}")->fetchColumn() === 2, 'Concurrent reset advanced the session version more than once.');
    });
} finally {
    foreach (array_reverse($userIds) as $id) {
        try { $db->prepare('DELETE FROM users WHERE id=?')->execute([$id]); } catch (Throwable $ignored) {}
    }
}
echo "RESULT: {$passed} passed, {$failed} failed, 0 skipped\n";
exit($failed ? 1 : 0);
