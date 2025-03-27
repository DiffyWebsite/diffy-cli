ARG PHP_VERSION_TAG=8.4.5-cli-alpine3.21
#ARG PHP_VERSION_TAG=8.2.28-cli-alpine3.21

FROM php:${PHP_VERSION_TAG}

RUN apk update \
    && apk upgrade \
    && apk add --no-cache bash git libzip-dev \
    && docker-php-ext-install zip \
    && rm -rf /var/cache/apk/*

COPY --from=composer/composer:2-bin /composer /usr/bin/composer

WORKDIR /diffy-cli
