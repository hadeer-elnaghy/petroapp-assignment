FROM php:8.2-cli-alpine

# Install system dependencies and PHP extensions
RUN apk add --no-cache \
    zip \
    libzip-dev \
    sqlite-dev \
    bash \
    make \
    && docker-php-ext-install pdo pdo_sqlite zip

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /app

# Copy dependency configuration files
COPY composer.json composer.lock ./

# Install Composer dependencies
RUN composer install --no-scripts --no-autoloader

# Copy application files
COPY . .

# Generate autoloader and run scripts
RUN composer dump-autoload --optimize \
    && php artisan config:clear

# Create environment file and generate application key
RUN cp .env.example .env \
    && php artisan key:generate

# Expose port
EXPOSE 8000

# Setup SQLite database and permissions
RUN touch database/database.sqlite \
    && chmod -R 777 storage database

# Create a shortcut for running tests
RUN printf '#!/bin/sh\nphp artisan test "$@"\n' > /usr/local/bin/test \
    && chmod +x /usr/local/bin/test

# Run migrations and start the Laravel development server
CMD php artisan migrate --force && php artisan serve --host=0.0.0.0 --port=8000
