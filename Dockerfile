FROM php:8.3-cli-alpine

# Install required PHP extensions for Laravel & SQLite
RUN apk add --no-cache sqlite sqlite-dev oniguruma-dev libpng-dev \
    && docker-php-ext-install pdo pdo_sqlite mbstring bcmath gd

# Copy Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy code
COPY . /var/www/html/

# Prepare default .env
RUN cp .env.example .env || true

# Install dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

# Setup SQLite DB & permissions
RUN mkdir -p database storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && touch database/database.sqlite \
    && chmod -R 777 storage bootstrap/cache database

EXPOSE 8080

CMD ["sh", "-c", "php artisan key:generate --force || true && php artisan migrate --force && php artisan db:seed --force && php artisan storage:link || true && php artisan serve --host=0.0.0.0 --port=${PORT:-8080}"]
