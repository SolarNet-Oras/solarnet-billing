#!/usr/bin/env bash
# One-shot deploy / update script for Solarnet ISP Billing
# Run from /app/deploy on the production VPS

set -euo pipefail

cd "$(dirname "$0")"

if [ ! -f .env ]; then
  echo "ERROR: .env missing. Copy .env.production.example to .env and edit it first."
  exit 1
fi

# Read only the three domain values during a normal update. This avoids
# re-evaluating deployment secrets in an already working production .env.
read_required_domain() {
  local key="$1" value
  value="$(sed -n "s/^${key}=//p" .env | tail -n 1)"
  if [ -z "$value" ]; then
    echo "ERROR: Set ${key} in deploy/.env"
    exit 1
  fi
  printf '%s' "$value"
}
CUSTOMER_DOMAIN="$(read_required_domain CUSTOMER_DOMAIN)"
ADMIN_DOMAIN="$(read_required_domain ADMIN_DOMAIN)"
LEGACY_ADMIN_DOMAIN="$(read_required_domain LEGACY_ADMIN_DOMAIN)"

COMPOSE="docker compose -f docker-compose.prod.yml --env-file .env"

echo "==> Validating Docker Compose and Caddy configuration"
$COMPOSE config --quiet
$COMPOSE run --rm --no-deps --entrypoint caddy caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile

echo "==> Pulling latest images"
$COMPOSE pull postgres redis caddy || true

echo "==> Building app images"
$COMPOSE build --pull

echo "==> Starting infrastructure (postgres, redis)"
$COMPOSE up -d postgres redis

echo "==> Waiting for postgres to be healthy"
until $COMPOSE ps postgres | grep -q "healthy"; do sleep 2; done

echo "==> Preparing backend .env file"
# Laravel needs a .env file inside the bind-mounted backend dir; generate from deploy .env
if [ ! -f ../backend/.env ] || [ "${FORCE_ENV:-0}" = "1" ]; then
  # First install only: load the existing deployment values used to generate
  # Laravel's initial environment file.
  set -a; . ./.env; set +a
  cat > ../backend/.env <<EOF
APP_NAME="${APP_NAME:-Solarnet Internet}"
APP_ENV=production
APP_KEY=${APP_KEY}
APP_DEBUG=false
APP_URL=https://${ADMIN_DOMAIN}
CUSTOMER_PORTAL_URL=https://${CUSTOMER_DOMAIN}
APP_TIMEZONE=${APP_TIMEZONE:-UTC}
APP_LOCALE=en
APP_FALLBACK_LOCALE=en

LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=error

DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=${DB_DATABASE}
DB_USERNAME=${DB_USERNAME}
DB_PASSWORD=${DB_PASSWORD}

REDIS_CLIENT=phpredis
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=null

CACHE_STORE=redis
CACHE_PREFIX=isp_cache
SESSION_DRIVER=redis
SESSION_LIFETIME=120
QUEUE_CONNECTION=redis
BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local

MAIL_MAILER=${MAIL_MAILER:-log}
MAIL_HOST=${MAIL_HOST:-}
MAIL_PORT=${MAIL_PORT:-587}
MAIL_USERNAME=${MAIL_USERNAME:-}
MAIL_PASSWORD=${MAIL_PASSWORD:-}
MAIL_FROM_ADDRESS=${MAIL_FROM_ADDRESS:-no-reply@example.com}
MAIL_FROM_NAME="${MAIL_FROM_NAME:-Solarnet Internet}"

JWT_SECRET=${JWT_SECRET}
JWT_TTL=${JWT_TTL:-60}
JWT_REFRESH_TTL=${JWT_REFRESH_TTL:-20160}
JWT_ALGO=${JWT_ALGO:-HS256}

CORS_ALLOWED_ORIGINS=https://${ADMIN_DOMAIN},https://${CUSTOMER_DOMAIN}
SANCTUM_STATEFUL_DOMAINS=${ADMIN_DOMAIN},${CUSTOMER_DOMAIN}
SESSION_DOMAIN=
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
MAIL_EHLO_DOMAIN=${ADMIN_DOMAIN}

BCRYPT_ROUNDS=12
APP_MAINTENANCE_DRIVER=file
EOF
  echo "    Wrote ../backend/.env"
fi

# Existing production installs already have backend/.env. Change only the
# hostname-related values; credentials, APP_KEY, JWT secrets, and database
# settings remain exactly as they were.
set_backend_env_value() {
  local key="$1" value="$2" file="../backend/.env"
  if grep -q "^${key}=" "$file"; then
    sed -i "s|^${key}=.*|${key}=${value}|" "$file"
  else
    printf '%s=%s\n' "$key" "$value" >> "$file"
  fi
}
set_backend_env_value APP_URL "https://${ADMIN_DOMAIN}"
set_backend_env_value CUSTOMER_PORTAL_URL "https://${CUSTOMER_DOMAIN}"
set_backend_env_value CORS_ALLOWED_ORIGINS "https://${ADMIN_DOMAIN},https://${CUSTOMER_DOMAIN}"
set_backend_env_value SANCTUM_STATEFUL_DOMAINS "${ADMIN_DOMAIN},${CUSTOMER_DOMAIN}"
set_backend_env_value SESSION_DOMAIN ""
set_backend_env_value SESSION_SECURE_COOKIE "true"
set_backend_env_value SESSION_SAME_SITE "lax"
set_backend_env_value MAIL_EHLO_DOMAIN "${ADMIN_DOMAIN}"

echo "==> Ensuring writable Laravel storage & cache dirs"
mkdir -p ../backend/storage/framework/views ../backend/bootstrap/cache
chmod -R 777 ../backend/storage ../backend/bootstrap/cache 2>/dev/null || true
# storage is a named Docker volume in production, so host-side directories are
# not visible at /var/www/storage inside PHP. Create the compiled-view path in
# that volume before config:view caching runs.
$COMPOSE run --rm --no-deps backend sh -lc 'mkdir -p storage/framework/views bootstrap/cache && chmod -R 777 storage bootstrap/cache'

echo "==> Ensuring PHP can read deployed application source"
# The PHP-FPM process runs as a non-root user. Keep source directories
# traversable and PHP/config/routes files readable after every git pull.
find ../backend/app ../backend/config ../backend/routes -type d -exec chmod 755 {} +
find ../backend/app ../backend/config ../backend/routes -type f -exec chmod 644 {} +

echo "==> Installing composer dependencies (first run only, or if vendor/ missing)"
if [ ! -f ../backend/vendor/autoload.php ]; then
  $COMPOSE run --rm --no-deps backend composer install \
    --optimize-autoloader --no-dev --no-interaction --prefer-dist
fi

if [ "${RUN_MIGRATIONS:-1}" = "1" ]; then
  echo "==> Running database migrations"
  $COMPOSE run --rm backend php artisan migrate --force
else
  echo "==> Skipping database migrations (RUN_MIGRATIONS=${RUN_MIGRATIONS})"
fi

if [ "${RUN_SEEDERS:-1}" = "1" ]; then
  echo "==> Seeding (idempotent - safe to re-run)"
  $COMPOSE run --rm backend php artisan db:seed --force || true
else
  echo "==> Skipping database seeders (RUN_SEEDERS=${RUN_SEEDERS})"
fi

echo "==> Optimising Laravel (cache config/routes/views)"
$COMPOSE run --rm backend php artisan config:cache
$COMPOSE run --rm backend php artisan route:cache
$COMPOSE run --rm backend php artisan view:cache 2>/dev/null || echo "    (no blade views to cache - OK for API-only apps)"
$COMPOSE run --rm backend php artisan storage:link || true

echo "==> Restarting PHP services to load the deployed source"
# The backend code is bind-mounted and production OPcache intentionally does
# not check file timestamps. Recreating these long-running processes is
# therefore required after every deploy; otherwise they can serve old PHP.
$COMPOSE up -d --force-recreate backend worker cron

echo "==> Starting updated web services"
# backend-nginx has no changed configuration and remains running. Frontend is
# rebuilt for same-origin API calls, while Caddy receives the new host/TLS map.
$COMPOSE up -d --force-recreate frontend caddy

echo "==> Cleaning up dangling images"
docker image prune -f

echo ""
echo "Deploy complete. Customer portal: https://${CUSTOMER_DOMAIN}"
echo "Deploy complete. Admin billing: https://${ADMIN_DOMAIN}"
echo "Tail logs:  docker compose -f docker-compose.prod.yml logs -f"
