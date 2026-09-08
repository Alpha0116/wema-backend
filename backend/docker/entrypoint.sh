#!/bin/sh
set -e

# Setup SQLite DB if not exists
touch /var/www/html/database/database.sqlite
chown -R www-data:www-data /var/www/html/database

# Run migrations and seeders
php /var/www/html/artisan migrate --force
php /var/www/html/artisan db:seed --force
php /var/www/html/artisan storage:link || true
php /var/www/html/artisan config:cache || true
php /var/www/html/artisan route:cache || true

exec /usr/bin/supervisord -c /etc/supervisord.conf
