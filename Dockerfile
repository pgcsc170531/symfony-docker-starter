# Stage 1: Build dependencies
FROM dunglas/frankenphp:1-php8.4-alpine AS builder

WORKDIR /app

# 1. Install system dependencies and PHP extensions
RUN install-php-extensions pdo_mysql intl zip opcache gd

# 2. Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 3. Copy only composer files first (This makes builds 10x faster!)
COPY composer.json composer.lock ./

# 4. Install PHP dependencies (Run before copying code to cache this layer)
RUN composer install --no-dev --no-scripts --no-autoloader

# 5. Copy the rest of the project
COPY . .

# 6. Finalize composer (Now it runs scripts and autoloader)
ENV APP_ENV=prod
RUN composer install --no-dev --optimize-autoloader

# Stage 2: Final Production Image
FROM dunglas/frankenphp:1-php8.4-alpine

WORKDIR /app

# 7. Re-install required runtime extensions
RUN install-php-extensions pdo_mysql intl zip opcache gd

# 8. Copy ONLY the built application from the builder
COPY --from=builder /app /app

# 9. Set permissions
RUN chown -R www-data:www-data /app/var

# 10. Warm up Symfony cache for production speed
# This ensures the first user doesn't experience a slow page load
RUN php bin/console cache:clear --env=prod

ENV APP_ENV=prod
ENV FRANKENPHP_CONFIG="worker ./public/index.php"