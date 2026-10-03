#!/bin/sh
# Release step. Railway runs this before the new container takes traffic.
# A failed migration stops the deploy and leaves the previous container up.
set -e

if [ -n "$MYSQLHOST" ]; then
  export DB_CONNECTION="${DB_CONNECTION:-mysql}"
  export DB_HOST="$MYSQLHOST"
  export DB_PORT="${MYSQLPORT:-3306}"
  export DB_DATABASE="$MYSQLDATABASE"
  export DB_USERNAME="$MYSQLUSER"
  export DB_PASSWORD="$MYSQLPASSWORD"
fi

if [ "${DB_CONNECTION}" != "mysql" ] || [ -z "$DB_HOST" ]; then
  echo "Skipping migrate (DB_CONNECTION=${DB_CONNECTION:-unset}; no database host)."
  exit 0
fi

echo "Waiting for MySQL at ${DB_HOST}:${DB_PORT}..."
i=0
while [ "$i" -lt 60 ]; do
  if php -r "try { new PDO('mysql:host='.getenv('DB_HOST').';port='.(getenv('DB_PORT') ?: '3306'), getenv('DB_USERNAME'), getenv('DB_PASSWORD')); exit(0); } catch (Throwable \$e) { exit(1); }"; then
    break
  fi
  i=$((i + 1))
  sleep 2
done

if [ "$i" -ge 60 ]; then
  echo "MySQL did not become reachable."
  exit 1
fi

php artisan migrate --force --no-interaction
