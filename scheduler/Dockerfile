# =============================================================================
# Trading Journal — Scheduler (supercronic + PHP CLI)
# =============================================================================
# Separate Railway service. Runs scheduled PHP CLI jobs (broker auto-sync, and
# any future job added to scheduler/crontab).
#
# Build context: repo root (so we can COPY api/ into the image).
# On Railway: Root Directory = "." (repo root), Dockerfile = "scheduler/Dockerfile".
# Scale this service to exactly 1 instance — otherwise jobs run twice.

# --- Stage 1: Install PHP dependencies ---
FROM composer:2 AS composer-build

WORKDIR /build

COPY api/composer.json api/composer.lock* ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --ignore-platform-reqs

COPY api/ .
RUN composer dump-autoload --optimize --classmap-authoritative

# --- Stage 2: Runtime image with supercronic ---
FROM php:8.4-cli

# PHP extensions needed by the CLI jobs (must match api/Dockerfile so the code runs)
# Plus the Debian cron daemon (cron -f runs in foreground, stdout/stderr go to
# the container logs — battle-tested and simpler than supercronic, which was
# crashing with "Failed to fork exec" on Railway for reasons never explained).
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        libwebp-dev \
        libzip-dev \
        libicu-dev \
        ca-certificates \
        cron \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        gd \
        zip \
        intl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# PHP production config
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Copy the PHP app (code + vendor) from the composer-build stage
COPY --from=composer-build /build /var/www/api

# Install the crontab into /etc/cron.d/ (debian cron picks up files there)
# with the required format: a USER field between schedule and command.
# Permission must be 0644 (world-readable) for cron to load it.
COPY scheduler/crontab /etc/cron.d/scheduler
RUN sed -i 's/\r$//' /etc/cron.d/scheduler && chmod 0644 /etc/cron.d/scheduler

# Redirect cron job output to a central file that we tail from the entrypoint,
# so job stdout/stderr becomes visible in Railway logs.
RUN touch /var/log/cron.log && chmod 0666 /var/log/cron.log

COPY scheduler/entrypoint.sh /entrypoint.sh
RUN sed -i 's/\r$//' /entrypoint.sh && chmod +x /entrypoint.sh

# Build-time sanity checks: fail fast if what cron is about to exec is not
# actually in the image. Catches COPY mishaps, missing files, and PATH issues.
RUN test -x /usr/local/bin/php \
 && test -x /usr/sbin/cron \
 && test -f /var/www/api/cli/sync-brokers.php \
 && test -f /etc/cron.d/scheduler \
 && test -x /entrypoint.sh \
 && echo "[scheduler build] all paths verified" \
 || (echo "[scheduler build] SANITY CHECK FAILED" && ls -la /var/www/api/cli /etc/cron.d/scheduler /usr/local/bin/php /usr/sbin/cron /entrypoint.sh 2>&1; exit 1)

WORKDIR /var/www/api

CMD ["/entrypoint.sh"]
