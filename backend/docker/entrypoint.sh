#!/bin/sh
# Prepares the Laravel app at container start, then runs the given command (php-fpm).
set -e

cd /var/www/html

# APP_KEY: use the configured one, otherwise generate one once and keep it in the
# storage volume so it survives container restarts and rebuilds.
if [ -z "${APP_KEY}" ]; then
    KEY_FILE=storage/app/.app_key
    if [ ! -s "${KEY_FILE}" ]; then
        echo "[entrypoint] APP_KEY not set; generating one in ${KEY_FILE}"
        php -r 'echo "base64:".base64_encode(random_bytes(32));' > "${KEY_FILE}"
        chmod 600 "${KEY_FILE}"
    fi
    APP_KEY="$(cat "${KEY_FILE}")"
    export APP_KEY
fi

# The storage volume may be fresh or created by an older image.
mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions \
    storage/framework/views storage/logs bootstrap/cache

if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    attempt=1
    until php artisan migrate --force; do
        if [ "${attempt}" -ge 30 ]; then
            echo "[entrypoint] database still unavailable after ${attempt} attempts" >&2
            exit 1
        fi
        echo "[entrypoint] waiting for the database (attempt ${attempt})..."
        attempt=$((attempt + 1))
        sleep 3
    done
fi

# Demo data is loaded once. The seeders refuse to run when APP_ENV=production.
if [ "${SEED_DEMO_DATA:-false}" = "true" ] && [ ! -f storage/app/.demo_seeded ]; then
    echo "[entrypoint] seeding demo data (first start only, this takes a minute)..."
    php artisan db:seed --force
    touch storage/app/.demo_seeded
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache

chown -R www-data:www-data storage bootstrap/cache

exec "$@"
