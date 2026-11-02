# ============================================================================
# Project Time Tracker — PHP 8.3 + Apache (the database runs in its own
# container, see docker-compose.yml)
# ============================================================================
FROM php:8.3-apache

# PDO MySQL/MariaDB driver + OPcache, Apache modules
RUN docker-php-ext-install pdo_mysql \
 && docker-php-ext-enable opcache \
 && a2enmod rewrite headers remoteip \
 && a2dissite 000-default \
 && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/php.ini       /usr/local/etc/php/conf.d/zz-projecttracker.ini
COPY docker/apache.conf   /etc/apache2/sites-available/projecttracker.conf
COPY docker/entrypoint.sh /usr/local/bin/projecttracker-entrypoint
RUN a2ensite projecttracker \
 && chmod 0755 /usr/local/bin/projecttracker-entrypoint \
 && printf 'ServerTokens Prod\nServerSignature Off\nTraceEnable Off\n' > /etc/apache2/conf-enabled/zz-security.conf

# Application code. Only public/ is served by Apache; the rest (source code,
# HTML views, schema, tools) is not reachable from the web.
WORKDIR /var/www/projecttracker
COPY public/   public/
COPY src/      src/
COPY views/    views/
COPY database/ database/
COPY bin/      bin/

# The application container exposes port 80 (mapped by docker-compose)
EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
  CMD curl -fsS http://127.0.0.1/api/health || exit 1

# Waits for the database and creates/updates the tables, then starts Apache
ENTRYPOINT ["projecttracker-entrypoint"]
CMD ["apache2-foreground"]
