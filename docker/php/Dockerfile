FROM composer:2.8 AS vendor

WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --no-interaction --prefer-dist --no-progress --optimize-autoloader

FROM php:8.4-cli-alpine

WORKDIR /app
COPY --from=vendor /app/vendor /app/vendor
COPY . .

EXPOSE 8081

CMD ["php", "-S", "0.0.0.0:8081", "-t", "public"]
