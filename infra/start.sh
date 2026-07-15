#!/bin/ash

cd /app

if [ ! -f .env ]; then
    cp .env.example .env
    php artisan key:generate --force
fi

php artisan storage:link --force 2>/dev/null || true

supervisord -n -c /etc/supervisord.conf
