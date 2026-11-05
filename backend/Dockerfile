# syntax=docker/dockerfile:1.7

# ---- 1. production dependencies -------------------------------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock* ./
# Platform extensions are provided by the runtime image below.
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --optimize --no-dev --no-scripts

# ---- 2. dev dependencies (tests, static analysis) ------------------------------
FROM composer:2 AS vendor-dev
WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --optimize --no-scripts

# ---- 3. shared PHP runtime (FrankenPHP = Caddy + PHP 8.4, one binary) ------------
FROM dunglas/frankenphp:1-php8.4-alpine AS base
RUN install-php-extensions pdo_pgsql pcntl intl zip opcache

ARG UID=10001
# Non-root user; drop the CAP_NET_BIND_SERVICE capability (we listen on 8000, not 80/443).
RUN addgroup -g ${UID} app \
    && adduser -D -u ${UID} -G app app \
    && setcap -r /usr/local/bin/frankenphp \
    && mkdir -p /data/caddy /config/caddy \
    && chown -R app:app /data /config

ENV XDG_CONFIG_HOME=/config \
    XDG_DATA_HOME=/data \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=json

WORKDIR /app
COPY docker/php.ini $PHP_INI_DIR/conf.d/zz-app.ini
COPY --chmod=0755 docker/entrypoint.sh /usr/local/bin/app-entrypoint

# ---- 4. test image: dev deps, runs Pest ------------------------------------------
FROM base AS test
ENV APP_ENV=testing APP_DEBUG=false
COPY --chown=app:app . .
COPY --from=vendor-dev --chown=app:app /app/vendor ./vendor
RUN php artisan package:discover --ansi && chown -R app:app bootstrap/cache storage
USER app
# Throw-away key generated per run (tests never need a stable one).
CMD ["sh", "-c", "export APP_KEY=base64:$(php -r 'echo base64_encode(random_bytes(32));'); exec php artisan test"]

# ---- 5. production image -------------------------------------------------------
FROM base AS runtime
COPY --chown=app:app . .
COPY --from=vendor --chown=app:app /app/vendor ./vendor
RUN rm -rf tests phpunit.xml phpstan.neon pint.json \
    && php artisan package:discover --ansi \
    && mkdir -p storage/secrets \
    && chown -R app:app bootstrap/cache storage
USER app
EXPOSE 8000
HEALTHCHECK --interval=10s --timeout=3s --start-period=60s --retries=10 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1:8000/api/health") === false ? 1 : 0);'
ENTRYPOINT ["app-entrypoint"]
CMD ["frankenphp", "php-server", "--root", "public/", "--listen", ":8000"]
