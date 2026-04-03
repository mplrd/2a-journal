#!/bin/sh
set -e

echo "==> Substituting PORT..."
envsubst '${PORT}' < /etc/nginx/sites-available/default > /etc/nginx/sites-enabled/default

echo "==> Testing nginx config..."
nginx -t

echo "==> Starting php-fpm..."
php-fpm -D

echo "==> Starting nginx..."
nginx -g 'daemon off;' 2>&1
