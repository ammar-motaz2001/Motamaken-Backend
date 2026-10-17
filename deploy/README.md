# Deploying on the VPS

Everything (website, API, worker, scheduler, Postgres, Redis, Caddy) runs with Docker Compose on one server.
Cloudflare proxies `mutamakin.com`, `api.mutamakin.com` and `admin.mutamakin.com` to it; set Cloudflare SSL/TLS to **Full**.

## First time

```sh
mkdir -p /opt/mutamakin && cd /opt/mutamakin
git clone https://github.com/ammar-motaz2001/Motamaken-Backend.git backend
git clone https://github.com/ammar-motaz2001/Motamaken-Front.git frontend
cd backend/deploy && ./setup.sh            # writes .env, backend.env, frontend.env with random secrets
nano .env backend.env                      # Moyasar keys, SMTP (MAIL_*), reCAPTCHA
docker compose up -d --build
docker compose exec api php artisan db:seed --class=ReferenceDataSeeder --force
docker compose exec api php artisan db:seed --class=PagesSeeder --force
docker compose exec api php artisan admin:create you@example.com --password='Choose#AStrong1'
```

## Update to the latest code

```sh
cd /opt/mutamakin/backend && git pull && cd ../frontend && git pull
cd ../backend/deploy && docker compose up -d --build
```

Migrations run automatically when the API starts. Changing a `NEXT_PUBLIC_*` value (in `.env`) needs `--build`.
A changed `Caddyfile` needs `docker compose up -d --force-recreate caddy` (a plain reload keeps the old file).

## Security notes

- Caddy only accepts connections from Cloudflare's IP ranges (see `Caddyfile`); browsing the server's IP directly is
  refused. If Cloudflare publishes new ranges (https://www.cloudflare.com/ips/), update both lists in the Caddyfile.
- Postgres and Redis have no published ports; only Caddy (80/443) is reachable.
- Secrets are in `.env`, `backend.env` and `frontend.env` here (mode 600, never in git).

## Useful

- Logs: `docker compose logs -f api` (also `web`, `queue`, `caddy`)
- Database shell: `docker compose exec postgres psql -U mutamakin mutamakin`
- Backup: `docker compose exec postgres pg_dump -U mutamakin mutamakin | gzip > backup-$(date +%F).sql.gz`
- Moyasar webhook: `https://api.mutamakin.com/api/webhooks/moyasar` with `MOYASAR_WEBHOOK_SECRET` from `backend.env` as its secret token.
