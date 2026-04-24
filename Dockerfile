FROM php:8.2-apache
RUN docker-php-ext-install pdo pdo_mysql
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html
COPY start.sh /start.sh
RUN chmod +x /start.sh
CMD ["/start.sh"]
