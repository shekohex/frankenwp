#!/usr/bin/env bash
set -euo pipefail

APP_USER="${APP_USER:-www-data}"
APP_GROUP="${APP_GROUP:-www-data}"

repair_ownership() {
  local path="$1"

  if [ ! -e "$path" ]; then
    return 0
  fi

  chown -R "${APP_USER}:${APP_GROUP}" "$path"
  chmod -R u+rwX,go+rX "$path"
}

repair_ownership /var/www/html/wp-content/cache
repair_ownership /var/www/html/wp-content/uploads
repair_ownership /data/caddy
repair_ownership /config/caddy

exec runuser -u "$APP_USER" -- /usr/local/bin/docker-entrypoint.sh "$@"
