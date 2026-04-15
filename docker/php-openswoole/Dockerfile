FROM composer:2.8 AS vendor

WORKDIR /app
RUN apk add --no-cache linux-headers && docker-php-ext-install sockets pcntl
COPY composer.json ./
COPY packages/ packages/
RUN composer update --no-interaction --prefer-dist --no-progress --optimize-autoloader \
    --no-scripts --ignore-platform-req=ext-swoole --ignore-platform-req=ext-swow

FROM php:8.3-cli-bookworm

WORKDIR /app

# System deps
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip curl procps libssl-dev libcurl4-openssl-dev \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions
RUN docker-php-ext-install sockets pcntl

# OpenSwoole (with HTTP/2 + SSL + curl hooks)
RUN pecl install openswoole && docker-php-ext-enable openswoole

# Protobuf C extension
RUN pecl install protobuf && docker-php-ext-enable protobuf

# 不需要 ext-grpc — php-spiffe 使用純 PHP 的 OpenSwooleHttp2Transport
# 直連 SPIRE Agent，不依賴 \Grpc\BaseStub

# Copy vendor from build stage
COPY --from=vendor /app/vendor /app/vendor

# Copy application
COPY . .

EXPOSE 8080

CMD ["php", "bin/gateway.php"]
