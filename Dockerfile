FROM php:8.2-apache

# Apache mod rewrite enable
RUN a2enmod rewrite

# PHP extensions
RUN docker-php-ext-install mysqli

# Set working directory
WORKDIR /var/www/html

# Copy all files
COPY . /var/www/html/

# Permissions (VERY IMPORTANT)
RUN chown -R www-data:www-data /var/www/html \
 && chmod -R 777 /var/www/html/data \
 && chmod 666 /var/www/html/users.json \
 && chmod 666 /var/www/html/error.log

EXPOSE 80
