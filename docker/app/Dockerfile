FROM php:8.3-fpm

# -------------------------
# System dependencies
# -------------------------

RUN apt-get update && apt-get install -y \
    git \
    curl \
    unzip \
    unar \
    libpng-dev \
    libjpeg-dev \
    libwebp-dev \
    libfreetype6-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
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
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install pdo_mysql mbstring zip gd bcmath \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# -------------------------
# Composer
# -------------------------

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# -------------------------
# PHP config
# -------------------------

COPY docker/app/php-fpm.conf /usr/local/etc/php-fpm.d/zzz-custom.conf
COPY docker/app/php.ini /usr/local/etc/php/conf.d/custom.ini
COPY docker/app/entrypoint.sh /usr/local/bin/docker-entrypoint.sh

# -------------------------
# Workdir
# -------------------------

WORKDIR /var/www/html

# -------------------------
# Copy only composer first (build cache)
# -------------------------

COPY server/composer.json server/composer.lock ./

RUN composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --no-scripts

# -------------------------
# Copy project
# -------------------------

COPY server/ .
RUN php artisan package:discover --ansi || true

# -------------------------
# Playwright
# -------------------------

ENV PLAYWRIGHT_BROWSERS_PATH=/root/.cache/ms-playwright

RUN pip3 install --no-cache-dir --break-system-packages playwright \
    && python3 -m playwright install chromium

# -------------------------
# Laravel setup
# -------------------------

RUN php artisan key:generate --ansi || true

# -------------------------
# Permissions
# -------------------------

RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 775 storage bootstrap/cache

RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# -------------------------
# Port
# -------------------------

EXPOSE 9000

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["php-fpm"]
