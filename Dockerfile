FROM serversideup/php:8.3-fpm-nginx

ENV AUTORUN_ENABLED=true
ENV SSL_MODE=off
ENV PHP_OPCACHE_ENABLE=1

WORKDIR /var/www/html

# Copy application files
COPY --chown=9999:9999 backend/ /var/www/html/

# Prepare .env for build
RUN cp /var/www/html/.env.example /var/www/html/.env || true

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

EXPOSE 8080
