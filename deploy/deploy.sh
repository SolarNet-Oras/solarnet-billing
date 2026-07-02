#!/usr/bin/env bash
# One-shot deploy / update script for Solarnet ISP Billing
# Run from /app/deploy on the production VPS

set -euo pipefail

cd "$(dirname "$0")"

if [ ! -f .env ]; then
  echo "ERROR: .env missing. Copy .env.production.example to .env and edit it first."
  exit 1
fi

COMPOSE="docker compose -f docker-compose.prod.yml --env-file .env"

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
  # Load our deploy .env values
  set -a; . ./.env; set +a
  cat > ../backend/.env <<EOF
APP_NAME="${APP_NAME:-Solarnet Internet}"
APP_ENV=production
APP_KEY=${APP_KEY}
APP_DEBUG=false
APP_URL=https://${DOMAIN}
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

CORS_ALLOWED_ORIGINS=https://${DOMAIN}

BCRYPT_ROUNDS=12
APP_MAINTENANCE_DRIVER=file
EOF
  echo "    Wrote ../backend/.env"
fi

echo "==> Ensuring writable Laravel storage & cache dirs"
mkdir -p ../backend/storage ../backend/bootstrap/cache
chmod -R 777 ../backend/storage ../backend/bootstrap/cache 2>/dev/null || true

echo "==> Installing composer dependencies (first run only, or if vendor/ missing)"
if [ ! -f ../backend/vendor/autoload.php ]; then
  $COMPOSE run --rm --no-deps backend composer install \
    --optimize-autoloader --no-dev --no-interaction --prefer-dist
fi

echo "==> Running database migrations"
$COMPOSE run --rm backend php artisan migrate --force

echo "==> Seeding (idempotent - safe to re-run)"
$COMPOSE run --rm backend php artisan db:seed --force || true

echo "==> Optimising Laravel (cache config/routes/views)"
$COMPOSE run --rm backend php artisan config:cache
$COMPOSE run --rm backend php artisan route:cache
$COMPOSE run --rm backend php artisan view:cache
$COMPOSE run --rm backend php artisan storage:link || true

echo "==> Starting all services"
$COMPOSE up -d

echo "==> Cleaning up dangling images"
docker image prune -f

echo ""
echo "Deploy complete. Live at: https://$(grep '^DOMAIN=' .env | cut -d= -f2)"
echo "Tail logs:  docker compose -f docker-compose.prod.yml logs -f"
