FROM php:8.2-apache

# Install PHP extensions needed for MySQL
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Install zip/unzip for file management features
RUN apt-get update && apt-get install -y zip unzip default-mysql-client cron && rm -rf /var/lib/apt/lists/*

# Set working directory
WORKDIR /var/www/html

# Create backups directory with proper permissions
RUN mkdir -p /var/www/backups && chown www-data:www-data /var/www/backups

# Redirect Apache logs to real files instead of /dev/stdout (which hangs in Docker)
RUN rm -f /var/log/apache2/access.log /var/log/apache2/error.log /var/log/apache2/other_vhosts_access.log \
    && touch /var/log/apache2/access.log /var/log/apache2/error.log \
    && chown www-data:www-data /var/log/apache2/access.log /var/log/apache2/error.log

# Configure PHP: error logging and upload limits
RUN echo "log_errors = On" >> /usr/local/etc/php/php.ini \
    && echo "error_log = /var/log/php_errors.log" >> /usr/local/etc/php/php.ini \
    && echo "upload_max_filesize = 512M" >> /usr/local/etc/php/php.ini \
    && echo "post_max_size = 512M" >> /usr/local/etc/php/php.ini \
    && echo "memory_limit = 256M" >> /usr/local/etc/php/php.ini \
    && touch /var/log/php_errors.log \
    && chown www-data:www-data /var/log/php_errors.log

# Create log files for backup and cron
RUN touch /var/log/backup_cron.log /var/log/cron.log \
    && chown www-data:www-data /var/log/backup_cron.log \
    && chmod 666 /var/log/cron.log

# Allow .htaccess overrides
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf


# Copy startup script
COPY start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

CMD ["start.sh"]
