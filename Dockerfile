FROM php:8.4-apache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libsqlite3-dev libicu-dev \
    && docker-php-ext-install pdo_mysql pdo_sqlite intl \
    && a2enmod rewrite headers \
    && echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername \
    && sed -ri 's!/var/www/html!/var/www/html/htdocs!g' /etc/apache2/sites-available/000-default.conf \
    && sed -ri 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

# Mark the host bind-mounted working tree as safe so git invocations inside
# the container do not print `dubious ownership` warnings for every command
# when the host uid differs from the container's root uid.
RUN git config --global --add safe.directory /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-progress

COPY . .
RUN ./init.sh
