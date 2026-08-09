# syntax=docker/dockerfile:1
#
# Ledgerflow application image.
#
#   base ──┬── vendor ────────┐
#          │                  │
#          ├── dev            │  local development target used by compose.
#          │                  │  Host UID/GID remap lives HERE and nowhere else.
#          │                  │
#          └── production ◄───┘  immutable artifact: fixed 1000:1000 identity,
#                     │          so the same commit builds the same image from
#                     │          any machine (deployment-boundaries.md).
#                     │
#                     └── web    nginx fronting the production public/ tree.
#
# The production stage runs ONE process, chosen by the command. The entrypoint
# ends in `exec "$@"`, so Horizon, the scheduler/relay, Reverb and future SQS
# workers reuse this artifact with only a `command:` override and no image
# change (operations/runtime-contract.md).

# ---------------------------------------------------------------------------
# base — runtime, extensions, fixed application identity
# ---------------------------------------------------------------------------
FROM php:8.4.24-fpm-alpine AS base

# fcgi     -> cgi-fcgi, used by the php-fpm health check
# unzip    -> Composer's archive path; without it installs fall back to git
# postgresql-client -> psql, used for database evidence and inspection
RUN apk add --no-cache \
        fcgi \
        unzip \
        postgresql-client \
        # Runtime shared libraries for the extensions built below. These must
        # NOT live in .build-deps: deleting the -dev packages also deletes the
        # runtime .so files, and the extensions then fail to load at startup.
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
    # Pinned to the exact release verified against PHP 8.4.24 in this image.
    # An unpinned `pecl install redis` silently moves with upstream and would
    # make a rebuild of an old commit produce a different extension.
    && pecl install redis-6.3.0 \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /tmp/pear

# Fixed identity. Never derived from the build host: production must be
# reproducible from any builder. Only the dev stage below remaps it.
RUN addgroup -g 1000 app \
    && adduser -u 1000 -G app -D -h /home/app app

WORKDIR /var/www/html

# vendor/bin on PATH so tooling is invoked by name inside the container.
ENV PATH="/var/www/html/vendor/bin:${PATH}" \
    COMPOSER_ALLOW_SUPERUSER=0 \
    COMPOSER_HOME=/home/app/.composer

COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-ledgerflow.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-ledgerflow.conf
COPY --chmod=0755 docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh

COPY --from=composer:2.8.12 /usr/bin/composer /usr/bin/composer

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm", "--nodaemonize"]

# ---------------------------------------------------------------------------
# vendor — production dependency set, resolved from the committed lockfile
# ---------------------------------------------------------------------------
FROM base AS vendor

COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --no-interaction \
        --prefer-dist

# ---------------------------------------------------------------------------
# dev — compose target. Host identity remap lives only in this stage.
# ---------------------------------------------------------------------------
FROM base AS dev

ARG HOST_UID=1000
ARG HOST_GID=1000

# Collision-aware on BOTH ids. macOS is 501:20 and Alpine already owns gid 20
# (dialout), so a bare `addgroup -g` fails there. If the target gid already
# exists we join that group instead of creating one; likewise for the uid.
# busybox has no usermod/groupmod; they come from `shadow`.
RUN set -eux; \
    apk add --no-cache shadow; \
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
        /home/app /var/www/html /usr/local/var/log /usr/local/var/run

USER app

# ---------------------------------------------------------------------------
# production — immutable application artifact, fixed 1000:1000
# ---------------------------------------------------------------------------
FROM base AS production

COPY --from=vendor --chown=1000:1000 /var/www/html/vendor ./vendor
COPY --chown=1000:1000 . .

# The runtime tree is created here, explicitly. .gitignore placeholders are
# excluded from the build context, so the image must not depend on them to
# bring these directories into existence.
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

# ---------------------------------------------------------------------------
# web — nginx carrying the production public/ tree
#
# Production has no bind mount, so the web tier needs public/ baked in. Local
# development uses the stock nginx image with the project mounted instead, so
# this stage is built by CI and deployment only.
# ---------------------------------------------------------------------------
FROM nginx:1.30.4-alpine AS web

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=production /var/www/html/public /var/www/html/public
