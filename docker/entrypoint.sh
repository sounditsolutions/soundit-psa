#!/usr/bin/env sh
set -e

if [ "$(id -u)" = "0" ]; then
    mkdir -p \
        storage/app \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache
    chown -R www-data:www-data storage bootstrap/cache
fi

if [ -d /shared-public ]; then
    cp -a public/. /shared-public/
fi

exec docker-php-entrypoint "$@"
