<?php

/*
 * Code style for code the fork adds (tests/), with exactly upstream's rules.
 *
 * Upstream's .php-cs-fixer.dist.php stays unchanged and still covers src/. Upstream's own src/ does not pass
 * those rules (32 of 105 files at v1.15.1), and reformatting it would make upstream merges painful, so CI only
 * checks the directories listed here. Run in Docker: make cs-check / make cs.
 */

$config = require __DIR__ . '/.php-cs-fixer.dist.php';
assert($config instanceof PhpCsFixer\Config);

return $config
    ->setFinder((new PhpCsFixer\Finder())->in([__DIR__ . '/tests']))
    ->setRiskyAllowed(true)
    ->setCacheFile(__DIR__ . '/var/cache/php-cs-fixer.fork.cache');
