# ─────────────────────────────────────────────────────────────────────────────
# Dockerfile for Inventory System
# PHP 8.2 + Apache + PostgreSQL PDO + Composer + PHPMailer
# Compatible with Render.com (Docker deployment)
# ─────────────────────────────────────────────────────────────────────────────

FROM php:8.2-apache

# ── System dependencies ───────────────────────────────────────────────────────
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libzip-dev \
    zip \
    unzip \
    curl \
    git \
    && rm -rf /var/lib/apt/lists/*

# ── PHP extensions ────────────────────────────────────────────────────────────
RUN docker-php-ext-install \
    pdo \
    pdo_pgsql \
    pgsql \
    zip \
    && docker-php-ext-enable \
    pdo_pgsql \
    pgsql

# ── Install Composer ──────────────────────────────────────────────────────────
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ── Apache config: enable mod_rewrite, set document root ─────────────────────
RUN a2enmod rewrite

# Allow .htaccess overrides
RUN sed -i 's|AllowOverride None|AllowOverride All|g' /etc/apache2/apache2.conf

# ── Copy application files ────────────────────────────────────────────────────
WORKDIR /var/www/html
COPY . .

# ── Install PHP dependencies (PHPMailer etc.) ─────────────────────────────────
RUN composer install --no-dev --optimize-autoloader --no-interaction

# ── Fix permissions ───────────────────────────────────────────────────────────
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 775 /var/www/html/uploads

# ── Apache port config for Render.com ─────────────────────────────────────────
# Render sets $PORT dynamically; Apache must listen on it
COPY docker/apache-render.conf /etc/apache2/sites-available/000-default.conf

# Startup script: replace $PORT at runtime and start Apache
COPY docker/start.sh /start.sh
RUN chmod +x /start.sh

EXPOSE 10000

CMD ["/start.sh"]
