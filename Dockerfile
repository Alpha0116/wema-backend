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

# Ensure storage, database, and vendor folders exist with full permissions
RUN mkdir -p /var/www/html/storage /var/www/html/database /var/www/html/bootstrap/cache /var/www/html/vendor \
    && touch /var/www/html/database/database.sqlite \
    && chown -R 9999:9999 /var/www/html \
    && chmod -R 775 /var/www/html/storage /var/www/html/database /var/www/html/bootstrap/cache /var/www/html/vendor

# Install PHP dependencies as root to guarantee full access to vendor and composer cache
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts \
    && chown -R 9999:9999 /var/www/html/vendor

USER 9999

EXPOSE 8080
