FROM php:8.3-apache

RUN docker-php-ext-install pdo_mysql
RUN a2dismod mpm_event mpm_worker 2>/dev/null || true
RUN a2enmod mpm_prefork headers rewrite

WORKDIR /var/www/html

COPY . .

RUN sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf \
    && printf '\nServerName localhost\n' >> /etc/apache2/apache2.conf

COPY docker/railway-entrypoint.sh /usr/local/bin/railway-entrypoint
RUN chmod +x /usr/local/bin/railway-entrypoint

CMD ["railway-entrypoint"]
