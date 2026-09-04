#!/bin/sh
# Prepare the app, then hand off to the CMD (supervisor).
set -e

cd /var/www/html

# --- storage skeleton (a mounted volume starts empty and hides the image's) --
mkdir -p \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/framework/testing \
    storage/logs
chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache

# --- APP_KEY -----------------------------------------------------------------
if [ -z "${APP_KEY}" ]; then
    echo "! APP_KEY is not set — using an ephemeral key. Encrypted cookies/"
    echo "! sessions reset on every restart. Set APP_KEY to a stable value."
    APP_KEY="base64:$(head -c 32 /dev/urandom | base64)"
    export APP_KEY
fi

# --- storage symlink -------------------------------------------------------
php artisan storage:link --no-interaction 2>/dev/null || true

# --- wait for the database ----------------------------------------------------
if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    touch "${DB_DATABASE:-/var/www/html/database/database.sqlite}"
    chown www-data:www-data "${DB_DATABASE:-/var/www/html/database/database.sqlite}" || true
else
    echo "> waiting for ${DB_CONNECTION} at ${DB_HOST}:${DB_PORT:-3306}"
    i=0
    until php artisan db:show --json >/dev/null 2>&1; do
        i=$((i + 1))
        if [ "$i" -ge 60 ]; then
            echo "! database not reachable after 120s — continuing anyway"
            break
        fi
        sleep 2
    done
fi

# --- migrations + framework caches -----------------------------------------
php artisan package:discover --ansi
php artisan migrate --force --no-interaction

php artisan config:clear
php artisan config:cache
php artisan event:cache
# NOTE: no route:cache — Route::native() registers Closure routes, which
# cannot be serialised. view:cache is skipped too (native Blade views must
# be compiled by the Edge runtime, never the web path).

exec "$@"
