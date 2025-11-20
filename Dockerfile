# ============================================================
# Etapa 1: PHP + Composer + dependencias Laravel
# ============================================================
FROM php:8.2-fpm AS php-build

RUN apt-get update && apt-get install -y \
    git unzip libzip-dev libpng-dev sqlite3 libsqlite3-dev libonig-dev \
    && docker-php-ext-install pdo pdo_sqlite zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

RUN composer install --no-interaction --prefer-dist --optimize-autoloader

RUN chown -R www-data:www-data storage bootstrap/cache


# ============================================================
# Etapa 2: PHP + NGINX + Supervisor
# ============================================================
FROM php:8.2-fpm

RUN apt-get update && apt-get install -y \
    nginx supervisor sqlite3 libsqlite3-dev

COPY ./docker/nginx.conf /etc/nginx/sites-enabled/default
COPY ./docker/supervisor.conf /etc/supervisor/conf.d/supervisor.conf

WORKDIR /var/www/html

COPY --from=php-build /var/www/html ./

EXPOSE 80

CMD ["supervisord", "-n"]
