FROM php:8.3-fpm

ARG COMPOSER_INSTALL_ARGS="--no-interaction --prefer-dist --no-progress --optimize-autoloader"
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV COMPOSER_CACHE_DIR=/tmp/composer-cache
ENV DEBIAN_FRONTEND=noninteractive

WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libcurl4-openssl-dev \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libsqlite3-dev \
        libxml2-dev \
        libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        curl \
        gd \
        intl \
        mbstring \
        pcntl \
        pdo_mysql \
        pdo_sqlite \
        xml \
        zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY composer.json composer.lock ./
RUN composer install ${COMPOSER_INSTALL_ARGS} --no-scripts

COPY . .

RUN composer run-script post-autoload-dump \
    && mkdir -p \
        storage/app \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/soundit-docker-entrypoint
RUN chmod +x /usr/local/bin/soundit-docker-entrypoint

ENTRYPOINT ["soundit-docker-entrypoint"]
CMD ["php-fpm"]
