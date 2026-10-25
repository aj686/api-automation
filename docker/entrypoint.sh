#!/bin/sh
# Shared by the app and worker containers (docker-compose.yml).
set -e

# The app's .env is a symlink into the storage volume (see Dockerfile), so
# APP_KEY and AUTOMATION_API_TOKEN survive rebuilds and every process sees
# them, `docker compose exec` included. The app container creates it on first
# start; the worker waits for it. Both must use the same key, or the worker
# cannot read the secrets the web side encrypted.
env_file=storage/app/private/.env

if [ "$1" = "php-fpm" ]; then
    if [ ! -f "$env_file" ]; then
        (umask 077 && printf 'APP_KEY=\nAUTOMATION_API_TOKEN=\n' > "$env_file")
    fi
    if ! grep -q '^APP_KEY=.' "$env_file"; then
        php artisan key:generate --force
    fi
    php artisan migrate --force
else
    # Wait for the key and the app container's migrations (fresh install or
    # upgrade), so the worker's startup recovery never queries a missing table.
    # --pending=1, not --pending: only a value makes it exit 1 while pending.
    tries=0
    until grep -qs '^APP_KEY=.' "$env_file" && php artisan migrate:status --pending=1 > /dev/null 2>&1; do
        tries=$((tries + 1))
        if [ "$tries" -gt 120 ]; then
            echo "Not ready after 240 s (APP_KEY or migrations): check the app service's log." >&2
            exit 1
        fi
        sleep 2
    done
fi

exec "$@"
