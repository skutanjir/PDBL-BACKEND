#!/bin/sh
set -e

# Run migrations if database is ready
php artisan migrate --force --no-interaction

# Optimize Laravel
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Set Apache to listen on the port provided by Railway/Render
if [ -n "$PORT" ]; then
    sed -i "s/Listen 80/Listen $PORT/g" /etc/apache2/ports.conf
    sed -i "s/<VirtualHost \*:80>/<VirtualHost \*:$PORT>/g" /etc/apache2/sites-available/000-default.conf
fi

# Start Apache in the foreground
exec apache2-foreground
