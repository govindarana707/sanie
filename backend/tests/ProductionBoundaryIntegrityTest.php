<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$read = static function (string $relative) use ($root): string {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $contents = is_file($path) ? file_get_contents($path) : false;
    if ($contents === false) throw new RuntimeException("Missing production-boundary file: {$relative}");
    return $contents;
};

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$rootAccess = $read('.htaccess');
foreach (['.git', '.agents', 'tests?', 'docs?', 'backups?', 'storage', 'diagnostics', 'fixtures', 'vendor'] as $protected) {
    $assert(str_contains($rootAccess, $protected), "Root access rules do not protect {$protected}");
}
$assert(str_contains($rootAccess, 'composer\\.(?:json|lock)'), 'Composer metadata is not protected');
$assert(str_contains($rootAccess, 'Options -Indexes'), 'Root directory listing is not disabled');

$backendAccess = $read('backend/.htaccess');
$apiAccess = $read('backend/api/.htaccess');
$uploadAccess = $read('backend/uploads/.htaccess');
$assert(str_contains($backendAccess, 'Require all denied'), 'Backend is not private by default');
$assert(str_contains($apiAccess, 'Require all granted'), 'API does not override the backend deny boundary');
$assert(str_contains($uploadAccess, 'jpe?g|png|webp'), 'Public avatar types are not explicitly allowlisted');
$assert(str_contains($uploadAccess, 'Require all denied'), 'Uploads are not private by default');

foreach (['frontend/manifest.webmanifest', 'frontend/manifest.json', 'manifest.webmanifest'] as $manifestPath) {
    $manifest = json_decode($read($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    foreach (['id', 'start_url', 'scope'] as $field) {
        $assert(($manifest[$field] ?? null) === './', "{$manifestPath} {$field} is not deployment-relative");
    }
}

$controller = $read('frontend/assets/js/pwa-controller.js');
$assert(!str_contains($controller, "const APP_FRONTEND_PATH = '/sanie/frontend/'"), 'PWA controller retains the old fixed path');
$assert(str_contains($controller, "new URL('./', document.baseURI"), 'PWA controller does not resolve from its deployment location');

$releaseBuilder = $read('deployment/build-production-release.php');
foreach (["'/frontend'", "'/backend'", "'/vendor'"] as $runtimeArea) {
    $assert(str_contains($releaseBuilder, $runtimeArea), "Release builder is missing runtime area {$runtimeArea}");
}
$assert(!str_contains($releaseBuilder, "copyTree(\$sourceRoot . '/backend/tests'"), 'Release builder copies backend tests');
$assert(!str_contains($releaseBuilder, "copyFile(\$sourceRoot . '/.env'"), 'Release builder copies a local environment file');
$assert(str_contains($releaseBuilder, "['composer.json', 'composer.lock']"), 'Release builder does not exclude public Composer metadata from vendor');

$environment = $read('.env.production.example');
$assert(str_contains($environment, 'BASE_URL=https://sanie.govindarana.com.np/backend/api'), 'Production API URL template is incorrect');
$assert(str_contains($environment, 'FRONTEND_URL=https://sanie.govindarana.com.np'), 'Production frontend URL template is incorrect');
$assert(preg_match('/^JWT_SECRET=\s*$/m', $environment) === 1, 'Production JWT template must not contain a secret');
$assert(preg_match('/^DB_PASSWORD=\s*$/m', $environment) === 1, 'Production database template must not contain a password');
$assert(preg_match('/^MAIL_PASSWORD=\s*$/m', $environment) === 1, 'Production SMTP template must not contain a password');

echo "PASS: production root paths, public boundary, release exclusions, and secret-free environment template\n";
