#!/bin/ash

cd /app

if [ ! -f .env ]; then
    cp .env.example .env
    php artisan key:generate --force
fi

php artisan storage:link --force 2>/dev/null || true

# Build the docs index only on first boot (or after it was explicitly removed).
# Keeping an existing index avoids unnecessary work and prevents every restart
# from putting the docs index back into a temporary "building" state.
if [ ! -f storage/app/webchat/docs_index.json ]; then
    echo "Docs index missing; building it before starting services."
    php artisan webchat:reindex-docs --sync
fi

supervisord -n -c /etc/supervisord.conf
