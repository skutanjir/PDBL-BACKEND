#!/bin/sh
set -e

# Force fix MPM at runtime
rm -f /etc/apache2/mods-enabled/mpm_*.load /etc/apache2/mods-enabled/mpm_*.conf
ln -sf /etc/apache2/mods-available/mpm_prefork.load /etc/apache2/mods-enabled/mpm_prefork.load
ln -sf /etc/apache2/mods-available/mpm_prefork.conf /etc/apache2/mods-enabled/mpm_prefork.conf

# Safety: never run with debug mode on unless explicitly set to true
if [ "${APP_DEBUG:-false}" = "true" ] && [ "${APP_ENV:-production}" = "production" ]; then
    echo "WARNING: APP_DEBUG=true in production is unsafe. Forcing APP_DEBUG=false."
    export APP_DEBUG=false
fi

# Set Apache port
if [ -n "$PORT" ]; then
    sed -i "s/Listen 80/Listen $PORT/g" /etc/apache2/ports.conf
    sed -i "s/<VirtualHost \*:80>/<VirtualHost \*:$PORT>/g" /etc/apache2/sites-available/000-default.conf
fi

# Ensure required storage subdirectories exist first (volume mount may be empty on first run)
mkdir -p /var/www/html/storage/app/public/avatars
mkdir -p /var/www/html/storage/app/public/teams
mkdir -p /var/www/html/storage/logs
mkdir -p /var/www/html/storage/framework/cache
mkdir -p /var/www/html/storage/framework/sessions
mkdir -p /var/www/html/storage/framework/views

# Fix storage permissions after mkdir
# chown may fail on GCS FUSE mounts (FUSE does not support chown) — that is expected
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true

# Optimize Laravel (jangan exit kalau gagal)
php artisan config:cache || true
php artisan route:cache || true
php artisan view:cache || true

# Migrate dengan retry (jangan pakai set -e di sini)
MAX_TRIES=5
COUNT=0
until php artisan migrate --force --no-interaction; do
    COUNT=$((COUNT+1))
    if [ $COUNT -ge $MAX_TRIES ]; then
        echo "Migration failed after $MAX_TRIES attempts, continuing anyway..."
        break
    fi
    echo "Migration attempt $COUNT failed, retrying in 3s..."
    sleep 3
done

# Remove stale public/storage directory so storage:link creates the symlink correctly
rm -rf /var/www/html/public/storage
php artisan storage:link

# Start Supervisor
exec /usr/bin/supervisord -n -c /etc/supervisor/conf.d/supervisord.conf