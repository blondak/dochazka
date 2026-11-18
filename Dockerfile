FROM node:22-bookworm-slim AS frontend
RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates curl openssl && rm -rf /var/lib/apt/lists/*
WORKDIR /build/web
COPY web/package*.json ./
RUN npm ci
COPY web/ ./
RUN npm run build
COPY bin/docs-assets.sh /build/bin/docs-assets.sh
RUN sh /build/bin/docs-assets.sh

FROM php:8.4-fpm AS app
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libjpeg62-turbo-dev libpng-dev unzip && docker-php-ext-configure gd --with-jpeg && docker-php-ext-install gd pdo_pgsql && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /srv/dochazka
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --no-autoloader
COPY src src
COPY config config
COPY public/index.php public/index.php
COPY bin bin
COPY migrations migrations
COPY deploy/php-fpm.conf /usr/local/etc/php-fpm.d/zzzz-dochazka.conf
RUN composer dump-autoload --no-dev --optimize && install -d -o www-data -g www-data var && php bin/console api:openapi && chown -R www-data:www-data var

FROM nginx:alpine AS web
COPY deploy/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=frontend /build/public/ /srv/dochazka/public/
COPY --from=app /srv/dochazka/public/openapi.json /srv/dochazka/public/openapi.json
COPY public/docs/index.html /srv/dochazka/public/docs/index.html
