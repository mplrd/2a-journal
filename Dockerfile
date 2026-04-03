# =============================================================================
# Trading Journal — Production Dockerfile (Railway)
# Single image: PHP/Apache serves API (/api) + Frontend static files (/)
# =============================================================================

# --- Stage 1: Build frontend ---
FROM node:22-alpine AS frontend-build

WORKDIR /build

COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci

COPY frontend/ .

ARG VITE_API_URL=/api
ENV VITE_API_URL=${VITE_API_URL}

RUN npm run build

# --- Stage 2: Install PHP dependencies ---
FROM composer:2 AS composer-build

WORKDIR /build

COPY api/composer.json api/composer.lock* ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY api/ .
RUN composer dump-autoload --optimize --classmap-authoritative

# --- Stage 3: Production image ---
FROM php:8.4-apache

# Install PHP extensions needed by the project
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        libzip-dev \
        libicu-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        gd \
        zip \
        intl \
        opcache \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Enable Apache modules
RUN a2enmod rewrite headers env

# PHP production config
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini "$PHP_INI_DIR/conf.d/99-app.ini"
COPY docker/opcache.ini "$PHP_INI_DIR/conf.d/opcache.ini"

# Apache config
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

# Copy API (PHP backend)
COPY --from=composer-build /build /var/www/api

# Copy frontend build
COPY --from=frontend-build /build/dist /var/www/frontend

# Set permissions
RUN chown -R www-data:www-data /var/www/api /var/www/frontend

# Railway uses PORT env var
ENV PORT=8080
RUN sed -i 's/Listen 80/Listen ${PORT}/' /etc/apache2/ports.conf

EXPOSE 8080

CMD ["apache2-foreground"]
