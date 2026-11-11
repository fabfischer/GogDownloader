#!/usr/bin/env bash
# All quality checks, in the order CI runs them. Runs inside the dev container:
#   locally:         make ci   (docker compose -f docker/dev/compose.yaml run --rm php tools/ci.sh)
#   GitHub Actions:  .github/workflows/fork-tests.yml runs the same make target
# Expects dependencies to be installed (make composer c="install").
set -euo pipefail

cd "$(dirname "$0")/.."
mkdir -p var/cache

step() { printf '\n\033[1m==> %s\033[0m\n' "$1"; }

step 'Composer: manifest and lock file are valid and in sync'
composer validate --no-check-publish --no-interaction

step 'Composer: no known security advisories in the locked dependencies'
composer audit --locked --no-interaction

step 'Code style (code the fork adds, upstream rules): php-cs-fixer'
vendor/bin/php-cs-fixer check --config=.php-cs-fixer.fork.php --show-progress=none --diff

step 'Static analysis: PHPStan'
vendor/bin/phpstan analyse --no-progress --memory-limit=1G

step 'Tests: PHPUnit'
vendor/bin/phpunit

printf '\n\033[1;32mAll checks passed.\033[0m\n'
