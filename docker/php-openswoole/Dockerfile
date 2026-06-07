# ─────────────────────────────────────────────────────────────────────
# Multi-stage build used by zt-gateway, zt-php-worker, and
# zt-spiffe-watcher.
#
# The runtime stage explicitly strips the gcc/g++/make/autoconf chain
# that `pecl install` (openswoole, protobuf) pulls in transparently.
# This closes the Paper-5 L1 attack vector (LD_PRELOAD-style library
# hijack via in-container compilation) flagged on zt-spiffe-watcher.
# ─────────────────────────────────────────────────────────────────────

# ── Stage 1: composer vendor ────────────────────────────────────────
FROM composer:2.8 AS vendor

WORKDIR /app
RUN apk add --no-cache linux-headers && docker-php-ext-install sockets pcntl
COPY composer.json ./
RUN composer update --no-interaction --prefer-dist --no-progress --optimize-autoloader \
    --no-scripts --ignore-platform-req=ext-swoole --ignore-platform-req=ext-swow

# ── Stage 2: runtime ────────────────────────────────────────────────
# Single-stage runtime with explicit toolchain strip. We keep the
# build → strip steps in ONE RUN so the compilers never persist in
# any image layer; multi-stage with a separate extension builder is
# the canonical alternative but copying /usr/local/lib/php/extensions
# across stages is fragile when the base image floats versions.
FROM php:8.3-cli-bookworm

WORKDIR /app

RUN set -eux \
    # ── runtime libs only — no compilers ─────────────────────────
    && apt-get update \
    && apt-get install -y --no-install-recommends \
         git unzip curl procps libssl-dev libcurl4-openssl-dev \
    # Build + enable PHP extensions. `pecl install` quietly pulls in
    # gcc / g++ / make / autoconf via PHPIZE_DEPS; we strip them after.
    && docker-php-ext-install sockets pcntl \
    && pecl install openswoole && docker-php-ext-enable openswoole \
    && pecl install protobuf && docker-php-ext-enable protobuf \
    # ── Strip the build toolchain to close the LD_PRELOAD / fakelib
    # ──   attack vector (Paper-5 L1 finding on zt-spiffe-watcher).
    && apt-get purge -y --auto-remove \
         gcc g++ cpp make autoconf m4 binutils \
         gcc-12 g++-12 cpp-12 binutils-common binutils-x86-64-linux-gnu \
         libgcc-12-dev libstdc++-12-dev pkg-config re2c \
         dpkg-dev libdpkg-perl \
    && apt-get autoremove -y --purge \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/cache/apt/* /root/.pearrc

# Copy vendor from composer stage
COPY --from=vendor /app/vendor /app/vendor

# Copy application
COPY . .

# Multi-process worker entrypoint. Forks WORKER_PROCESSES copies of
# `php bin/worker.php` under one stable container name, sidestepping
# compose `--scale`'s container-renaming (which would break every script
# that hard-codes `zt-php-worker`).
COPY docker/php-openswoole/zt-worker-entrypoint.sh /usr/local/bin/zt-worker-entrypoint.sh
RUN chmod +x /usr/local/bin/zt-worker-entrypoint.sh

EXPOSE 8080

CMD ["php", "bin/gateway.php"]
