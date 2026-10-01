<?php

/**
 * The former global financial reset utility was intentionally retired.
 * Fresh Start is available only through the authenticated, user-scoped API.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

fwrite(STDERR, "The global reset utility is disabled. Use Settings > Danger Zone > Fresh Start for a user-scoped reset.\n");
exit(2);
