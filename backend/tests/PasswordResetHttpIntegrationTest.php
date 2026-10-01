<?php
$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/jwt.php';
require_once __DIR__ . '/../includes/rate_limiter.php';

function prhAssert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function prhHttp(string $method, string $path, ?array $body = null, ?string $token = null): array {
    $base = rtrim(getenv('TEST_API_BASE') ?: 'http://localhost/sanie/backend/api', '/');
    $headers = "Content-Type: application/json\r\n" . ($token ? "Authorization: Bearer {$token}\r\n" : '');
    $options = ['method' => $method, 'header' => $headers, 'ignore_errors' => true, 'timeout' => 10];
    if ($body !== null) $options['content'] = json_encode($body);
    $raw = file_get_contents("{$base}/{$path}", false, stream_context_create(['http' => $options]));
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $match);
    return [(int)($match[1] ?? 0), json_decode($raw ?: 'null', true), $raw ?: ''];
}
function prhTokensForEmail(string $path, string $email): array {
    if (!is_file($path)) return [];
    $tokens = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $record = json_decode($line, true);
        if (($record['email'] ?? null) !== $email) continue;
        $query = parse_url((string)($record['reset_url'] ?? ''), PHP_URL_QUERY);
        parse_str((string)$query, $parameters);
        if (isset($parameters['reset_token'])) $tokens[] = (string)$parameters['reset_token'];
    }
    return $tokens;
}

$db = (new Database())->getConnection();
$ids = [];
$failed = false;
$deliveryPath = PASSWORD_RESET_DEV_LOG;
if (!preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#', $deliveryPath)) {
    $deliveryPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $deliveryPath);
}
try {
    prhAssert(APP_ENV === 'development', 'HTTP delivery test requires APP_ENV=development.');
    $email = 'password-reset-http-' . bin2hex(random_bytes(5)) . '@example.invalid';
    $foreignEmail = 'password-reset-http-foreign-' . bin2hex(random_bytes(5)) . '@example.invalid';
    $unknownEmail = 'password-reset-http-missing-' . bin2hex(random_bytes(5)) . '@example.invalid';
    foreach ([[$email, 'oldPassword123'], [$foreignEmail, 'foreignPass123']] as [$address, $password]) {
        $stmt = $db->prepare("INSERT INTO users(email,password,first_name) VALUES(?,?,'Password Reset HTTP')");
        $stmt->execute([$address, password_hash($password, PASSWORD_DEFAULT)]);
        $ids[] = (int)$db->lastInsertId();
    }
    [$userId, $foreignId] = $ids;
    $oldSession = JWT::encode(['user_id' => $userId]);
    $foreignSession = JWT::encode(['user_id' => $foreignId]);
    foreach (['127.0.0.1', '::1', 'unknown'] as $ip) {
        foreach ([$email, $unknownEmail] as $address) RateLimiter::clear('password-reset-request:' . $ip . ':' . hash('sha256', $address));
        RateLimiter::clear('password-reset:' . $ip);
    }

    [$unknownStatus, $unknownBody] = prhHttp('POST', 'auth/forgot-password', ['email' => $unknownEmail]);
    [$knownStatus, $knownBody, $knownRaw] = prhHttp('POST', 'auth/forgot-password', ['email' => $email]);
    prhAssert($knownStatus === 200 && $unknownStatus === 200, 'Forgot-password request did not return neutral success.');
    prhAssert($knownBody === $unknownBody, 'Known and unknown accounts received distinguishable public responses.');
    prhAssert(!str_contains($knownRaw, $email) && !preg_match('/[a-f0-9]{64}/i', $knownRaw), 'Forgot-password response exposed account or token data.');
    $tokens = prhTokensForEmail($deliveryPath, $email);
    prhAssert(count($tokens) >= 1, 'Development delivery log did not receive the reset link.');
    $firstToken = end($tokens);

    [$repeatStatus] = prhHttp('POST', 'auth/forgot-password', ['email' => $email]);
    prhAssert($repeatStatus === 200, 'Repeated reset request failed.');
    $tokens = prhTokensForEmail($deliveryPath, $email);
    $secondToken = end($tokens);
    prhAssert($secondToken !== $firstToken, 'Repeated request did not rotate the reset token.');
    [$oldTokenStatus] = prhHttp('POST', 'auth/reset-password/validate', ['token' => $firstToken]);
    [$validStatus, $validBody] = prhHttp('POST', 'auth/reset-password/validate', ['token' => $secondToken]);
    prhAssert($oldTokenStatus === 422 && $validStatus === 200 && ($validBody['data']['valid'] ?? false), 'Replaced/current token validation is incorrect.');

    [$weakStatus] = prhHttp('POST', 'auth/reset-password', ['token' => $secondToken, 'new_password' => 'short', 'new_password_confirmation' => 'short']);
    [$mismatchStatus] = prhHttp('POST', 'auth/reset-password', ['token' => $secondToken, 'new_password' => 'newPassword456', 'new_password_confirmation' => 'differentPass789']);
    prhAssert($weakStatus === 422 && $mismatchStatus === 422, 'HTTP reset bypassed password policy or confirmation.');
    [$resetStatus, $resetBody, $resetRaw] = prhHttp('POST', 'auth/reset-password', ['token' => $secondToken, 'new_password' => 'newPassword456', 'new_password_confirmation' => 'newPassword456']);
    prhAssert($resetStatus === 200 && ($resetBody['success'] ?? false), 'Valid HTTP password reset failed.');
    prhAssert(!str_contains($resetRaw, $secondToken) && !str_contains($resetRaw, 'newPassword456'), 'Reset response exposed credentials.');
    [$usedStatus] = prhHttp('POST', 'auth/reset-password', ['token' => $secondToken, 'new_password' => 'anotherPass789']);
    prhAssert($usedStatus === 422, 'Consumed token was accepted over HTTP.');

    [$oldSessionStatus] = prhHttp('GET', 'auth/me', null, $oldSession);
    [$foreignSessionStatus] = prhHttp('GET', 'auth/me', null, $foreignSession);
    [$oldLoginStatus] = prhHttp('POST', 'auth/login', ['email' => $email, 'password' => 'oldPassword123']);
    [$newLoginStatus, $newLogin] = prhHttp('POST', 'auth/login', ['email' => $email, 'password' => 'newPassword456']);
    prhAssert($oldSessionStatus === 401 && $foreignSessionStatus === 200, 'HTTP reset revoked the wrong session scope.');
    prhAssert($oldLoginStatus === 401 && $newLoginStatus === 200 && isset($newLogin['data']['token']), 'HTTP login credentials are incorrect after reset.');
    echo "PASS: forgot-password, delivery, validation, reset, session revocation, and post-reset login work over HTTP\n";
} catch (Throwable $e) {
    $failed = true;
    fwrite(STDERR, "FAIL: {$e->getMessage()}\n");
} finally {
    foreach (['127.0.0.1', '::1', 'unknown'] as $ip) {
        if (isset($email, $unknownEmail)) foreach ([$email, $unknownEmail] as $address) RateLimiter::clear('password-reset-request:' . $ip . ':' . hash('sha256', $address));
        RateLimiter::clear('password-reset:' . $ip);
    }
    foreach (array_reverse($ids) as $id) {
        try { $db->prepare('DELETE FROM users WHERE id=?')->execute([$id]); } catch (Throwable $ignored) {}
    }
}
exit($failed ? 1 : 0);
