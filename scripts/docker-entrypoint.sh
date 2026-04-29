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

# OPTIONAL: tunggu mount GCS siap (biar lebih stabil)
sleep 2

# Ensure required storage subdirectories exist.
# GCS FUSE mount at storage/app/public may be owned by root; try to create
# subdirs anyway — fails silently if not writable.
mkdir -p /var/www/html/storage/app/public/avatars 2>/dev/null || true
mkdir -p /var/www/html/storage/app/public/teams 2>/dev/null || true
mkdir -p /var/www/html/storage/logs || true
mkdir -p /var/www/html/storage/framework/cache || true
mkdir -p /var/www/html/storage/framework/sessions || true
mkdir -p /var/www/html/storage/framework/views || true

# Fix permissions.
# chown/chmod on GCS FUSE silently fail — that is expected.
# The permanent fix is deploying with mount-options=uid=33:gid=33:file-mode=0664:dir-mode=0775
# (33 = www-data on Debian) so Apache workers can write to the GCS mount.
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true
chmod -R 777 /var/www/html/storage/app/public 2>/dev/null || true
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true

# Optimize Laravel (jangan exit kalau gagal)
php artisan config:cache || true
php artisan route:cache || true
php artisan view:cache || true

# Migrate dengan retry
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

# Remove stale public/storage directory (safe)
rm -rf /var/www/html/public/storage || true
php artisan storage:link || true

# Start Supervisor
exec /usr/bin/supervisord -n -c /etc/supervisor/conf.d/supervisord.conf