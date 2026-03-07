#!/bin/sh
set -e

# Run migrations if database is ready (optional, but good for Render)
# php artisan migrate --force

# Optimize Laravel
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Start Apache in the foreground
exec apache2-foreground
