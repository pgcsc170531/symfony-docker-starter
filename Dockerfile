FROM dunglas/frankenphp

# Install the MySQL driver and other valid extensions for Symfony
RUN install-php-extensions pdo_mysql intl zip opcache

FROM dunglas/frankenphp

# Install the MySQL driver and other valid extensions for Symfony
RUN install-php-extensions pdo_mysql intl zip opcache

# NEW: Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer