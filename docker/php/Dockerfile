FROM composer:2.8 AS composer

FROM php:8.4-cli-alpine AS vendor

WORKDIR /app
RUN apk add --no-cache git unzip \
    linux-headers \
    && docker-php-ext-install sockets
COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock* ./
RUN composer install --no-interaction --prefer-dist --no-progress --optimize-autoloader --no-dev

FROM php:8.4-cli-alpine

WORKDIR /app
RUN apk add --no-cache linux-headers \
    && docker-php-ext-install sockets
COPY --from=vendor /app/vendor /app/vendor
COPY . .

EXPOSE 8081

CMD ["php", "-S", "0.0.0.0:8081", "-t", "public"]
