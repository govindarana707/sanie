<?php
$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/../services/CredentialService.php';
[$script, $token, $password, $barrier] = $argv;
$deadline = microtime(true) + 10;
while (!file_exists($barrier) && microtime(true) < $deadline) usleep(10000);
try {
    (new CredentialService())->resetPassword([
        'token' => base64_decode($token, true),
        'new_password' => base64_decode($password, true),
        'new_password_confirmation' => base64_decode($password, true),
    ]);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'class' => get_class($e),
        'status' => $e instanceof CredentialValidationException ? $e->status : 500,
    ]);
}
