# syntax=docker/dockerfile:1

FROM php:8.4.24-fpm-alpine AS base

RUN apk add --no-cache \
        fcgi \
        unzip \
        postgresql-client \
        # Runtime libraries stay outside .build-deps: deleting that group would remove the .so files extensions load.
        icu-libs \
        libzip \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        libpq-dev \
        libzip-dev \
        linux-headers \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        sockets \
        zip \
    # Pinned: an unpinned pecl install would give a rebuild of an old commit a different extension.
    && pecl install redis-6.3.0 \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /tmp/pear

# Fixed identity, never derived from the build host, so production images are reproducible; only dev remaps it.
RUN addgroup -g 1000 app \
    && adduser -u 1000 -G app -D -h /home/app app

WORKDIR /var/www/html

ENV PATH="/var/www/html/vendor/bin:${PATH}" \
    COMPOSER_HOME=/home/app/.composer

COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-ledgerflow.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-ledgerflow.conf
COPY --chmod=0755 docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh

COPY --from=composer:2.8.12 /usr/bin/composer /usr/bin/composer

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm", "--nodaemonize"]

FROM base AS vendor

COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --no-interaction \
        --prefer-dist

FROM base AS dev

ARG HOST_UID=1000
ARG HOST_GID=1000

# Collision-aware: Alpine already owns macOS's gid 20, so an existing group is joined instead of recreated.
RUN set -eux; \
    apk add --no-cache --virtual .dev-user-deps shadow; \
    if [ "$HOST_GID" != "1000" ]; then \
        if getent group "$HOST_GID" >/dev/null 2>&1; then \
            usermod -aG "$(getent group "$HOST_GID" | cut -d: -f1)" app; \
        else \
            groupmod -g "$HOST_GID" app; \
        fi; \
    fi; \
    if [ "$HOST_UID" != "1000" ]; then \
        conflict="$(getent passwd "$HOST_UID" | cut -d: -f1 || true)"; \
        if [ -n "$conflict" ] && [ "$conflict" != "app" ]; then \
            echo "ERROR: HOST_UID=$HOST_UID is already taken by user '$conflict'." >&2; \
            echo "       Remapping would be skipped and 'app' would stay at uid 1000," >&2; \
            echo "       which makes every bind-mounted file unwritable at runtime." >&2; \
            echo "       Failing the build rather than shipping that silently." >&2; \
            echo "       Fix: free the uid in the base stage, or build with a different HOST_UID." >&2; \
            exit 1; \
        fi; \
        [ "$conflict" = "app" ] || usermod -u "$HOST_UID" app; \
    fi; \
    mkdir -p /usr/local/var/log /usr/local/var/run; \
    chown -R "$HOST_UID":"$HOST_GID" \
        /home/app /var/www/html /usr/local/var/log /usr/local/var/run; \
    apk del .dev-user-deps

USER app

FROM dev AS dev-xdebug

USER root

RUN apk add --no-cache --virtual .xdebug-build-deps \
        $PHPIZE_DEPS \
        linux-headers \
    && pecl install xdebug-3.5.3 \
    && docker-php-ext-enable xdebug \
    && apk del .xdebug-build-deps \
    && rm -rf /tmp/pear

COPY docker/php/xdebug.ini /usr/local/etc/php/conf.d/99-xdebug.ini

USER app

FROM base AS production

COPY --from=vendor --chown=1000:1000 /var/www/html/vendor ./vendor
COPY --chown=1000:1000 . .

# Created explicitly: the .gitignore placeholders are excluded from the build context.
RUN set -eux; \
    mkdir -p \
        storage/app/private \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/testing \
        storage/framework/views \
        storage/logs \
        bootstrap/cache; \
    composer dump-autoload --no-dev --optimize --classmap-authoritative; \
    chown -R 1000:1000 storage bootstrap/cache

USER app

FROM nginx:1.30.4-alpine AS web

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=production /var/www/html/public /var/www/html/public
