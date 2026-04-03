#!/bin/sh
set -e

echo "==> PORT=${PORT}"

# Substitute PORT in nginx config
envsubst '${PORT}' < /etc/nginx/nginx.conf.template > /etc/nginx/nginx.conf

echo "==> Generated nginx.conf:"
cat /etc/nginx/nginx.conf

echo "==> Testing nginx config..."
nginx -t 2>&1

echo "==> Starting php-fpm..."
php-fpm -D

# Wait for php-fpm
until nc -z 127.0.0.1 9000 2>/dev/null; do sleep 0.2; done
echo "==> php-fpm ready on port 9000"

echo "==> Starting nginx on port ${PORT}..."
exec nginx -g 'daemon off;'
