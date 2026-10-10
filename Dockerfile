FROM php:8.4-fpm-alpine

# Install system dependencies and build libraries
RUN apk add --no-cache \
    curl \
    libzip-dev \
    libpng-dev \
    oniguruma-dev \
    icu-dev \
    linux-headers

# Install PHP core extensions
RUN docker-php-ext-install \
    pdo \
    pdo_mysql \
    bcmath \
    mbstring \
    opcache \
    intl \
    zip

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/trip

# Copy project files
COPY . /var/www/trip

# Install composer production dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Copy production PHP and OPcache configuration
COPY deployment/php/php.ini /usr/local/etc/php/conf.d/custom-php.ini
COPY deployment/php/opcache.ini /usr/local/etc/php/conf.d/custom-opcache.ini

# Set permissions for storage and cache directories
RUN chown -R www-data:www-data /var/www/trip/storage /var/www/trip/app/views /var/www/trip/public/uploads \
    && chmod -R 775 /var/www/trip/storage

EXPOSE 9000

CMD ["php-fpm"]