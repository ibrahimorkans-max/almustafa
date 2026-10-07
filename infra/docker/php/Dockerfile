# PHP 8.3 FPM with the extensions the ERP needs (PostgreSQL + MySQL drivers, intl, bcmath, redis).
FROM php:8.3-fpm-alpine

RUN apk add --no-cache icu-dev libzip-dev postgresql-dev libpng-dev oniguruma-dev $PHPIZE_DEPS \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql pdo_mysql intl zip bcmath gd opcache mbstring exif \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY infra/docker/php/php.ini /usr/local/etc/php/conf.d/zz-ricemill.ini

WORKDIR /var/www/app
USER www-data
