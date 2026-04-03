#!/bin/sh
set -e

echo "==> Substituting PORT..."
envsubst '${PORT}' < /etc/nginx/sites-available/default > /etc/nginx/sites-enabled/default

echo "==> Testing nginx config..."
nginx -t

echo "==> Starting php-fpm..."
php-fpm -D

# Wait for php-fpm to be ready
echo "==> Waiting for php-fpm..."
for i in $(seq 1 30); do
    if nc -z 127.0.0.1 9000 2>/dev/null; then
        echo "==> php-fpm ready"
        break
    fi
    sleep 0.2
done

echo "==> Starting nginx..."
nginx -g 'daemon off;' 2>&1
