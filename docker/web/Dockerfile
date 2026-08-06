# 既定はローカル・CIで固定しているPHP 8.2.30。PHP 8.5互換確認環境
# （docker-compose.check-php85.yml、Issue #215）は、このDockerfileを
# 変更せずビルド時に PHP_IMAGE だけ上書きして再利用する。
# FROMで参照するARGはファイル先頭（最初のFROMより前）で宣言する必要がある。
ARG PHP_IMAGE=php:8.2.30-apache

FROM composer:2.8.12 AS composer

FROM ${PHP_IMAGE}

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

RUN apt-get update && apt-get install -y git zip unzip libzip-dev

COPY php-base.ini $PHP_INI_DIR/conf.d/

COPY httpd-base.conf /etc/apache2/sites-available/laravel.conf
RUN a2dissite 000-default.conf && a2ensite laravel.conf && a2enmod rewrite

RUN pecl install xdebug && docker-php-ext-enable xdebug

RUN apt-get install -y zlib1g-dev libpq-dev libicu-dev libfreetype6-dev libjpeg62-turbo-dev libpng-dev

RUN docker-php-ext-install pdo_mysql intl gd zip && docker-php-ext-configure gd
