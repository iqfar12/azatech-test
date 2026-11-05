#!/bin/sh
# Entrypoint shared by the api, worker and scheduler containers.
set -eu
cd /app

# 1. APP_KEY: use the provided one, otherwise generate once and share it through the
#    `secrets` volume so api, worker and scheduler use the same key. Never baked into the image.
if [ -z "${APP_KEY:-}" ]; then
    KEY_FILE=/app/storage/secrets/app_key
    if [ ! -s "$KEY_FILE" ]; then
        php -r 'echo "base64:".base64_encode(random_bytes(32));' > "$KEY_FILE"
        chmod 600 "$KEY_FILE"
        echo '{"message":"entrypoint.app_key_generated"}'
    fi
    APP_KEY="$(cat "$KEY_FILE")"
    export APP_KEY
fi

# 2. Migrations (+ demo seed on first boot) — only the api container sets RUN_MIGRATIONS=true.
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    attempt=1
    until php artisan migrate --force --no-interaction; do
        if [ "$attempt" -ge 30 ]; then
            echo '{"message":"entrypoint.migrate_failed"}' >&2
            exit 1
        fi
        echo "{\"message\":\"entrypoint.waiting_for_database\",\"attempt\":$attempt}"
        attempt=$((attempt + 1))
        sleep 2
    done

    if [ "${SEED_ON_BOOT:-false}" = "true" ]; then
        php artisan shop:seed-if-empty --no-interaction
    fi
fi

# 3. Production caches (config is read from the container environment at this point).
php artisan config:cache --no-interaction >/dev/null
php artisan route:cache --no-interaction >/dev/null
php artisan view:cache --no-interaction >/dev/null

exec "$@"
