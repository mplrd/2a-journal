#!/bin/sh
set -e

# Substitute PORT in nginx config
envsubst '${PORT}' < /etc/nginx/nginx.conf.template > /etc/nginx/nginx.conf

# Start php-fpm in background
php-fpm -D

# Wait for php-fpm
until nc -z 127.0.0.1 9000 2>/dev/null; do sleep 0.2; done
echo "php-fpm ready"

# Start nginx in foreground
exec nginx -g 'daemon off;'
