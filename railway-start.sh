#!/bin/sh
set -e

if [ -n "$MYSQLHOST" ]; then
  export DB_CONNECTION="${DB_CONNECTION:-mysql}"
  export DB_HOST="$MYSQLHOST"
  export DB_PORT="${MYSQLPORT:-3306}"
  export DB_DATABASE="$MYSQLDATABASE"
  export DB_USERNAME="$MYSQLUSER"
  export DB_PASSWORD="$MYSQLPASSWORD"
fi

mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
php artisan storage:link --force >/dev/null 2>&1 || true
php artisan migrate --force --no-interaction

exec php artisan serve --host=0.0.0.0 --port="${PORT:-8000}"
