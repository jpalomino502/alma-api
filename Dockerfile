FROM php:8.2-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git curl unzip libonig-dev libxml2-dev libzip-dev zip nginx supervisor sqlite3

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_mysql pdo_sqlite mbstring zip xml

# Copy project
COPY . /var/www/html
WORKDIR /var/www/html

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN composer install --no-dev --optimize-autoloader

# Generate Laravel cache files
RUN php artisan config:clear && \
    php artisan route:clear && \
    php artisan view:clear && \
    php artisan optimize

# Fix permissions for Laravel
RUN mkdir -p /var/www/html/storage/framework/{views,cache,sessions} \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Configure Nginx
RUN rm /etc/nginx/sites-enabled/default
RUN echo 'server { \
    listen 8080; \
    root /var/www/html/public; \
    index index.php index.html; \
    location / { try_files $uri $uri/ /index.php?$query_string; } \
    location ~ \.php$$ { \
        fastcgi_pass 127.0.0.1:9000; \
        fastcgi_index index.php; \
        include fastcgi_params; \
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; \
    } \
}' > /etc/nginx/conf.d/default.conf

# Supervisor config
RUN echo '[supervisord]\nnodaemon=true\n\n\
[program:php-fpm]\ncommand=/usr/local/sbin/php-fpm -F\n\n\
[program:nginx]\ncommand=nginx -g "daemon off;"\n' \
> /etc/supervisor/conf.d/supervisord.conf

EXPOSE 8080

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
