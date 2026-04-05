#!/bin/sh
set -e

# Force fix MPM at runtime
rm -f /etc/apache2/mods-enabled/mpm_*.load /etc/apache2/mods-enabled/mpm_*.conf
ln -sf /etc/apache2/mods-available/mpm_prefork.load /etc/apache2/mods-enabled/mpm_prefork.load
ln -sf /etc/apache2/mods-available/mpm_prefork.conf /etc/apache2/mods-enabled/mpm_prefork.conf

# Set Apache port
if [ -n "$PORT" ]; then
    sed -i "s/Listen 80/Listen $PORT/g" /etc/apache2/ports.conf
    sed -i "s/<VirtualHost \*:80>/<VirtualHost \*:$PORT>/g" /etc/apache2/sites-available/000-default.conf
fi

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

# Storage link (ignore error kalau sudah ada)
php artisan storage:link || true

# Start Supervisor
exec /usr/bin/supervisord -n -c /etc/supervisor/conf.d/supervisord.conf