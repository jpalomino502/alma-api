FROM php:8.2-fpm

RUN apt-get update && apt-get install -y \
    git curl unzip nginx supervisor sqlite3 \
    libonig-dev libxml2-dev libzip-dev libpng-dev zlib1g-dev libsqlite3-dev \
    && docker-php-ext-install mbstring pdo pdo_mysql pdo_sqlite zip xml

COPY . /var/www/html
WORKDIR /var/www/html

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN composer install --no-dev --optimize-autoloader

# Correct storage structure and permissions
RUN mkdir -p storage/framework/{views,cache,sessions} \
    && mkdir -p bootstrap/cache \
    && touch storage/framework/views/.gitignore \
    && touch storage/framework/cache/.gitignore \
    && touch storage/framework/sessions/.gitignore \
    && chmod -R 775 storage bootstrap/cache \
    && chown -R www-data:www-data /var/www/html

# Remove any previous Laravel cache (VERY IMPORTANT)
RUN rm -f bootstrap/cache/*.php

# Nginx config
RUN rm -f /etc/nginx/sites-enabled/default
RUN echo 'server { \
    listen 8080; \
    root /var/www/html/public; \
    index index.php index.html; \
    location / { try_files $uri $uri/ /index.php?$query_string; } \
    location ~ \.php$$ { \
        include fastcgi_params; \
        fastcgi_pass 127.0.0.1:9000; \
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; \
    } \
}' > /etc/nginx/conf.d/default.conf

# Supervisor
RUN echo '[supervisord]\nnodaemon=true\n\n\
[program:php-fpm]\ncommand=/usr/local/sbin/php-fpm -F\nautostart=true\nautorestart=true\n\n\
[program:nginx]\ncommand=nginx -g "daemon off;"\nautostart=true\nautorestart=true\n' \
> /etc/supervisor/conf.d/supervisord.conf

EXPOSE 8080

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
