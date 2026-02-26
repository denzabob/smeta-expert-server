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

# Установка Python зависимостей для парсера
RUN pip3 install --no-cache-dir --break-system-packages \
    playwright \
    requests \
    aiohttp \
    beautifulsoup4 \
    lxml \
    pillow

# Установка браузеров Playwright и их системных зависимостей
RUN playwright install chromium && \
    playwright install-deps chromium

# Установка Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Копируем конфигурационные файлы
COPY docker/app/php-fpm.conf /usr/local/etc/php-fpm.d/zzz-custom.conf
COPY docker/app/php.ini /usr/local/etc/php/conf.d/custom.ini

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