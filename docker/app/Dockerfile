FROM php:8.3-fpm

# Установка зависимостей ОС
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    unzip \
    nginx \
    python3 \
    python3-pip \
    nodejs \
    npm \
    libgtk-3-0 \
    libgdk-pixbuf-xlib-2.0-0 \
    libpangocairo-1.0-0 \
    libcairo-gobject2 \
    libgbm1 \
    libxss1 \
    libnss3 \
    libatk1.0-0 \
    libatk-bridge2.0-0 \
    libcups2 \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql mbstring zip gd

# Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Конфиги
COPY docker/app/php-fpm.conf /usr/local/etc/php-fpm.d/zzz-custom.conf
COPY docker/app/php.ini /usr/local/etc/php/conf.d/custom.ini

# Приложение
WORKDIR /var/www/html
COPY server/ .

# PHP зависимости
RUN composer install --no-dev --optimize-autoloader

# Playwright (Node)
RUN npm install playwright
RUN npx playwright install chromium

# Laravel ключ
RUN php artisan key:generate --ansi

# Права
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 storage bootstrap/cache

EXPOSE 9000
CMD ["php-fpm"]