FROM php:8.3-fpm

# Установка зависимостей ОС
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    unzip \
    nginx \
    && docker-php-ext-install pdo_mysql mbstring zip

# Установка Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
COPY docker/app/php-fpm.conf /usr/local/etc/php-fpm.d/zzz-custom.conf
# Копируем приложение
WORKDIR /var/www/html
COPY server/ .

# Устанавливаем зависимости
RUN composer install --no-dev --optimize-autoloader

# Генерируем ключ (если ещё не задан)
RUN php artisan key:generate --ansi

# Права
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html/storage \
    && chmod -R 755 /var/www/html/bootstrap/cache

EXPOSE 9000
CMD ["php-fpm"]