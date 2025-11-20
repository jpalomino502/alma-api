FROM php:8.2-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git curl unzip libonig-dev libxml2-dev libzip-dev zip nginx supervisor sqlite3

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_mysql pdo_sqlite mbstring zip xml

# Configure PHP-FPM
COPY ./docker/php-fpm.conf /usr/local/etc/php-fpm.d/www.conf

# Configure Nginx
COPY ./docker/nginx.conf /etc/nginx/nginx.conf

# Copy project
COPY . /var/www/html
WORKDIR /var/www/html

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN composer install --no-dev --optimize-autoloader

# FIX PERMISSIONS (VERY IMPORTANT)
RUN mkdir -p /var/www/html/storage/framework/views \
    /var/www/html/storage/framework/cache \
    /var/www/html/storage/framework/sessions && \
    chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache && \
    chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Expose port
EXPOSE 80

# Supervisor configuration
COPY ./docker/supervisor.conf /etc/supervisor/conf.d/supervisor.conf

CMD ["/usr/bin/supervisord", "-n"]
