# AegisCore PHP-FPM image.
#
# Adds the PHP extensions Laravel 12 + Horizon + Filament need on top of the
# upstream php-fpm image. Built from compose (`build: ./php`), tagged
# `aegiscore/php-fpm:<version>` for clarity. Bump the tag whenever this file
# changes.

FROM php:8.4-fpm-alpine

# Build-time deps (removed at the end) + runtime libs.
RUN set -eux; \
    apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        oniguruma-dev \
        libzip-dev \
        linux-headers \
    ; \
    apk add --no-cache \
        icu-libs \
        libpng \
        libjpeg-turbo \
        freetype \
        libzip \
        oniguruma \
        mariadb-connector-c \
        git \
        unzip \
    ; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        mysqli \
        intl \
        bcmath \
        gd \
        mbstring \
        opcache \
        pcntl \
        sockets \
        zip \
    ; \
    pecl install redis; \
    docker-php-ext-enable redis; \
    apk del .build-deps; \
    rm -rf /tmp/pear /tmp/* /var/cache/apk/*

# Composer for Laravel / Filament install.
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html
