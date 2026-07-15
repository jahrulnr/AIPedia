FROM php:8.1-fpm-alpine

SHELL ["/bin/ash", "-o", "pipefail", "-c"]

RUN apk add --no-cache \
        mysql-client \
        curl \
        nginx \
        supervisor \
        inetutils-telnet \
        tzdata \
    && apk --no-cache upgrade

RUN docker-php-ext-install pdo_mysql mysqli

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    PATH=${PATH}:/app

COPY infra/supervisord.conf /etc/supervisord.conf
COPY infra/nginx/default.conf /etc/nginx/http.d/default.conf
COPY infra/nginx/nginx.conf /etc/nginx/nginx.conf
COPY infra/php/php.ini /usr/local/etc/php/php.ini
COPY infra/php/www.conf /usr/local/etc/php-fpm.d/www.conf
COPY infra/start.sh /start.sh
RUN chmod +x /start.sh

COPY web/ /app

WORKDIR /app

RUN mkdir -p /run/php && chown -R www-data:www-data /run/php /app/storage /app/bootstrap/cache

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && composer install --no-dev --no-cache --no-interaction --optimize-autoloader \
    && php artisan key:generate --force \
    && php artisan storage:link --force \
    || true

EXPOSE 8080

CMD [ "/start.sh" ]
