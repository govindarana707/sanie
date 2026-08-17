<?php

putenv('JWT_SECRET=phase8-test-secret-that-is-at-least-thirty-two-characters');
require_once __DIR__ . '/../includes/jwt.php';

$token = JWT::encode(['user_id' => 42]);
$payload = JWT::decode($token);
if ((int)($payload['user_id'] ?? 0) !== 42) {
    fwrite(STDERR, "FAIL: valid JWT was not verified\n");
    exit(1);
}

$parts = explode('.', $token);
$parts[2] = strrev($parts[2]);
if (JWT::decode(implode('.', $parts)) !== false) {
    fwrite(STDERR, "FAIL: tampered JWT signature was accepted\n");
    exit(1);
}

if (JWT::decode('not.a.valid-token') !== false) {
    fwrite(STDERR, "FAIL: malformed JWT was accepted\n");
    exit(1);
}

fwrite(STDOUT, "PASS: valid JWT accepted; tampered and malformed JWTs rejected\n");
