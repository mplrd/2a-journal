# =============================================================================
# Trading Journal — API Dockerfile (PHP/Apache)
# =============================================================================

# --- Stage 1: Install PHP dependencies ---
FROM composer:2 AS composer-build

WORKDIR /build

COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --ignore-platform-reqs

COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative

# --- Stage 2: Production image ---
FROM php:8.4-apache

# Install system libraries for PHP extensions
RUN apt-get update && apt-get install -y --no-install-recommends \
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

# Ensure only prefork MPM is active (required for mod_php)
RUN a2dismod mpm_event mpm_worker 2>/dev/null; a2enmod mpm_prefork rewrite headers env

# PHP production config
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini "$PHP_INI_DIR/conf.d/99-app.ini"
COPY docker/opcache.ini "$PHP_INI_DIR/conf.d/opcache.ini"

# Apache config
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

# Copy API
COPY --from=composer-build /build /var/www/api

# Set permissions
RUN chown -R www-data:www-data /var/www/api

# Railway uses PORT env var
ENV PORT=8080
RUN sed -i 's/Listen 80/Listen ${PORT}/' /etc/apache2/ports.conf

EXPOSE 8080

CMD ["apache2-foreground"]
