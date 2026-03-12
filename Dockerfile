FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    git curl zip unzip \
    libpng-dev libonig-dev libxml2-dev libzip-dev libpq-dev \
 && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install pdo_mysql pdo_pgsql pgsql mbstring exif pcntl bcmath gd zip

# Fix MPM conflict
RUN a2dismod mpm_event mpm_worker || true \
 && a2enmod mpm_prefork \
 && a2enmod rewrite

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

RUN composer install --no-dev --optimize-autoloader

RUN chown -R www-data:www-data storage bootstrap/cache

# Apache virtual host config
RUN echo '<VirtualHost *:80>\n\
    DocumentRoot /var/www/html/public\n\
    <Directory /var/www/html/public>\n\
        AllowOverride All\n\
        Require all granted\n\
    </Directory>\n\
</VirtualHost>' > /etc/apache2/sites-available/000-default.conf

COPY scripts/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80

ENTRYPOINT ["docker-entrypoint.sh"]