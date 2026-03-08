# --- Build stage: install deps, build assets ---
FROM php:8.3-fpm-alpine AS build

# System deps
RUN apk add --no-cache \
    git curl zip unzip libpng-dev libjpeg-turbo-dev oniguruma-dev \
    libxml2-dev icu-dev

# PHP extensions
RUN docker-php-ext-configure gd --with-jpeg \
 && docker-php-ext-install pdo pdo_mysql mbstring exif pcntl bcmath gd intl

# Install composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Copy just composer files first (cache layer)
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Copy rest of app
COPY . .

# (Optional) build front-end assets if you use Vite
# RUN npm ci && npm run build

# --- Runtime stage: slimmer image ---
FROM php:8.3-fpm-alpine

RUN apk add --no-cache libpng libjpeg-turbo icu-libs

RUN docker-php-ext-configure gd --with-jpeg \
 && docker-php-ext-install pdo pdo_mysql mbstring exif pcntl bcmath gd intl

WORKDIR /app

# Copy app from build stage
COPY --from=build /app /app

# Laravel optimisations
RUN php artisan config:cache \
 && php artisan route:cache || true

# Run as non-root user
RUN adduser -D appuser
USER appuser

CMD ["php-fpm"]