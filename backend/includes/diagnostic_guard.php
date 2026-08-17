<?php

// Diagnostic and maintenance scripts must never be reachable over HTTP.
if (PHP_SAPI !== 'cli' || getenv('ALLOW_DIAGNOSTICS') !== '1') {
    if (PHP_SAPI !== 'cli') {
        http_response_code(404);
    }
    exit;
}
