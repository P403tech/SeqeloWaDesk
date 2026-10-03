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

mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache storage/app/training
php artisan storage:link --force >/dev/null 2>&1 || true
php artisan view:clear >/dev/null 2>&1 || true

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
  php artisan seqelo:use-shipped-brand --no-interaction || true
  php -r '
    require "vendor/autoload.php";
    $app = require "bootstrap/app.php";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $ok = function ($label, $yes) { echo $label.": ".($yes ? "yes" : "MISSING")."\n"; };
    $ok("col ai_agents.knowledge_assistant_id", Illuminate\Support\Facades\Schema::hasColumn("ai_agents", "knowledge_assistant_id"));
    $ok("col ai_agents.shop_router", Illuminate\Support\Facades\Schema::hasColumn("ai_agents", "shop_router"));
    $ok("table flow_retry_logs", Illuminate\Support\Facades\Schema::hasTable("flow_retry_logs"));
  ' || true
  # Ephemeral disks lose storage/installed on every deploy. If MySQL already
  # has users, restore the marker so EnsureInstalled never bounces to /install.
  php -r '
    require "vendor/autoload.php";
    $app = require "bootstrap/app.php";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    try {
      if (Illuminate\Support\Facades\Schema::hasTable("users") && Illuminate\Support\Facades\DB::table("users")->exists()) {
        App\Support\InstallState::forgetMemo();
        App\Support\InstallState::markInstalled();
        echo "Install marker restored (live users table).\n";
      }
    } catch (Throwable $e) {
      echo "Install marker skip: ".$e->getMessage()."\n";
    }
  ' || true
  # Gallery templates live in MySQL (not the image). Re-seed on boot so
  # /flows "Start from a template" is never empty after a deploy.
  php artisan db:seed --class=Database\\Seeders\\FlowTemplateSeeder --force --no-interaction || true
  php artisan db:seed --class=Database\\Seeders\\MetaPricingChange2026Seeder --force --no-interaction || true
else
  echo "Skipping migrate (DB_CONNECTION=${DB_CONNECTION:-unset}; no MYSQLHOST)."
fi

chown -R www-data:www-data storage bootstrap/cache || true

port="${PORT:-8080}"
sed "s/__PORT__/${port}/" docker/nginx.railway.conf > /tmp/nginx-railway.conf

php-fpm -F &
fpm_pid=$!
nginx -c /tmp/nginx-railway.conf -g "daemon off;" &
nginx_pid=$!
# Railway has no OS cron. This runs the every-minute schedule, including
# the stall rescue for campaigns and flows when advanced scaling is on.
php artisan schedule:work &
sched_pid=$!

while kill -0 "$fpm_pid" 2>/dev/null && kill -0 "$nginx_pid" 2>/dev/null; do
  if ! kill -0 "$sched_pid" 2>/dev/null; then
    php artisan schedule:work &
    sched_pid=$!
  fi
  sleep 2
done

kill "$fpm_pid" "$nginx_pid" "$sched_pid" 2>/dev/null || true
wait "$fpm_pid" 2>/dev/null || true
wait "$nginx_pid" 2>/dev/null || true
exit 1
