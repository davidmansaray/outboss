#!/usr/bin/env bash
set -euo pipefail

# Always create a new isolated fixture database; never migrate/reset the local app.
s3_fixture_dir="$(mktemp -d "${TMPDIR:-/tmp}/outboss-s3-browser.XXXXXX")"
export APP_ENV=testing
export APP_URL=http://127.0.0.1:8013
export APP_CONFIG_CACHE="$s3_fixture_dir/config.php"
export DB_CONNECTION=sqlite
export DB_URL=
export DB_DATABASE="$s3_fixture_dir/s3-browser.sqlite"
export SESSION_DRIVER=database
export SESSION_COOKIE=outboss_s3_browser_session
export CACHE_STORE=array
export QUEUE_CONNECTION=database
export OUTBOSS_TEST_FAKE_INTELLIGENCE_PROVIDERS=true
# This is a test-only process: AppServiceProvider binds FakeMailProvider.
export CAMPAIGN_SENDING_ENABLED=true

touch "$DB_DATABASE"
php artisan migrate --force --no-interaction
php artisan db:seed --class='Database\Seeders\S3BrowserSeeder' --force --no-interaction
printf 'Disposable S3 browser database: %s\n' "$DB_DATABASE"
exec php -S 127.0.0.1:8013 -t "$PWD/public" "$PWD/tests/Browser/s3-router.php"
