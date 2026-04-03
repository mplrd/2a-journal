#!/bin/sh
set -e

echo "==> PORT=${PORT}"

# Substitute PORT in nginx config
envsubst '${PORT}' < /etc/nginx/nginx.conf.template > /etc/nginx/nginx.conf

echo "==> Testing nginx config..."
nginx -t 2>&1

# Debug: show php-fpm listen config
echo "==> PHP-FPM listen config:"
grep -r "^listen" /usr/local/etc/php-fpm.d/ 2>&1 || true

echo "==> Starting php-fpm..."
php-fpm -D
sleep 1

# Debug: check what's listening
echo "==> Listening ports:"
ss -tlnp 2>/dev/null || netstat -tlnp 2>/dev/null || true

echo "==> Starting nginx on port ${PORT}..."
exec nginx -g 'daemon off;'
