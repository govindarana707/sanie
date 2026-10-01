<?php

$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../services/CredentialService.php';
require_once __DIR__ . '/../services/PasswordResetDeliveryService.php';
require_once __DIR__ . '/../includes/jwt.php';

final class P17FakeResetTransport implements PasswordResetMailTransportInterface {
    public array $messages = [];
    public bool $fail = false;
    public function send(string $recipient, string $subject, string $htmlBody, string $textBody): void {
        if ($this->fail) throw new RuntimeException('Injected SMTP failure including sensitive body: ' . $textBody);
        $this->messages[] = compact('recipient', 'subject', 'htmlBody', 'textBody');
    }
}

function p17Assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }

$db = (new Database())->getConnection();
$users = [];
$passed = 0;
$failed = 0;
$tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sanie-p17-' . bin2hex(random_bytes(6));
$devLog = $tempRoot . DIRECTORY_SEPARATOR . 'dev-reset.log';
$productionLog = $tempRoot . DIRECTORY_SEPARATOR . 'production-errors.log';
mkdir($tempRoot, 0700, true);
$oldErrorLog = ini_get('error_log');
$run = function(string $name, callable $test) use (&$passed, &$failed): void {
    try { $test(); $passed++; echo "PASS: {$name}\n"; }
    catch (Throwable $e) { $failed++; fwrite(STDERR, "FAIL: {$name} - {$e->getMessage()}\n"); }
};
$create = function(string $label, string $password = 'oldPassword123') use ($db, &$users): array {
    $email = 'phase17-' . $label . '-' . bin2hex(random_bytes(5)) . '@example.invalid';
    $db->prepare("INSERT INTO users(email,password,first_name) VALUES(?,?, 'Phase 17')")
        ->execute([$email, password_hash($password, PASSWORD_DEFAULT)]);
    $id = (int)$db->lastInsertId();
    $users[] = $id;
    return [$id, $email];
};
$config = [
    'transport' => 'smtp', 'host' => 'smtp.example.test', 'port' => 587,
    'username' => 'smtp-user', 'password' => 'smtp-test-secret', 'encryption' => 'tls',
    'from_address' => 'no-reply@example.test', 'from_name' => 'SanIE', 'timeout' => 10,
    'frontend_url' => 'https://finance.example.test/sanie/frontend', 'dev_log' => $devLog,
];

try {
    $credentials = new CredentialService($db);

    $run('development writes protected reset link and never invokes production transport', function() use ($devLog, $config): void {
        $fake = new P17FakeResetTransport();
        $token = bin2hex(random_bytes(CredentialService::RESET_TOKEN_BYTES));
        $service = new PasswordResetDeliveryService($fake, 'development', $config);
        p17Assert($service->deliverPasswordReset('developer@example.test', $token, new DateTimeImmutable('+1 hour')), 'development delivery failed');
        p17Assert(count($fake->messages) === 0, 'development invoked production mail transport');
        $log = file_get_contents($devLog);
        p17Assert(str_contains($log, $token) && str_contains($log, 'https://finance.example.test/sanie/frontend?reset_token='), 'development log omitted usable reset link');
    });

    $run('production fake transport receives safe HTML and plain-text reset instructions', function() use ($credentials, $create, $config): void {
        [, $email] = $create('success');
        $reset = $credentials->createPasswordReset(strtoupper($email));
        $fake = new P17FakeResetTransport();
        $service = new PasswordResetDeliveryService($fake, 'production', $config);
        p17Assert($service->deliverPasswordReset($reset['email'], $reset['token'], $reset['expires_at']), 'production delivery failed');
        p17Assert(count($fake->messages) === 1 && $fake->messages[0]['recipient'] === $email, 'production recipient or email normalization is wrong');
        $message = $fake->messages[0];
        foreach ([$message['htmlBody'], $message['textBody']] as $body) {
            p17Assert(str_contains($body, $reset['token']) && str_contains($body, 'SanIE') && str_contains($body, 'expires') && str_contains($body, 'ignore'), 'production reset content is incomplete');
        }
        p17Assert(str_contains($message['htmlBody'], 'https://finance.example.test/sanie/frontend?reset_token='), 'configured subdirectory reset URL is wrong');
    });

    $run('unknown account creates no token and triggers no delivery', function() use ($credentials, $config): void {
        $fake = new P17FakeResetTransport();
        $reset = $credentials->createPasswordReset('phase17-missing-' . bin2hex(random_bytes(5)) . '@example.invalid');
        if ($reset) (new PasswordResetDeliveryService($fake, 'production', $config))->deliverPasswordReset($reset['email'], $reset['token'], $reset['expires_at']);
        p17Assert($reset === null && count($fake->messages) === 0, 'unknown account created or delivered a reset');
        $controller = file_get_contents(__DIR__ . '/../controllers/AuthController.php');
        p17Assert(str_contains($controller, "Response::success(null, \$message)") && !str_contains($controller, "Response::success(\$reset"), 'public forgot-password response can expose reset state');
    });

    $run('failed production delivery invalidates only its token and a later request recovers', function() use ($db, $credentials, $create, $config): void {
        [$id, $email] = $create('failure');
        $before = $db->query("SELECT password,token_version FROM users WHERE id={$id}")->fetch(PDO::FETCH_ASSOC);
        $reset = $credentials->createPasswordReset($email);
        $failing = new P17FakeResetTransport(); $failing->fail = true;
        $delivered = (new PasswordResetDeliveryService($failing, 'production', $config))->deliverPasswordReset($reset['email'], $reset['token'], $reset['expires_at']);
        p17Assert(!$delivered && $credentials->invalidatePasswordResetToken($reset['token']), 'failed delivery token was not invalidated');
        p17Assert(!$credentials->validatePasswordResetToken($reset['token']), 'undelivered token remains usable');
        $after = $db->query("SELECT password,token_version FROM users WHERE id={$id}")->fetch(PDO::FETCH_ASSOC);
        p17Assert($before === $after, 'delivery failure mutated credentials or sessions');
        $next = $credentials->createPasswordReset($email); $working = new P17FakeResetTransport();
        p17Assert((new PasswordResetDeliveryService($working, 'production', $config))->deliverPasswordReset($next['email'], $next['token'], $next['expires_at']), 'subsequent legitimate request did not recover');
    });

    $run('missing production configuration fails safely without development fallback', function() use ($devLog): void {
        $before = file_get_contents($devLog);
        $token = bin2hex(random_bytes(CredentialService::RESET_TOKEN_BYTES));
        $service = new PasswordResetDeliveryService(null, 'production', [
            'frontend_url' => 'https://finance.example.test/frontend', 'dev_log' => $devLog,
        ]);
        p17Assert(!$service->deliverPasswordReset('known@example.test', $token, new DateTimeImmutable('+1 hour')), 'missing production mail configuration was accepted');
        p17Assert(file_get_contents($devLog) === $before, 'production fell back to development reset log');
    });

    $run('expired and replaced production-delivered tokens retain Phase 11 semantics', function() use ($db, $credentials, $create, $config): void {
        [$id, $email] = $create('lifecycle');
        $fake = new P17FakeResetTransport(); $delivery = new PasswordResetDeliveryService($fake, 'production', $config);
        $expired = $credentials->createPasswordReset($email); $delivery->deliverPasswordReset($email, $expired['token'], $expired['expires_at']);
        $db->prepare('UPDATE password_reset_tokens SET expires_at=DATE_SUB(CURRENT_TIMESTAMP,INTERVAL 1 SECOND) WHERE user_id=?')->execute([$id]);
        p17Assert(!$credentials->validatePasswordResetToken($expired['token']), 'expired delivered token validated');
        $first = $credentials->createPasswordReset($email); $delivery->deliverPasswordReset($email, $first['token'], $first['expires_at']);
        $second = $credentials->createPasswordReset($email); $delivery->deliverPasswordReset($email, $second['token'], $second['expires_at']);
        p17Assert(!$credentials->validatePasswordResetToken($first['token']) && $credentials->validatePasswordResetToken($second['token']), 'latest delivered token did not replace the older token');
    });

    $run('production-delivered token is single-use and revokes only owner sessions', function() use ($credentials, $create, $config): void {
        [$id, $email] = $create('session');
        [$foreignId] = $create('unrelated', 'foreignPass123');
        $oldSession = JWT::encode(['user_id' => $id]); $foreignSession = JWT::encode(['user_id' => $foreignId]);
        $reset = $credentials->createPasswordReset($email); $fake = new P17FakeResetTransport();
        p17Assert((new PasswordResetDeliveryService($fake, 'production', $config))->deliverPasswordReset($email, $reset['token'], $reset['expires_at']), 'session reset delivery failed');
        $credentials->resetPassword(['token' => $reset['token'], 'new_password' => 'newPassword456', 'new_password_confirmation' => 'newPassword456']);
        $model = new User();
        p17Assert($model->login($email, 'oldPassword123') === false && (int)$model->login($email, 'newPassword456')['id'] === $id, 'password transition is wrong');
        p17Assert(!$credentials->validatePasswordResetToken($reset['token']), 'used token remains valid');
        try { $credentials->resetPassword(['token' => $reset['token'], 'new_password' => 'anotherPass789']); throw new RuntimeException('used token reset twice'); }
        catch (CredentialValidationException $expected) {}
        p17Assert(JWT::getUserIdFromToken($oldSession) === false && JWT::getUserIdFromToken($foreignSession) === $foreignId, 'session invalidation scope changed');
    });

    $run('production diagnostics contain no token, URL, password, or SMTP secret', function() use ($productionLog, $config): void {
        ini_set('error_log', $productionLog);
        $token = bin2hex(random_bytes(CredentialService::RESET_TOKEN_BYTES));
        $success = new P17FakeResetTransport();
        (new PasswordResetDeliveryService($success, 'production', $config))->deliverPasswordReset('safe@example.test', $token, new DateTimeImmutable('+1 hour'));
        $failure = new P17FakeResetTransport(); $failure->fail = true;
        (new PasswordResetDeliveryService($failure, 'production', $config))->deliverPasswordReset('safe@example.test', $token, new DateTimeImmutable('+1 hour'));
        (new PasswordResetDeliveryService(null, 'production', ['frontend_url' => $config['frontend_url']]))->deliverPasswordReset('safe@example.test', $token, new DateTimeImmutable('+1 hour'));
        $log = is_file($productionLog) ? file_get_contents($productionLog) : '';
        p17Assert(!str_contains($log, $token) && !str_contains($log, 'reset_token=') && !str_contains($log, $config['password']) && !str_contains($log, 'Injected SMTP failure'), 'production log exposed a reset or mail secret');
        p17Assert(substr_count($log, 'Password reset delivery failed') === 1 && substr_count($log, 'Password reset delivery configuration error') === 1, 'production failures were not safely diagnosable');
    });

    $run('SMTP configuration validates TLS, addresses, paired credentials, and bounded timeout', function() use ($config): void {
        new PhpMailerSmtpPasswordResetTransport($config);
        foreach ([
            array_merge($config, ['encryption' => 'none']),
            array_merge($config, ['from_address' => "safe@example.test\r\nBcc: attacker@example.test"]),
            array_merge($config, ['password' => '']),
            array_merge($config, ['timeout' => 120]),
        ] as $invalid) {
            try { new PhpMailerSmtpPasswordResetTransport($invalid); throw new RuntimeException('invalid SMTP configuration accepted'); }
            catch (RuntimeException $expected) { p17Assert($expected->getMessage() !== 'invalid SMTP configuration accepted', $expected->getMessage()); }
        }
    });
} finally {
    ini_set('error_log', $oldErrorLog ?: '');
    foreach (array_reverse($users) as $id) {
        try { $db->prepare('DELETE FROM users WHERE id=?')->execute([$id]); } catch (Throwable $ignored) {}
    }
    foreach ([$devLog, $productionLog] as $file) if (is_file($file)) unlink($file);
    if (is_dir($tempRoot)) rmdir($tempRoot);
}

echo "RESULT: {$passed} passed, {$failed} failed, 0 skipped\n";
exit($failed ? 1 : 0);
