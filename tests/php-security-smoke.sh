#!/usr/bin/env bash
set -euo pipefail
image="${1:-waq3/wp:latest-php8.3}"
expected_php=8.3.36
expected_wordpress=7.1.3
actual_php="$(docker run --rm --network none --entrypoint php "$image" -r 'echo PHP_VERSION;')"
if [[ "$actual_php" != "$expected_php" ]]; then
  printf 'FAIL: PHP %s, expected %s\n' "$actual_php" "$expected_php" >&2
  exit 1
fi
# FrankenPHP dynamically loads libphp.so: check its HTTP SAPI, not just CLI.
docker run --rm --network none --entrypoint sh "$image" -ec '
  probe_dir=$(mktemp -d)
  trap '\''[ -z "${server_pid:-}" ] || kill "$server_pid" 2>/dev/null || true; rm -rf "$probe_dir"'\'' EXIT
  printf '\''<?php header("Content-Type: text/plain"); echo PHP_VERSION, ":", PHP_SAPI; '\'' > "$probe_dir/index.php"
  frankenphp php-server --listen 127.0.0.1:18080 --root "$probe_dir" > "$probe_dir/server.log" 2>&1 &
  server_pid=$!
  ready=0
  for attempt in $(seq 1 30); do
    if response=$(curl -fsS --max-time 2 http://127.0.0.1:18080/ 2>/dev/null); then
      test "$response" = "8.3.36:frankenphp"
      ready=1
      printf "HTTP SAPI: %s\n" "$response"
      break
    fi
    kill -0 "$server_pid" 2>/dev/null || { cat "$probe_dir/server.log"; exit 1; }
    sleep 1
  done
  test "$ready" = 1
'
docker run --rm --network none --entrypoint php "$image" -r '
  foreach (["bcmath", "redis", "apcu", "exif", "gd", "intl", "mysqli", "zip", "imagick", "Zend OPcache", "sodium"] as $extension) {
    if (!extension_loaded($extension)) { fwrite(STDERR, "Missing extension: $extension\n"); exit(1); }
  }
  if (!PHP_ZTS) { fwrite(STDERR, "Thread-safe PHP required\n"); exit(1); }
  echo "PHP extensions and ZTS: OK\n";
'
actual_wordpress="$(docker run --rm --network none --entrypoint wp "$image" --allow-root core version --path=/usr/src/wordpress)"
test "$actual_wordpress" = "$expected_wordpress"
docker run --rm --network none --entrypoint sh "$image" -ec '
  frankenphp list-modules | grep -Fx http.handlers.wp_cache
  SERVER_NAME=:80 frankenphp validate --config /etc/caddy/Caddyfile --adapter caddyfile
'
printf 'PASS: PHP %s CLI/HTTP, extensions, WordPress %s, custom cache module and Caddy configuration\n' "$actual_php" "$actual_wordpress"
