#!/bin/ash

cd /app

if [ ! -f .env ]; then
    cp .env.example .env
    php artisan key:generate --force
fi

php artisan storage:link --force 2>/dev/null || true

# One-shot docs reindex on container boot (queued; workers pick it up after supervisord starts).
# No scheduled/watch reindex after this — only restarts re-queue.
php artisan webchat:reindex-docs 2>/dev/null || true

supervisord -n -c /etc/supervisord.conf
