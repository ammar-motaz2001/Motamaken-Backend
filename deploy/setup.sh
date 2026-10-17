#!/bin/sh
# First-time setup on the server: writes the secret files with fresh random values. Safe to re-run (keeps existing files).
set -e
cd "$(dirname "$0")"

rand() { openssl rand -hex "${1:-24}"; }

if [ ! -f .env ]; then
  DB_PASSWORD=$(rand 24)
  cat > .env <<ENV
PUBLIC_API_URL=https://api.mutamakin.com/api
MOYASAR_PUBLISHABLE_KEY=
RECAPTCHA_SITE_KEY=
DB_DATABASE=mutamakin
DB_USERNAME=mutamakin
DB_PASSWORD=${DB_PASSWORD}
ENV
fi
. ./.env

if [ ! -f backend.env ]; then
  PROXY_SECRET=$(rand 32)
  cat > backend.env <<ENV
APP_NAME=Mutamakin
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:$(openssl rand -base64 32)
APP_URL=https://api.mutamakin.com
APP_LOCALE=en
LOG_CHANNEL=stderr
LOG_LEVEL=warning

DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=${DB_DATABASE}
DB_USERNAME=${DB_USERNAME}
DB_PASSWORD=${DB_PASSWORD}

REDIS_CLIENT=phpredis
REDIS_HOST=redis
REDIS_PORT=6379
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
FILESYSTEM_DISK=local

SESSION_SECURE_COOKIE=true

FRONTEND_URL=https://mutamakin.com
CORS_ALLOWED_ORIGINS=https://mutamakin.com,https://www.mutamakin.com
CORS_ALLOWED_ORIGIN_PATTERNS=
FRONTEND_PROXY_SECRET=${PROXY_SECRET}

# Until SMTP details are set, emails (sign-up codes) are only written to the log.
MAIL_MAILER=log
MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=no-reply@mutamakin.com
MAIL_FROM_NAME=Mutamakin

PAYMENTS_DRIVER=moyasar
MOYASAR_SECRET_KEY=
MOYASAR_WEBHOOK_SECRET=$(rand 24)
PAYMENTS_WEBHOOK_SECRET=$(rand 24)

RECAPTCHA_SITE_KEY=
RECAPTCHA_SECRET_KEY=
ENV
fi

if [ ! -f frontend.env ]; then
  cat > frontend.env <<ENV
API_URL=http://api:10000/api
API_PROXY_SECRET=$(grep ^FRONTEND_PROXY_SECRET= backend.env | cut -d= -f2)
ENV
fi

chmod 600 .env backend.env frontend.env
echo "Secret files ready in $(pwd). Fill MOYASAR_* and MAIL_* if needed, then: docker compose up -d --build"
