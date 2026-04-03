#!/bin/sh
set -e

# Substitute PORT in nginx config
envsubst '${PORT}' < /etc/nginx/nginx.conf.template > /etc/nginx/nginx.conf

# Start php-fpm
php-fpm -D
sleep 1

# Start nginx in background to self-test
nginx
sleep 1

# Self-test: hit the app internally
echo "==> Self-test on http://127.0.0.1:${PORT}/"
wget -q -O - --server-response http://127.0.0.1:${PORT}/ 2>&1 | head -20 || true

# Stop temporary nginx, restart in foreground
nginx -s stop 2>/dev/null || true
sleep 0.5
exec nginx -g 'daemon off;'
