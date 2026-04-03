#!/bin/sh
set -e

echo "==> Substituting PORT..."
envsubst '${PORT}' < /etc/nginx/sites-available/default > /etc/nginx/sites-enabled/default

echo "==> Testing nginx config..."
nginx -t

echo "==> Starting php-fpm..."
php-fpm -D

# Wait for php-fpm socket to be ready
echo "==> Waiting for php-fpm..."
for i in $(seq 1 30); do
    if [ -S /var/run/php-fpm.sock ]; then
        echo "==> php-fpm ready"
        break
    fi
    sleep 0.2
done

echo "==> Starting nginx..."
nginx -g 'daemon off;' 2>&1
