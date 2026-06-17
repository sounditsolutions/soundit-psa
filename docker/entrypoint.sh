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
    rm -f /shared-public/.soundit-public-ready
    cp -af public/. /shared-public/
    touch /shared-public/.soundit-public-ready
fi

exec docker-php-entrypoint "$@"
