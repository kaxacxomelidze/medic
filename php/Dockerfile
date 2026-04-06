FROM php:8.2-fpm

# Install extensions
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    libicu-dev \
    libonig-dev \
    zip \
    unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        gd \
        mysqli \
        pdo \
        pdo_mysql \
        zip \
        intl \
        mbstring \
        opcache \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Copy custom PHP config
COPY php-custom.ini /usr/local/etc/php/conf.d/99-custom.ini

# Create log file
RUN touch /var/log/php_errors.log && chmod 666 /var/log/php_errors.log

WORKDIR /var/www/html
