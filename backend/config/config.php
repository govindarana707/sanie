<?php

// Load installation-specific settings from protected environment files.
// Values supplied by the web server or process always take precedence. Local
// overrides may replace shared .env defaults, but never explicit runtime values.
$runtimeEnvironment = getenv();
if (!is_array($runtimeEnvironment)) $runtimeEnvironment = [];
$loadEnvFile = static function ($envFile, $overrideExisting = false) use ($runtimeEnvironment) {
    if (!is_readable($envFile)) return;

    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$name, $value] = array_map('trim', explode('=', $line, 2));
        $existingValue = $name !== '' ? getenv($name) : false;
        $hasRuntimeValue = $name !== ''
            && array_key_exists($name, $runtimeEnvironment)
            && $runtimeEnvironment[$name] !== '';
        if ($name !== '' && !$hasRuntimeValue && ($overrideExisting || $existingValue === false || $existingValue === '')) {
            $value = trim($value, "\"'");
            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
        }
    }
};

$projectRoot = dirname(__DIR__, 2);
$loadEnvFile($projectRoot . '/.env');

$requestHost = strtolower(preg_replace('/:\\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
$isLocalRequest = in_array($requestHost, ['localhost', '127.0.0.1', '::1'], true);
if ($isLocalRequest) {
    $loadEnvFile($projectRoot . '/.env.local', true);
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
    if (array_key_exists($name, $_ENV) && $_ENV[$name] !== '') {
        return $_ENV[$name];
    }

    $value = getenv($name);
    return ($value === false || $value === '') ? $default : $value;
}

define('APP_ENV', env_value('APP_ENV', 'production'));
define('APP_DEBUG', filter_var(env_value('APP_DEBUG', '0'), FILTER_VALIDATE_BOOLEAN));
define('BASE_URL', env_value('BASE_URL', 'http://localhost/sanie/backend/api'));
$derivedFrontendUrl = preg_replace('#/backend/api/?$#i', '/frontend', BASE_URL);
define('FRONTEND_URL', rtrim(env_value('FRONTEND_URL', $derivedFrontendUrl), '/'));
define('PASSWORD_RESET_TTL', 3600);
define('PASSWORD_RESET_DEV_LOG', env_value(
    'PASSWORD_RESET_DEV_LOG',
    dirname(__DIR__) . '/storage/password-reset-deliveries.log'
));
define('MAIL_TRANSPORT', strtolower((string)env_value('MAIL_TRANSPORT', '')));
define('MAIL_HOST', (string)env_value('MAIL_HOST', ''));
define('MAIL_PORT', (int)env_value('MAIL_PORT', '587'));
define('MAIL_USERNAME', (string)env_value('MAIL_USERNAME', ''));
define('MAIL_PASSWORD', (string)env_value('MAIL_PASSWORD', ''));
define('MAIL_ENCRYPTION', strtolower((string)env_value('MAIL_ENCRYPTION', 'tls')));
define('MAIL_FROM_ADDRESS', (string)env_value('MAIL_FROM_ADDRESS', ''));
define('MAIL_FROM_NAME', (string)env_value('MAIL_FROM_NAME', 'SanIE'));
define('MAIL_TIMEOUT_SECONDS', (int)env_value('MAIL_TIMEOUT_SECONDS', '10'));

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
