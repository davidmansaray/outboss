#!/usr/bin/env bash
set -euo pipefail

s2_db_path="${S2_BROWSER_DB_PATH:-$PWD/storage/framework/testing/s2-browser.sqlite}"
mkdir -p "$(dirname "$s2_db_path")"
: > "$s2_db_path"

export APP_ENV=testing
export APP_URL=http://127.0.0.1:8012
export DB_CONNECTION=sqlite
export DB_DATABASE="$s2_db_path"
export SESSION_DRIVER=file
export CACHE_STORE=file
export QUEUE_CONNECTION=sync
export OUTBOSS_TEST_FAKE_INTELLIGENCE_PROVIDERS=true
export CAMPAIGN_SENDING_ENABLED=false

php artisan migrate:fresh --force --no-interaction
php artisan db:seed --class='Database\Seeders\S2BrowserSeeder' --force --no-interaction
exec php artisan serve --host=127.0.0.1 --port=8012 --no-reload
