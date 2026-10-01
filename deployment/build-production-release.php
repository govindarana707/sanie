<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$sourceRoot = realpath(dirname(__DIR__));
$targetArgument = $argv[1] ?? '';

if ($sourceRoot === false || trim($targetArgument) === '') {
    fwrite(STDERR, "Usage: php deployment/build-production-release.php <new-target-directory>\n");
    exit(1);
}

$isAbsolute = preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#', $targetArgument) === 1;
$target = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $isAbsolute
    ? $targetArgument
    : getcwd() . DIRECTORY_SEPARATOR . $targetArgument);
$target = rtrim($target, DIRECTORY_SEPARATOR);

$normalize = static function (string $path): string {
    $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    return strtolower(rtrim($path, DIRECTORY_SEPARATOR));
};

$sourceNormalized = $normalize($sourceRoot);
$targetNormalized = $normalize($target);
if ($targetNormalized === $sourceNormalized
    || str_starts_with($targetNormalized . DIRECTORY_SEPARATOR, $sourceNormalized . DIRECTORY_SEPARATOR)
    || str_starts_with($sourceNormalized . DIRECTORY_SEPARATOR, $targetNormalized . DIRECTORY_SEPARATOR)) {
    fwrite(STDERR, "Target must be a new directory outside the source project.\n");
    exit(1);
}

if (file_exists($target)) {
    fwrite(STDERR, "Target already exists; refusing to overwrite it.\n");
    exit(1);
}

$copyFile = static function (string $source, string $destination): void {
    $directory = dirname($destination);
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException("Unable to create release directory: {$directory}");
    }
    if (!copy($source, $destination)) {
        throw new RuntimeException("Unable to copy release file: {$source}");
    }
};

$copyTree = static function (string $source, string $destination, array $excludedNames = []) use (&$copyTree, $copyFile): void {
    if (!is_dir($destination) && !mkdir($destination, 0755, true) && !is_dir($destination)) {
        throw new RuntimeException("Unable to create release directory: {$destination}");
    }
    foreach (new DirectoryIterator($source) as $item) {
        if ($item->isDot() || in_array($item->getFilename(), $excludedNames, true)) continue;
        $targetPath = $destination . DIRECTORY_SEPARATOR . $item->getFilename();
        if ($item->isDir()) $copyTree($item->getPathname(), $targetPath, $excludedNames);
        elseif ($item->isFile()) $copyFile($item->getPathname(), $targetPath);
    }
};

$removeTree = static function (string $directory) use (&$removeTree): void {
    if (!is_dir($directory)) return;
    foreach (new DirectoryIterator($directory) as $item) {
        if ($item->isDot()) continue;
        if ($item->isDir() && !$item->isLink()) $removeTree($item->getPathname());
        else @unlink($item->getPathname());
    }
    @rmdir($directory);
};

try {
    if (!mkdir($target, 0755, true) && !is_dir($target)) {
        throw new RuntimeException('Unable to create release target.');
    }

    // Serve the frontend directly from the production document root.
    $copyTree($sourceRoot . '/frontend', $target, ['.htaccess', 'README.md', 'tests', 'manifest.json']);
    $copyFile($sourceRoot . '/.htaccess', $target . '/.htaccess');

    // Copy only runtime backend areas. Database tooling, diagnostics, tests,
    // backups, logs, reset utilities, and documentation are intentionally absent.
    $backendTarget = $target . '/backend';
    if (!mkdir($backendTarget, 0755, true) && !is_dir($backendTarget)) {
        throw new RuntimeException('Unable to create backend release directory.');
    }
    $copyFile($sourceRoot . '/backend/.htaccess', $backendTarget . '/.htaccess');
    foreach (['api', 'config', 'controllers', 'data', 'includes', 'models', 'services', 'uploads'] as $area) {
        $copyTree($sourceRoot . '/backend/' . $area, $backendTarget . '/' . $area);
    }

    // PHPMailer and Composer runtime autoloading are required internally. Root
    // access rules deny all HTTP requests into vendor.
    $copyTree($sourceRoot . '/vendor', $target . '/vendor', ['composer.json', 'composer.lock']);

    fwrite(STDOUT, "Production release created: {$target}\n");
    fwrite(STDOUT, "Create the production .env separately; no local environment or secrets were copied.\n");
} catch (Throwable $error) {
    $removeTree($target);
    fwrite(STDERR, "Release creation failed: {$error->getMessage()}\n");
    exit(1);
}
