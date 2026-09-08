FROM serversideup/php:8.3-fpm-nginx

ENV AUTORUN_ENABLED=true
ENV AUTORUN_LARAVEL_MIGRATION=true
ENV AUTORUN_LARAVEL_STORAGE_LINK=true
ENV SSL_MODE=off
ENV PHP_OPCACHE_ENABLE=1
ENV NGINX_PORT=8080

WORKDIR /var/www/html

USER root

# Copy application files
COPY --chown=9999:9999 . /var/www/html/

# Ensure storage and database folders exist and are writable
RUN mkdir -p /var/www/html/storage /var/www/html/database /var/www/html/bootstrap/cache \
    && touch /var/www/html/database/database.sqlite \
    && chown -R 9999:9999 /var/www/html/storage /var/www/html/database /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/database /var/www/html/bootstrap/cache

USER 9999

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

EXPOSE 8080
