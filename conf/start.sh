#!/bin/sh
set -e

cd /app

if [ ! -f "/app/.env" ]; then
    echo ".env file is missing. Mount it to /app/.env first."
    exit 1
fi

mkdir -p /run/nginx /app/storage /app/bootstrap/cache /app/public/uploads

if [ ! -d "/app/storage/app" ] && [ -d "/app/storage_bak" ]; then
    cp -a /app/storage_bak/. /app/storage/
fi

mkdir -p \
    /app/storage/app \
    /app/storage/framework/cache \
    /app/storage/framework/sessions \
    /app/storage/framework/views \
    /app/storage/logs \
    /app/bootstrap/cache \
    /app/public/uploads

chown -R application:application /app/storage /app/bootstrap/cache /app/public/uploads

if [ "$INSTALL" != "true" ]; then
    echo "ok" > /app/install.lock
fi

/app/start-hook.sh

php artisan clear-compiled

exec supervisord -c /etc/supervisor/conf.d/supervisord.conf
