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
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        libwebp-dev \
        libzip-dev \
        libicu-dev \
        ca-certificates \
        curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        gd \
        zip \
        intl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install supercronic (cron replacement designed for containers: proper signal
# handling, stdout logging, single-process). Pinned SHA256 for integrity.
ENV SUPERCRONIC_URL=https://github.com/aptible/supercronic/releases/download/v0.2.33/supercronic-linux-amd64 \
    SUPERCRONIC_SHA256SUM=feefa310da569c81b99e1027b86b27b51e6ee9ab647747b49099645120cfc671 \
    SUPERCRONIC=supercronic-linux-amd64

RUN curl -fsSLO "$SUPERCRONIC_URL" \
 && echo "${SUPERCRONIC_SHA256SUM}  ${SUPERCRONIC}" | sha256sum -c - \
 && chmod +x "$SUPERCRONIC" \
 && mv "$SUPERCRONIC" "/usr/local/bin/${SUPERCRONIC}" \
 && ln -s "/usr/local/bin/${SUPERCRONIC}" /usr/local/bin/supercronic

# PHP production config
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Copy the PHP app (code + vendor) from the composer-build stage
COPY --from=composer-build /build /var/www/api

# Copy the crontab (versioned in the repo) — single source of truth for schedules
COPY scheduler/crontab /etc/crontab

WORKDIR /var/www/api

# Running as non-root isn't worth the permission dance for a cron worker
# with no inbound sockets; keep root inside the container like api/Dockerfile.

CMD ["supercronic", "/etc/crontab"]
