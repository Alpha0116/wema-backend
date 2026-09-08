FROM serversideup/php:8.3-fpm-nginx

ENV AUTORUN_ENABLED=true
ENV SSL_MODE=off
ENV PHP_OPCACHE_ENABLE=1

WORKDIR /var/www/html

# Copy application files
COPY --chown=9999:9999 backend/ /var/www/html/

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction

EXPOSE 8080
