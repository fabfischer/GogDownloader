# Development commands for this fork. Everything runs in Docker (docker/dev/); the host's PHP is never used.
# Arguments go into c="...", e.g. make composer c="update --lock", make test c="--filter S3", make cli c="games".

SHELL := /bin/bash
.DEFAULT_GOAL := help

# Containers write into the bind-mounted source with the host user's IDs.
export HOST_UID := $(shell id -u)
export HOST_GID := $(shell id -g)

DC  := docker compose -f docker/dev/compose.yaml
RUN := $(DC) run --rm
c   ?=

.PHONY: help
help: ## Show this help
	@awk 'BEGIN {FS = ":.*##"} /^[a-zA-Z0-9_-]+:.*##/ {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

.PHONY: build
build: ## Build the dev image
	$(DC) build

.PHONY: sh
sh: ## Open a shell in the dev container
	$(RUN) php bash

.PHONY: php
php: ## Run PHP: make php c="-v"
	$(RUN) php php $(c)

.PHONY: composer
composer: ## Run Composer: make composer c="install"
	$(RUN) php composer $(c)

.PHONY: cli
cli: ## Run the GogDownloader CLI from source: make cli c="games" (state in the cli-config volume)
	$(RUN) php php -d memory_limit=-1 bin/app.php $(c)

.PHONY: ci
ci: ## Run every check CI runs (Composer, style, static analysis, tests)
	$(RUN) php tools/ci.sh

.PHONY: test
test: ## Run PHPUnit, optionally filtered: make test c="--filter OwnedItems"
	$(RUN) php vendor/bin/phpunit $(c)

.PHONY: test-integration
test-integration: ## Start the test services (fake GOG source, S3, two Nextclouds), run the integration tests, stop them
	$(DC) --profile test up -d --wait fake-gog s3 nextcloud nextcloud-objectstore || { $(DC) --profile test logs --tail 40; $(DC) --profile test down --remove-orphans; exit 1; }
	$(RUN) php vendor/bin/phpunit --testsuite integration $(c); status=$$?; $(DC) --profile test down --remove-orphans; exit $$status

.PHONY: test-services
test-services: ## Start the test services and keep them running (stop with: make test-services-down)
	$(DC) --profile test up -d --wait fake-gog s3 nextcloud nextcloud-objectstore

.PHONY: test-services-down
test-services-down: ## Stop the test services and drop their data
	$(DC) --profile test down --remove-orphans

.PHONY: stan
stan: ## Run PHPStan
	$(RUN) php vendor/bin/phpstan analyse --memory-limit=1G $(c)

.PHONY: stan-baseline
stan-baseline: ## Regenerate the PHPStan baseline (only after upstream merges)
	$(RUN) php vendor/bin/phpstan analyse --memory-limit=1G --generate-baseline phpstan-baseline.neon

.PHONY: cs
cs: ## Fix code style of the code the fork adds (tests/)
	$(RUN) php vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.fork.php

.PHONY: cs-check
cs-check: ## Check code style without changing files
	$(RUN) php vendor/bin/php-cs-fixer check --config=.php-cs-fixer.fork.php --diff

.PHONY: clean
clean: ## Remove the dev containers, test services and the locally built image (keeps volumes)
	$(DC) --profile test down --remove-orphans --rmi local

.PHONY: purge
purge: ## Also delete the volumes (Composer cache, CLI login and downloads). Needs CONFIRM=yes
	@test "$(CONFIRM)" = "yes" || { echo "This deletes the CLI's GOG login and downloads. Run: make purge CONFIRM=yes"; exit 1; }
	$(DC) down --remove-orphans --rmi local --volumes
