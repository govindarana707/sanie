<?php

require_once __DIR__ . '/SchemaMigrator.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
try {
    $result = (new SchemaMigrator())->run();
    echo ($result['applied'] ? 'APPLIED: ' : 'CURRENT: ') . $result['migration_id'] . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'MIGRATION FAILED: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
