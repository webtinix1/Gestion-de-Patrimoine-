# syntax=docker/dockerfile:1

# Image de l'application : PHP 8.4-FPM et Nginx (Alpine), assets compilés, sans secret.
# La configuration (clé, base…) arrive par les variables d'environnement : voir docker/compose.yaml.
# Construction (poste connecté) : docker compose -f docker/compose.yaml build

# 1. Dépendances PHP (sans les outils de développement).
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress --ignore-platform-reqs

# 2. Interface (Vite) : le thème Filament importe des fichiers de vendor.
FROM node:24-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY --from=vendor /app/vendor ./vendor
COPY vite.config.js ./
COPY resources ./resources
COPY public ./public
COPY app ./app
RUN npm run build

# 3. Image finale.
FROM php:8.4-fpm-alpine

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Extensions de docs/07 (les autres sont déjà dans l'image officielle) ; client MariaDB pour
# patrimoine:sauvegarder et patrimoine:restaurer ; su-exec pour lancer artisan en www-data.
RUN install-php-extensions bcmath gd intl zip pdo_mysql opcache \
    && apk add --no-cache nginx supervisor mariadb-client su-exec tzdata openssl \
    && cp /usr/share/zoneinfo/Africa/Brazzaville /etc/localtime

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-patrimoine.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-patrimoine.conf
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/mariadb-client.cnf /etc/my.cnf.d/patrimoine-client.cnf
COPY docker/entrypoint.sh /usr/local/bin/patrimoine-entrypoint

WORKDIR /var/www/html

COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY --from=assets /app/public/build ./public/build

# Autoload optimisé, découverte des paquets et publication des assets Filament (sans base : cache en mémoire).
RUN sed -i 's/\r$//' /usr/local/bin/patrimoine-entrypoint \
    && chmod +x /usr/local/bin/patrimoine-entrypoint \
    && composer dump-autoload --optimize --classmap-authoritative --no-dev --no-interaction \
    && CACHE_STORE=array php artisan package:discover \
    && CACHE_STORE=array php artisan filament:upgrade \
    && mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD wget -qO- http://127.0.0.1/up > /dev/null || exit 1

ENTRYPOINT ["patrimoine-entrypoint"]
CMD ["app"]
