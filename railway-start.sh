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

if [ "${DB_CONNECTION}" = "mysql" ] && [ -n "$DB_HOST" ]; then
  echo "Waiting for MySQL at ${DB_HOST}:${DB_PORT}..."
  i=0
  while [ "$i" -lt 60 ]; do
    if php -r "try { new PDO('mysql:host='.getenv('DB_HOST').';port='.(getenv('DB_PORT') ?: '3306'), getenv('DB_USERNAME'), getenv('DB_PASSWORD')); exit(0); } catch (Throwable \$e) { exit(1); }"; then
      break
    fi
    i=$((i + 1))
    sleep 2
  done
  php artisan migrate --force --no-interaction
else
  echo "Skipping migrate (DB_CONNECTION=${DB_CONNECTION:-unset}; no MYSQLHOST)."
fi

exec php artisan serve --host=0.0.0.0 --port="${PORT:-8000}"
