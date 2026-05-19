# Dockerfile — PHP 8.2 + Apache + PostgreSQL + Composer
# Place this file in the project ROOT
# Render reads this when render.yaml has:  env: docker

FROM php:8.2-apache

# ── 1. System packages needed to compile PHP extensions ──────────────────────
RUN apt-get update && apt-get install -y \
        libpq-dev \
        libzip-dev \
        zip \
        unzip \
        git \
        curl \
    && rm -rf /var/lib/apt/lists/*

# ── 2. PHP extensions the app needs ──────────────────────────────────────────
RUN docker-php-ext-install \
        pdo \
        pdo_pgsql \
        pgsql \
        zip

# ── 3. Composer (grabbed from official image) ─────────────────────────────────
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ── 4. Apache: enable mod_rewrite for .htaccess support ──────────────────────
RUN a2enmod rewrite \
    && sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# ── 5. Copy all project files into the image ──────────────────────────────────
WORKDIR /var/www/html
COPY . .

# ── 6. Install PHP dependencies (PHPMailer etc. from composer.json) ───────────
RUN composer install --no-dev --optimize-autoloader --no-interaction

# ── 7. Permissions ────────────────────────────────────────────────────────────
RUN mkdir -p /var/www/html/uploads/products \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 775 /var/www/html/uploads

# ── 8. Apache virtual host ────────────────────────────────────────────────────
# Render assigns $PORT at runtime (typically 10000).
# We write a template here; start.sh patches the real port value at startup.
RUN printf '<VirtualHost *:__PORT__>\n\
    DocumentRoot /var/www/html\n\
    <Directory /var/www/html>\n\
        Options -Indexes +FollowSymLinks\n\
        AllowOverride All\n\
        Require all granted\n\
    </Directory>\n\
    # Pass all env vars Render injects into PHP\n\
    PassEnv DATABASE_URL DB_HOST DB_PORT DB_NAME DB_USER DB_PASSWORD\n\
    PassEnv APP_URL APP_ENV APP_DEBUG\n\
    PassEnv SMTP_HOST SMTP_PORT SMTP_FROM_ADDR SMTP_FROM_NAME SMTP_USERNAME SMTP_PASSWORD\n\
    ErrorLog ${APACHE_LOG_DIR}/error.log\n\
    CustomLog ${APACHE_LOG_DIR}/access.log combined\n\
</VirtualHost>\n' > /etc/apache2/sites-available/000-default.conf

# ── 9. Startup script ─────────────────────────────────────────────────────────
COPY docker/start.sh /start.sh
RUN chmod +x /start.sh

# Render connects to this port — must match what Apache listens on
EXPOSE 10000

CMD ["/start.sh"]
