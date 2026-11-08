# syntax=docker/dockerfile:1.7
#
# One Dockerfile, several targets:
#   development  PHP-FPM + Composer + test extensions; code is bind-mounted (docker-compose.yml)
#   production   PHP-FPM with the code, vendor/ and built assets baked in (docker-compose.prod.yml)
#   web          Nginx serving public/ and proxying PHP to the app container
#
ARG PHP_VERSION=8.3

# ── base: PHP-FPM with the extensions the application needs ───────────────────────────────
FROM php:${PHP_VERSION}-fpm-alpine AS base

# bcmath: all money arithmetic; pdo_mysql: MySQL 8; intl/zip: framework/Composer; pcntl: queue signals;
# opcache: performance; fcgi: php-fpm health check (cgi-fcgi).
RUN apk add --no-cache icu-libs libzip fcgi \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev linux-headers \
    && docker-php-ext-install -j"$(nproc)" bcmath intl opcache pcntl pdo_mysql zip \
    && apk del .build-deps

WORKDIR /var/www/html

COPY docker/php/conf.d/app.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-www.conf
COPY --chmod=755 docker/php/entrypoint.sh /usr/local/bin/app-entrypoint

ENTRYPOINT ["app-entrypoint"]
CMD ["php-fpm"]

# ── development: source is bind-mounted; runs as the host user so files stay editable ────
FROM base AS development

ARG UID=1000
ARG GID=1000

# gd + pdo_sqlite: the test suite (fake images, in-memory SQLite). git/unzip: Composer.
RUN apk add --no-cache git unzip shadow freetype libpng libjpeg-turbo libwebp sqlite-libs \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS freetype-dev libpng-dev libjpeg-turbo-dev libwebp-dev sqlite-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd pdo_sqlite \
    && apk del .build-deps \
    && groupmod -o -g "${GID}" www-data \
    && usermod -o -u "${UID}" -g www-data www-data

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/conf.d/development.ini /usr/local/etc/php/conf.d/zz-development.ini

USER www-data

# ── vendor: production Composer dependencies (cached until composer.lock changes) ─────────
FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

# ── assets: Vite production build (cached until package-lock.json or resources change) ────
FROM node:22-alpine AS assets

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY vite.config.js tsconfig.json ./
COPY resources ./resources
RUN npm run build

# ── production: code + vendor + built assets, running as an unprivileged user ────────────
FROM base AS production

ENV APP_ENV=production \
    APP_DEBUG=false

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /var/www/html/vendor ./vendor
COPY --from=assets --chown=www-data:www-data /app/public/build ./public/build

RUN composer dump-autoload --optimize --no-dev --no-interaction \
    && rm -f /usr/bin/composer \
    && mkdir -p storage/app/public storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && ln -sfn ../storage/app/public public/storage \
    && chown -R www-data:www-data storage bootstrap/cache public/storage

USER www-data

# ── web: Nginx with only the public files ─────────────────────────────────────────────────
FROM nginx:1.27-alpine AS web

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=production /var/www/html/public /var/www/html/public
