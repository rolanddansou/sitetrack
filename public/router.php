<?php

// Router script for PHP's built-in dev server (php -S).
// Without it, requests for a path with an extension (e.g. /collect/event.js)
// that has no matching file on disk 404 directly instead of reaching index.php.
if (PHP_SAPI === 'cli-server') {
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/index.php';
    $path = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
    if ($path !== '/' && file_exists(__DIR__ . $path)) {
        return false;
    }
}

require __DIR__ . '/index.php';
