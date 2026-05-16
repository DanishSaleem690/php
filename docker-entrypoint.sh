#!/bin/sh
set -e

PORT="${PORT:-8080}"

echo "[backend] starting PHP built-in server on 0.0.0.0:${PORT} (docroot /var/www/html)"

exec php -S "0.0.0.0:${PORT}" -t /var/www/html
