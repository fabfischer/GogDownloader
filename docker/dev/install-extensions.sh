#!/bin/sh
# PHP extensions for the fork's dev image. pcntl: the CLI's signal handling; zip: Composer dist installs.
# pdo_sqlite (CLI state), simplexml (checksum XML), zlib and curl ship with the base image and are only checked.
set -eu

install-php-extensions pcntl zip "$@"

php -r '
$required = ["pdo_sqlite", "simplexml", "zlib", "curl", "pcntl", "zip"];
$missing = array_filter($required, fn ($e) => !extension_loaded($e));
if ($missing) {
    fwrite(STDERR, "Missing PHP extensions: " . implode(", ", $missing) . PHP_EOL);
    exit(1);
}
'
