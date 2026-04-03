#!/bin/sh
set -e

# Substitute PORT in nginx config
envsubst '${PORT}' < /etc/nginx/nginx.conf.template > /etc/nginx/nginx.conf

# Start php-fpm
php-fpm -D
sleep 1

# Start nginx in foreground
exec nginx -g 'daemon off;'
