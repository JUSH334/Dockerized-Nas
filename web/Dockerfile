FROM php:8.2-apache

# Install PHP extensions for MySQL
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Enable Apache modules
RUN a2enmod rewrite headers

# Set working directory
WORKDIR /var/www/html

# Create uploads directory with proper permissions
RUN mkdir -p /var/www/uploads && chown www-data:www-data /var/www/uploads

EXPOSE 80
