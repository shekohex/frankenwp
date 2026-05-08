#!/usr/bin/env bash
set -euo pipefail

APP_USER="${APP_USER:-www-data}"
APP_GROUP="${APP_GROUP:-www-data}"

activate_bundled_plugins() {
  local plugin_slug="auto-watermark"
  local plugin_path="/var/www/html/wp-content/plugins/${plugin_slug}/${plugin_slug}.php"

  if [ ! -f "$plugin_path" ]; then
    return 0
  fi

  (
    for _ in $(seq 1 60); do
      if runuser -u "$APP_USER" -- wp core is-installed --path=/var/www/html >/dev/null 2>&1; then
        runuser -u "$APP_USER" -- wp plugin is-active "$plugin_slug" --path=/var/www/html >/dev/null 2>&1 && exit 0
        runuser -u "$APP_USER" -- wp plugin activate "$plugin_slug" --path=/var/www/html >/dev/null 2>&1 && exit 0
      fi

      sleep 2
    done

    echo "Auto Watermark activation skipped or failed" >&2
  ) &
}

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

activate_bundled_plugins

exec runuser -u "$APP_USER" -- /usr/local/bin/docker-entrypoint.sh "$@"
