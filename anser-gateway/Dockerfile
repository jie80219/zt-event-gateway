FROM webdevops/php:8.2-alpine

# php-event extension
RUN \
    apk add autoconf openssl-dev && apk add build-base && \
    apk add linux-headers && apk add libevent-dev && apk add openldap-dev && apk add imagemagick-dev && \
    docker-php-ext-install opcache && docker-php-ext-enable opcache && \
    pecl install xdebug && docker-php-ext-enable xdebug && \
    echo '' | pecl install event && \
    docker-php-ext-enable --ini-name zz-event.ini event && \
    docker-php-ext-enable redis || true

# SWOW extension — pin to a 1.x tag matching the composer swow/swow library
# (^1.2 → requires ext ^1.5.3). Unpinned master now ships 2.0.0-alpha.2 which
# fails the library's version check, so pin to the latest 1.x release.
RUN \
    git clone --depth 1 --branch v1.6.2 https://github.com/swow/swow.git && \
    cd swow/ext && \
    phpize && ./configure --enable-swow-ssl --enable-swow-curl && make && \
    make install && \
    docker-php-ext-enable swow

WORKDIR "/"

# 複製 supervisord 設定
COPY supervisord.conf /etc/supervisord.conf
COPY supervisord.recalc.conf /etc/supervisor/conf.d/recalc.conf
COPY supervisord.monitor.conf /etc/supervisor/conf.d/monitor.conf

# 預設以 supervisor 啟動
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]