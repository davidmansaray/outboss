#!/usr/bin/env bash
set -euo pipefail

current="${OUTBOSS_RELEASE_CURRENT:-/srv/outboss/current}"
cd "$current"

php8.4 artisan queue:restart --no-interaction

if command -v supervisorctl >/dev/null 2>&1; then
    supervisorctl restart outboss-mail-sync:* outboss-mail-send:* outboss-campaigns:* outboss-low:* outboss-ai:* outboss-objectives:* || true
fi
