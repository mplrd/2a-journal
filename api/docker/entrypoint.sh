#!/bin/sh
set -e

# Run migrations (idempotent)
echo "==> Running migrations..."
php /var/www/api/database/migrate.php

# Seed demo data (idempotent)
echo "==> Seeding demo data..."
php /var/www/api/database/seed-demo.php

# Start PHP built-in server
echo "==> Starting PHP server on port ${PORT}..."
exec php -S 0.0.0.0:${PORT} -t /var/www/api/public /var/www/api/public/index.php
