FROM composer:2.7 AS composer

FROM php:8.3-cli-bookworm

ARG APP_USER=www-data
ARG APP_UID=1000

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1 \
    PHP_MEMORY_LIMIT=-1

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        git \
        libpq-dev \
        librabbitmq-dev \
        unzip \
        $PHPIZE_DEPS \
    && pecl install amqp redis \
    && docker-php-ext-enable amqp redis \
    && docker-php-ext-install -j"$(nproc)" \
        opcache \
        pcntl \
        pdo_pgsql \
        sockets \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json ./

RUN composer install \
        --no-progress \
        --prefer-dist

COPY . .

RUN composer dump-autoload --classmap-authoritative --optimize \
    && mkdir -p var/cache var/log \
    && chown -R ${APP_UID}:${APP_UID} /app \
    && chmod +x bin/console bin/start-web

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-application.ini

USER ${APP_UID}

EXPOSE 8080

CMD ["php", "bin/console", "app:outbox:publish", "--watch", "--limit=100", "--sleep-ms=500"]
