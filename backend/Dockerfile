# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Base: PHP-FPM with the extensions Laravel and this app need
# ---------------------------------------------------------------------------
FROM php:8.4-fpm-alpine AS base

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql bcmath intl opcache zip

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-agrosync.ini

WORKDIR /var/www/html

# ---------------------------------------------------------------------------
# Dependencies: install Composer packages in a cacheable layer
# ---------------------------------------------------------------------------
FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader

COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && php artisan package:discover --ansi

# ---------------------------------------------------------------------------
# App: the PHP-FPM runtime image
# ---------------------------------------------------------------------------
FROM base AS app

COPY --from=vendor --chown=www-data:www-data /var/www/html /var/www/html
COPY --chmod=0755 docker/entrypoint.sh /usr/local/bin/agrosync-entrypoint
# Guard against CRLF line endings from a Windows checkout.
RUN sed -i 's/\r$//' /usr/local/bin/agrosync-entrypoint

ENTRYPOINT ["agrosync-entrypoint"]
CMD ["php-fpm"]

# ---------------------------------------------------------------------------
# Web: nginx serving public/ and proxying PHP to the app container
# ---------------------------------------------------------------------------
FROM nginx:1.27-alpine AS web

COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=vendor /var/www/html/public /var/www/html/public
