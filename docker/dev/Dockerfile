# syntax=docker/dockerfile:1
#
# Development and CI image for the fork's checks (Composer, code style, PHPStan, PHPUnit) and for running the CLI
# from source. Not a release image: upstream's root Dockerfile builds that and stays unchanged.
#
# Same pinned PHP 8.4 base as the project that consumes this fork, so tests run on the PHP build it runs on.
ARG PHP_IMAGE=dunglas/frankenphp:1.13.4-php8.4-bookworm@sha256:81ba601edfb650d1df63f54dafdedc021d9d1fb37517850a4e93278ab19cced1
ARG COMPOSER_IMAGE=composer:2.11.2

FROM ${COMPOSER_IMAGE} AS composer

FROM ${PHP_IMAGE}

SHELL ["/bin/bash", "-o", "pipefail", "-c"]

COPY install-extensions.sh /usr/local/bin/fork-install-extensions
RUN fork-install-extensions

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
RUN cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
COPY php.ini "$PHP_INI_DIR/conf.d/90-fork-dev.ini"

# Containers run with the host user's UID (see Makefile), which has no home directory. The CLI's state
# directories are world-writable so named volumes inherit usable permissions for any UID.
ENV HOME=/tmp \
    COMPOSER_HOME=/composer \
    COMPOSER_CACHE_DIR=/composer/cache \
    CONFIG_DIRECTORY=/var/lib/gog-cli/config \
    DOWNLOAD_DIRECTORY=/var/lib/gog-cli/downloads
RUN mkdir -p /composer/cache "$CONFIG_DIRECTORY" "$DOWNLOAD_DIRECTORY" \
    && chmod -R 1777 /composer "$CONFIG_DIRECTORY" "$DOWNLOAD_DIRECTORY"

WORKDIR /app
