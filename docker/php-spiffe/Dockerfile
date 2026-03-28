FROM php:8.3-cli-bookworm

# ── System dependencies ───────────────────────────────────────────
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip curl procps libssl-dev libprotobuf-dev protobuf-compiler \
        autoconf g++ make \
    && rm -rf /var/lib/apt/lists/*

# ── PHP extensions ────────────────────────────────────────────────
RUN docker-php-ext-install sockets pcntl

# protobuf extension (C implementation for performance)
RUN pecl install protobuf && docker-php-ext-enable protobuf

# Swow extension (coroutine runtime for gRPC client)
RUN git clone --depth 1 https://github.com/swow/swow.git /tmp/swow \
    && cd /tmp/swow/ext \
    && phpize && ./configure --enable-swow-ssl --enable-swow-curl \
    && make -j"$(nproc)" && make install \
    && docker-php-ext-enable swow \
    && rm -rf /tmp/swow

# google/protobuf PHP library
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json composer.lock* ./
COPY packages/ packages/
RUN composer update --no-interaction --prefer-dist --no-progress \
    --no-scripts --ignore-platform-req=ext-swow || true

COPY . .

CMD ["php", "spiffe/e2e/test-e2e.php"]
