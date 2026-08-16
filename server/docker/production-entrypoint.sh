#!/bin/sh
set -eu

load_secret() {
    variable_name="$1"
    eval "secret_file=\${${variable_name}_FILE:-}"

    if [ -z "$secret_file" ]; then
        return
    fi

    if [ ! -r "$secret_file" ]; then
        echo "Required secret file for ${variable_name} is not readable." >&2
        exit 1
    fi

    secret_value="$(cat "$secret_file")"
    export "${variable_name}=${secret_value}"
    unset secret_value
}

for variable_name in \
    APP_KEY \
    DB_PASSWORD \
    REDIS_PASSWORD \
    MEILISEARCH_KEY \
    REVERB_APP_SECRET \
    LIVEKIT_API_KEY \
    LIVEKIT_API_SECRET \
    MAIL_PASSWORD
do
    load_secret "$variable_name"
done

wait_for_tcp() {
    service_name="$1"
    service_host="$2"
    service_port="$3"
    attempt=0

    while [ "$attempt" -lt 60 ]; do
        if php -r '$socket = @fsockopen($argv[1], (int) $argv[2], $errorCode, $errorMessage, 1); if ($socket === false) { exit(1); } fclose($socket);' "$service_host" "$service_port"; then
            return
        fi

        attempt=$((attempt + 1))
        sleep 1
    done

    echo "${service_name} did not become reachable before the production startup deadline." >&2
    exit 1
}

wait_for_tcp PostgreSQL "${DB_HOST:-postgres}" "${DB_PORT:-5432}"
wait_for_tcp Redis "${REDIS_HOST:-redis}" "${REDIS_PORT:-6379}"

if [ "$(id -u)" = "0" ]; then
    mkdir -p \
        bootstrap/cache \
        storage/app/private \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs
    chown -R www-data:www-data bootstrap/cache storage

    if [ "${1:-}" != "php-fpm" ]; then
        exec gosu www-data "$@"
    fi
fi

exec "$@"
