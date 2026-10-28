# Stage 1: Build dependencies
FROM dunglas/frankenphp:1-php8.4-alpine AS builder

WORKDIR /app

# 1. Install system dependencies and PHP extensions
RUN install-php-extensions pdo_mysql intl zip opcache gd

# 1b. Memory limit for every PHP process in this stage.
#
# This image ships NO php.ini (`Loaded Configuration File: (none)`), so PHP's
# compiled-in default of 128M applies to every process — including the
# `cache:clear` that Composer's auto-scripts run in step 6. A prod warmup
# compiles the whole DI container plus every Twig template and peaks around
# 124M of real memory, which leaves nothing for a cold builder: the process is
# killed with "Allowed memory size of 134217728 bytes exhausted" and the build
# exits 255. `composer install -d memory_limit` does not help, because the
# console command runs in a child process that reads this ini, not Composer's
# runtime setting. Raise it once, for everything.
RUN printf 'memory_limit = 512M\n' > /usr/local/etc/php/conf.d/zz-memory-limit.ini

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

# 7b. Same memory limit as the builder, for the warmup in step 10 and for the
# frankenphp workers at runtime. 128M is PHP's default, not a decision anyone
# made here, and this app does not fit in it.
RUN printf 'memory_limit = 512M\n' > /usr/local/etc/php/conf.d/zz-memory-limit.ini

# 8. Copy ONLY the built application from the builder
COPY --from=builder /app /app

# 9. Set permissions
RUN chown -R www-data:www-data /app/var

# 10. Warm up Symfony cache for production speed
# This ensures the first user doesn't experience a slow page load.
# The memory limit comes from 7b — do not remove it while this line exists.
RUN php bin/console cache:clear --env=prod

ENV APP_ENV=prod
ENV FRANKENPHP_CONFIG="worker ./public/index.php"