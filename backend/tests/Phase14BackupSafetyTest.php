<?php

function phase14Assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function phase14Ignored(string $projectRoot, string $path): bool {
    $pipes = [];
    $process = proc_open(
        ['git', 'check-ignore', '--no-index', '-q', $path],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $projectRoot
    );
    if (!is_resource($process)) throw new RuntimeException('Unable to verify Git ignore rules.');
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return proc_close($process) === 0;
}

function phase14HttpStatus(string $url): array {
    $context = stream_context_create(['http' => [
        'method' => 'GET',
        'ignore_errors' => true,
        'timeout' => 10,
    ]]);
    $body = @file_get_contents($url, false, $context);
    $headers = $http_response_header ?? [];
    preg_match('/\s(\d{3})\s/', $headers[0] ?? '', $match);
    return [(int)($match[1] ?? 0), $body === false ? '' : $body];
}

$projectRoot = dirname(__DIR__, 2);
$backupDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backups';
$probeName = 'sanie-db-phase14-http-probe.sql';
$probePath = $backupDirectory . DIRECTORY_SEPARATOR . $probeName;
$probeMarker = 'PHASE14_SYNTHETIC_BACKUP_MUST_NOT_BE_PUBLIC';
$failed = false;

try {
    phase14Assert(is_file($projectRoot . '/backend/database/schema.sql'), 'Canonical schema.sql is missing.');
    phase14Assert(is_file($projectRoot . '/backend/database/migration_accounts.sql'), 'Canonical migration SQL is missing.');
    phase14Assert(is_file($projectRoot . '/backend/database/fixtures/august-2026-baseline-schema.sql'), 'Sanitized baseline fixture is missing.');

    phase14Assert(phase14Ignored($projectRoot, 'backend/backups/sanie-db-20990101-010101.sql'), 'Generated SQL backup is not ignored.');
    phase14Assert(phase14Ignored($projectRoot, 'backend/backups/sanie_local_post_migration_2026-08-10.sql'), 'Legacy baseline dump is not ignored.');
    phase14Assert(phase14Ignored($projectRoot, 'backend/backups/export.dump'), 'Generated dump is not ignored.');
    phase14Assert(phase14Ignored($projectRoot, 'sanie.sql'), 'Legacy root dump is not protected from future staging.');
    phase14Assert(!phase14Ignored($projectRoot, 'backend/database/schema.sql'), 'Canonical schema.sql is incorrectly ignored.');
    phase14Assert(!phase14Ignored($projectRoot, 'backend/database/migration_accounts.sql'), 'Migration SQL is incorrectly ignored.');
    phase14Assert(!phase14Ignored($projectRoot, 'backend/database/fixtures/august-2026-baseline-schema.sql'), 'Sanitized baseline fixture is incorrectly ignored.');

    $rootHtaccess = file_get_contents($projectRoot . '/.htaccess');
    $backupHtaccess = file_get_contents($backupDirectory . '/.htaccess');
    foreach (['sql', 'dump', 'backup', 'bak', 'gz', 'zip', 'tar'] as $extension) {
        phase14Assert(str_contains($rootHtaccess, $extension), "Root HTTP deny rule does not cover .{$extension}.");
    }
    phase14Assert(str_contains($backupHtaccess, 'Require all denied'), 'Backup directory is not explicitly denied.');

    phase14Assert(file_put_contents($probePath, $probeMarker) !== false, 'Could not create the synthetic HTTP probe.');
    $baseUrl = rtrim(getenv('TEST_APP_BASE') ?: 'http://localhost/sanie', '/');
    [$status, $body] = phase14HttpStatus($baseUrl . '/backend/backups/' . rawurlencode($probeName));
    phase14Assert(in_array($status, [403, 404], true), "Synthetic backup returned HTTP {$status}; expected 403 or 404.");
    phase14Assert(!str_contains($body, $probeMarker), 'Synthetic backup contents were publicly returned.');

    echo "PASS: Phase 14 backup ignore policy, canonical SQL visibility, and HTTP denial verified\n";
} catch (Throwable $error) {
    $failed = true;
    fwrite(STDERR, "FAIL: {$error->getMessage()}\n");
} finally {
    if (is_file($probePath)) unlink($probePath);
}

exit($failed ? 1 : 0);
