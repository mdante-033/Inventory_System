# Dockerfile — PHP 8.2 + Apache + PostgreSQL + Composer
# Fixed: mod_env enabled, all env vars passed to PHP via Apache

FROM php:8.2-apache

# ── 1. System packages ────────────────────────────────────────────────────────
RUN apt-get update && apt-get install -y \
        libpq-dev \
        libzip-dev \
        zip \
        unzip \
        git \
        curl \
    && rm -rf /var/lib/apt/lists/*

# ── 2. PHP extensions ─────────────────────────────────────────────────────────
RUN docker-php-ext-install \
        pdo \
        pdo_pgsql \
        pgsql \
        zip

# ── 3. Composer ───────────────────────────────────────────────────────────────
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ── 4. Apache modules: rewrite + env (env needed for PassEnv to work) ─────────
RUN a2enmod rewrite env \
    && sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# ── 5. App files ──────────────────────────────────────────────────────────────
WORKDIR /var/www/html
COPY . .

# ── 6. PHP dependencies ───────────────────────────────────────────────────────
RUN composer install --no-dev --optimize-autoloader --no-interaction

# ── 7. Permissions ────────────────────────────────────────────────────────────
RUN mkdir -p /var/www/html/uploads/products \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 775 /var/www/html/uploads

# ── 8. PHP ini: make getenv() work under Apache + FPM ────────────────────────
# variables_order must include E (environment) so $_ENV and getenv() work
RUN echo "variables_order = EGPCS" >> /usr/local/etc/php/conf.d/render.ini \
    && echo "variables_order = EGPCS" >> /usr/local/etc/php/php.ini || true

# ── 9. Apache virtual host — port patched at runtime by start script ──────────
RUN printf '<VirtualHost *:__PORT__>\n\
    DocumentRoot /var/www/html\n\
    <Directory /var/www/html>\n\
        Options -Indexes +FollowSymLinks\n\
        AllowOverride All\n\
        Require all granted\n\
    </Directory>\n\
    # Pass Render environment variables into PHP\n\
    PassEnv DATABASE_URL\n\
    PassEnv DB_HOST DB_PORT DB_NAME DB_USER DB_PASSWORD\n\
    PassEnv APP_URL APP_ENV APP_DEBUG\n\
    PassEnv SMTP_HOST SMTP_PORT SMTP_FROM_ADDR SMTP_FROM_NAME SMTP_USERNAME SMTP_PASSWORD\n\
    ErrorLog ${APACHE_LOG_DIR}/error.log\n\
    CustomLog ${APACHE_LOG_DIR}/access.log combined\n\
</VirtualHost>\n' > /etc/apache2/sites-available/000-default.conf

# ── 10. Inline startup script — patches Apache port at container start ─────────
RUN printf '#!/bin/bash\n\
set -e\n\
PORT="${PORT:-10000}"\n\
echo "==> Apache starting on port ${PORT}"\n\
sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf\n\
sed -i "s/__PORT__/${PORT}/" /etc/apache2/sites-available/000-default.conf\n\
exec apache2-foreground\n' > /start.sh \
    && chmod +x /start.sh

EXPOSE 10000

CMD ["/start.sh"]
