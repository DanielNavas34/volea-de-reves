# Usamos la imagen oficial de PHP con Apache
FROM php:8.2-apache

# Instalamos las extensiones necesarias para conectar con MySQL
RUN docker-php-ext-install pdo pdo_mysql

# Habilitamos la reescritura de URLs de Apache (muy útil para proyectos web)
RUN a2enmod rewrite

# Damos permisos a la carpeta web
RUN chown -R www-data:www-data /var/www/html