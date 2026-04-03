# =============================================================================
# Trading Journal — API Dockerfile (PHP-FPM + Nginx)
# =============================================================================

# --- Stage 1: Install PHP dependencies ---
FROM composer:2 AS composer-build

WORKDIR /build

COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --ignore-platform-reqs

COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative

# --- Stage 2: Production image ---
FROM php:8.4-fpm

# Install nginx and PHP extension dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx \
        gettext-base \
        netcat-openbsd \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        libwebp-dev \
        libzip-dev \
        libicu-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        gd \
        zip \
        intl \
        opcache \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# PHP production config
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini "$PHP_INI_DIR/conf.d/99-app.ini"
COPY docker/opcache.ini "$PHP_INI_DIR/conf.d/opcache.ini"
RUN sed -i 's/\r$//' "$PHP_INI_DIR/conf.d/99-app.ini" "$PHP_INI_DIR/conf.d/opcache.ini"

# Nginx config (full replacement, used as template for envsubst)
COPY docker/nginx.conf /etc/nginx/nginx.conf.template
RUN sed -i 's/\r$//' /etc/nginx/nginx.conf.template \
    && rm -f /etc/nginx/sites-enabled/default /etc/nginx/conf.d/default.conf

# Copy API
COPY --from=composer-build /build /var/www/api

# Set permissions
RUN chown -R www-data:www-data /var/www/api

# Railway uses PORT env var
ENV PORT=8080
EXPOSE 8080

# Start both php-fpm and nginx
COPY docker/entrypoint.sh /entrypoint.sh
RUN sed -i 's/\r$//' /entrypoint.sh && chmod +x /entrypoint.sh

CMD ["/entrypoint.sh"]
