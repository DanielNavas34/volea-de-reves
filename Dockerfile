# Usamos la imagen oficial de PHP con Apache
FROM php:8.3-apache

# MySQL (pdo_mysql) y GD con JPEG/WebP para recodificar las fotos de perfil
RUN apt-get update \
    && apt-get install -y --no-install-recommends libjpeg62-turbo-dev libpng-dev libwebp-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install pdo pdo_mysql gd exif \
    && rm -rf /var/lib/apt/lists/*

# Habilitamos la reescritura de URLs de Apache (muy útil para proyectos web)
RUN a2enmod rewrite

# Damos permisos a la carpeta web
RUN chown -R www-data:www-data /var/www/html