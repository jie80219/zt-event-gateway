FROM php:8.4-cli-bookworm

# ── System dependencies ───────────────────────────────────────────
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip curl procps libssl-dev libprotobuf-dev protobuf-compiler \
    && rm -rf /var/lib/apt/lists/*

# ── PHP extensions ────────────────────────────────────────────────
RUN docker-php-ext-install sockets pcntl

# protobuf extension (C implementation for performance)
RUN pecl install protobuf && docker-php-ext-enable protobuf

# Swoole extension (required for coroutine gRPC client)
RUN pecl install swoole && docker-php-ext-enable swoole

# ── Composer ──────────────────────────────────────────────────────
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --no-interaction --prefer-dist --no-progress --optimize-autoloader || true

COPY . .

# ── Entrypoint ────────────────────────────────────────────────────
# Default: run the E2E test suite
CMD ["php", "spiffe/e2e/test-e2e.php"]
