FROM webdevops/php-nginx:8.3

ENV WEB_DOCUMENT_ROOT=/app/public \
    INSTALL=false

COPY . /app
WORKDIR /app

RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

RUN printf '%s\n' \
    '#!/bin/sh' \
    'set -e' \
    'if [ "$INSTALL" != "true" ] && [ ! -d /app/install.lock ]; then echo ok > /app/install.lock; fi' \
    'su application -s /bin/sh -c "php artisan queue:work --tries=3" >/tmp/work.log 2>&1 &' \
    'exec supervisord' > /app/start.sh \
    && chmod +x /app/start.sh

COPY conf/dujiaoka-nginx.conf /opt/docker/etc/nginx/vhost.common.d/20-dujiaoka-security.conf

RUN mkdir -p /app/storage /app/bootstrap/cache /app/public/uploads \
    && chown -R root:root /app \
    && chown -R application:application /app/storage /app/bootstrap/cache /app/public/uploads \
    && find /app -type d -exec chmod 0755 {} + \
    && find /app -type f -exec chmod 0644 {} + \
    && chmod +x /app/start.sh \
    && chmod -R 0775 /app/storage /app/bootstrap/cache /app/public/uploads

CMD [ "sh", "-c", "/app/start.sh" ]
