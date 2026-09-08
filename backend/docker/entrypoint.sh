#!/bin/sh
set -e

# Setup SQLite DB directory if not exists
mkdir -p /var/www/html/database
touch /var/www/html/database/database.sqlite
chown -R www-data:www-data /var/www/html/database /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/database

# Run migrations and seeders
php /var/www/html/artisan migrate --force
php /var/www/html/artisan db:seed --force
php /var/www/html/artisan storage:link || true

exec /usr/bin/supervisord -c /etc/supervisord.conf
