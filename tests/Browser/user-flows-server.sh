#!/usr/bin/env bash
set -euo pipefail

# Isolated disposable SQLite fixture for operator user-flow browser tests.
# Never migrates or resets the normal local database. FakeMailProvider is
# bound because APP_ENV=testing. Campaign sending stays off.
user_flow_db_path="${USER_FLOW_BROWSER_DB_PATH:-$PWD/storage/framework/testing/user-flows-browser.sqlite}"
mkdir -p "$(dirname "$user_flow_db_path")"
: > "$user_flow_db_path"

export APP_ENV=testing
export APP_URL=http://127.0.0.1:8124
export DB_CONNECTION=sqlite
export DB_URL=
export DB_DATABASE="$user_flow_db_path"
export SESSION_DRIVER=database
export SESSION_COOKIE=outboss_user_flows_session
export CACHE_STORE=array
export QUEUE_CONNECTION=sync
export CAMPAIGN_SENDING_ENABLED=false
export MAIL_MAILER=array

php artisan migrate:fresh --force --no-interaction
php artisan db:seed --class='Database\Seeders\UserFlowBrowserSeeder' --force --no-interaction
printf 'Disposable user-flow browser database: %s\n' "$user_flow_db_path"
exec php artisan serve --host=127.0.0.1 --port=8124 --no-reload
