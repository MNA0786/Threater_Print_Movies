# Dockerfile
FROM php:8.2-apache

# System dependencies install karo
RUN apt-get update && apt-get install -y \
    curl \
    git \
    zip \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# Apache configuration
RUN a2enmod rewrite headers
COPY .htaccess /var/www/html/.htaccess

# Working directory
WORKDIR /var/www/html

# Files copy karo
COPY . .

# File permissions set karo
RUN chmod 666 users.json && \
    chmod 666 movies.csv && \
    chmod 666 bot_stats.json && \
    chmod 777 backups/ && \
    chmod 666 error.log

# Expose port
EXPOSE 80

# Start Apache
CMD ["apache2-foreground"]
