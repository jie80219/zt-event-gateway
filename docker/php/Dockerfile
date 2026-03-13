FROM php:8.4-cli-alpine AS vendor

WORKDIR /app
COPY composer.json composer.lock* ./
RUN apk add --no-cache git unzip \
    && docker-php-ext-install sockets \
    && php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" \
    && php composer-setup.php --install-dir=/usr/local/bin --filename=composer \
    && rm composer-setup.php \
    && composer install --no-interaction --prefer-dist --no-progress --optimize-autoloader

FROM php:8.4-cli-alpine

WORKDIR /app
COPY --from=vendor /app/vendor /app/vendor
COPY . .

EXPOSE 8081

CMD ["php", "-S", "0.0.0.0:8081", "-t", "public"]
