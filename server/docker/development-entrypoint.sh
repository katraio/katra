#!/bin/sh
set -eu

if [ ! -f .env ]; then
    cp .env.example .env
fi

if [ "${KATRA_SKIP_SETUP:-false}" != "true" ]; then
    composer install --no-interaction --prefer-dist

    if ! grep -Eq '^APP_KEY=.+$' .env; then
        php artisan key:generate --force
    fi

    php artisan migrate --graceful --force
fi

if [ "$#" -gt 0 ]; then
    exec "$@"
fi

# Run the development server directly so Docker-provided database, Redis, and
# session environment variables reach the HTTP worker unchanged.
cd public
exec php -S 0.0.0.0:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
