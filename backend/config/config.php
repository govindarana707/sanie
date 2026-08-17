<?php

// Load installation-specific settings from the protected project .env file.
// Existing server environment variables always take precedence.
$envFile = dirname(__DIR__, 2) . '/.env';
if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$name, $value] = array_map('trim', explode('=', $line, 2));
        if ($name !== '' && getenv($name) === false) {
            $value = trim($value, "\"'");
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
        }
    }
}

if (!function_exists('getallheaders')) {
    function getallheaders() {
        $headers = [];

        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) === 'HTTP_') {
                $header = str_replace('_', '-', substr($name, 5));
                $header = implode('-', array_map('ucfirst', explode('-', strtolower($header))));
                $headers[$header] = $value;
            } elseif (in_array($name, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }
}

function env_value($name, $default = null) {
    $value = getenv($name);
    return ($value === false || $value === '') ? $default : $value;
}

define('APP_ENV', env_value('APP_ENV', 'production'));
define('APP_DEBUG', filter_var(env_value('APP_DEBUG', '0'), FILTER_VALIDATE_BOOLEAN));
define('BASE_URL', env_value('BASE_URL', 'http://localhost/sanie/backend/api'));

$jwtSecret = env_value('JWT_SECRET');
$requestHost = strtolower(preg_replace('/:\\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
$isLocalRequest = in_array($requestHost, ['localhost', '127.0.0.1', '::1'], true);
if (!$jwtSecret && $isLocalRequest) {
    // Development-only fallback: production hosts must always provide JWT_SECRET.
    $jwtSecret = hash('sha256', __DIR__ . php_uname('n'));
}
if (!$jwtSecret || strlen($jwtSecret) < 32) {
    throw new RuntimeException('JWT_SECRET must be configured with at least 32 characters.');
}
define('JWT_SECRET', $jwtSecret);
define('JWT_ALGORITHM', 'HS256');
define('JWT_EXPIRATION', 86400); // 24 hours

define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('MAX_FILE_SIZE', 5242880); // 5MB

$originList = array_filter(array_map('trim', explode(',', env_value(
    'ALLOWED_ORIGINS',
    'https://sanie.govindarana.com.np,http://localhost:5173,http://localhost:3000'
))));
define('ALLOWED_ORIGINS', array_values($originList));

// Error reporting
error_reporting(E_ALL);

// Disable error display on production
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', 1);

// Timezone
date_default_timezone_set('Asia/Kathmandu');
