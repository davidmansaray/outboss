#!/usr/bin/env bash
set -euo pipefail

release="${OUTBOSS_RELEASE_PATH:?OUTBOSS_RELEASE_PATH is required}"
resolved="$(readlink -f "$release")"
cd "$resolved"

if [[ "$(id -u)" -eq 0 ]]; then
    chown -R www-data:www-data "$resolved"
    chown -R www-data:www-data "${OUTBOSS_DEPLOY_ROOT:-/srv/outboss}/shared/storage" 2>/dev/null || true
fi

mkdir -p bootstrap/cache
chmod -R ug+rwx storage bootstrap/cache || true

php8.4 artisan migrate --force --no-interaction
php8.4 artisan gtm:backfill-profiles --no-interaction
php8.4 artisan storage:link --force --no-interaction >/dev/null 2>&1 || php8.4 artisan storage:link --no-interaction
php8.4 artisan config:cache --no-interaction
php8.4 artisan route:cache --no-interaction
php8.4 artisan view:cache --no-interaction
