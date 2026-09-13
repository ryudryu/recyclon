FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo_pgsql pgsql \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY . /var/www/html/

RUN mkdir -p /var/www/html/storage/sessions \
    && chown -R www-data:www-data /var/www/html/storage \
    && chmod 700 /var/www/html/storage/sessions

ENV APACHE_DOCUMENT_ROOT=/var/www/html
ENV DB_DRIVER=pgsql
ENV ENFORCE_HTTPS=true

EXPOSE 80

CMD ["apache2-foreground"]