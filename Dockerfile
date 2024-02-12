FROM ghcr.io/roadrunner-server/roadrunner:latest AS roadrunner

FROM composer:latest AS composer

FROM php:8.3-alpine

COPY --from=composer /usr/bin/composer /usr/bin/composer

COPY --from=roadrunner /usr/bin/rr /usr/bin/rr

RUN apk add --no-cache \
    postgresql-dev \
    linux-headers \
    && apk add --no-cache --virtual .build-deps \
    $PHPIZE_DEPS \
    && docker-php-ext-install pdo_pgsql sockets \
    && apk del .build-deps

COPY . /app
WORKDIR /app

RUN composer install -o -a --apcu-autoloader --no-dev

CMD ["rr", "serve", "-c", ".rr.yaml"]
