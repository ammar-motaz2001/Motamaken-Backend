#!/bin/sh
# Runs before every process of the Render image (web, queue worker, scheduler).
set -e

cd /var/www/html

# Uploads live on the persistent disk mounted at storage/app (web service only); make sure its folders exist.
mkdir -p storage/app/public storage/app/private
php artisan storage:link --force --no-interaction >/dev/null 2>&1 || true

# Settings come from Render's environment, so the caches are built at start, not at build time.
php artisan config:cache --no-interaction >/dev/null
php artisan route:cache --no-interaction >/dev/null
php artisan view:cache --no-interaction >/dev/null
php artisan event:cache --no-interaction >/dev/null

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force --no-interaction
fi

exec "$@"
