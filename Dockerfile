FROM php:8.2-fpm

# Install your original dependencies + supervisor
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libpq-dev \
    supervisor \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd zip pdo pdo_pgsql \
    && docker-php-ext-enable gd zip pdo_pgsql

# Copy Composer from your original setup
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

RUN php artisan config:clear || true
RUN php artisan route:clear || true
RUN php artisan view:clear || true

# CHANGED: Copy directly to the primary, default configuration path
COPY ./supervisord.conf /etc/supervisor/supervisord.conf

EXPOSE 8080

# CHANGED: Launch using the global default configuration path
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/supervisord.conf"]
