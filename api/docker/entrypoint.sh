#!/bin/sh

# Substitute PORT in nginx config
envsubst '${PORT}' < /etc/nginx/sites-available/default > /etc/nginx/sites-enabled/default

# Start php-fpm in background
php-fpm -D

# Start nginx in foreground
nginx -g 'daemon off;'
