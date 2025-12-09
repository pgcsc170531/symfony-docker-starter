FROM dunglas/frankenphp

# Install the MySQL driver and other valid extensions for Symfony
RUN install-php-extensions pdo_mysql intl zip opcache
