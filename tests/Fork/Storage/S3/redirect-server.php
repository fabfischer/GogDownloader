<?php

declare(strict_types=1);

// Router for PHP's built-in web server (php -S 127.0.0.1:PORT redirect-server.php) for S3RedirectTest: an endpoint that
// answers every request with a 302 to /canary/…, the stand-in for an internal address. Requests that reach /canary/ are
// appended to the file in REDIRECT_CANARY_LOG; an empty log means the client followed no redirect.

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
if (str_starts_with($path, '/canary/')) {
    file_put_contents((string) getenv('REDIRECT_CANARY_LOG'), ($_SERVER['REQUEST_METHOD'] ?? '?') . ' ' . ($_SERVER['REQUEST_URI'] ?? '') . "\n", FILE_APPEND);
    echo 'internal';

    return;
}

http_response_code(302);
header(sprintf('Location: http://%s/canary%s', $_SERVER['HTTP_HOST'] ?? '127.0.0.1', $_SERVER['REQUEST_URI'] ?? '/'));
