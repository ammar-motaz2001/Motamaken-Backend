# Mutamakin architecture

How the platform fits together, for developers taking it over. Feature-level API notes are in the
[backend README](../README.md), front-end conventions in the website's README, and server operations in
[deploy/README.md](../deploy/README.md).

## The pieces

```
Browser ──HTTPS──▶ Cloudflare (DNS, TLS edge, WAF/DDoS) ──HTTPS──▶ VPS: Caddy (accepts Cloudflare IPs only)
                                                                    │
                    mutamakin.com / admin.mutamakin.com ────────────┼──▶ web   Next.js 16 (server rendering)
                    api.mutamakin.com ──────────────────────────────┴──▶ api   Laravel 13 on FrankenPHP
                                                                              │
                     web ──server-side calls on the Docker network (http://api:10000/api)──▶ api
                     api ──▶ postgres 16 (+pgvector)   api ──▶ redis 7 (cache, sessions, queues, OTP codes)
                     queue    (php artisan queue:work)    : emails, WhatsApp, broadcasts, AI screening
                     scheduler (php artisan schedule:work): invitations, renewals, prize draws, campaign closing
External: Moyasar (cards), Resend SMTP (email), WhatsApp provider, Google reCAPTCHA v3, optional Ollama (AI).
```

Two repositories:

| Repo | Contents |
|------|----------|
| `Motamaken-Backend` | Laravel API, admin API, queue jobs, scheduler, `deploy/` (Compose, Caddyfile, Postgres image) |
| `Motamaken-Front`   | Next.js website and admin panel (one app; the admin panel lives under `/admin`) |

## Backend layers (`app/`)

A request passes through these layers, and each layer has one job:

| Layer | Folder | Job |
|-------|--------|-----|
| Routes | `routes/api.php` | URL → controller; auth (`auth:sanctum`), rate limits (`throttle:*`), admin permissions (`admin.can:*`) |
| Middleware | `Http/Middleware` | Security headers, maintenance mode, admin audit log, visitor IP from the website's proxy |
| Form requests | `Http/Requests` | Input validation and authorisation; controllers only see valid data |
| Controllers | `Http/Controllers` (`Admin/` for staff) | Thin: load models, call a service, return a resource |
| Services | `Services` | Business rules: escrow, disputes, proposals, teams, rewards, campaigns, subscriptions, support |
| Payments | `Services/Payments` | Ledger, wallets, deposits (Moyasar), withdrawals, escrow money, fees |
| Models | `Models` | Eloquent models; relations and small helpers only |
| Resources | `Http/Resources` | JSON shape returned to clients (camelCase) |
| Async | `Jobs`, `Notifications`, `Channels`, `Observers`, `Listeners` | Email/WhatsApp/database notifications, broadcasts, AI screening |
| Scheduled | `Console/Commands`, `routes/console.php` | Recurring work run by the `scheduler` container |
| Support | `Support`, `Rules`, `Policies`, `Enums` | Cross-database SQL helpers, custom validation (reCAPTCHA), policies |

### Money: a double-entry ledger

All balances come from `ledger_entries`; nothing stores a balance number that could drift.

- `LedgerService::move()` is the only code that changes money. A movement is a set of legs that add up to zero,
  written in one database transaction. Accounts are locked in id order, so two movements cannot deadlock or
  double-spend, and an idempotency key makes retries (e.g. a repeated webhook) harmless.
- Accounts: one wallet per user and currency, plus platform accounts (escrow, revenue, gateway, ...).
- Amounts are integer cents; `FeeCalculator` rounds so no cent is created or lost (covered by tests).
- Card deposits: the browser tokenises the card with Moyasar, the API creates the payment, and the deposit is only
  credited after the API confirms it with Moyasar (by asking Moyasar, or from the webhook carrying the shared
  secret), never from what the browser says.
- Escrow: the client funds milestones (wallet → escrow), the freelancer delivers, and approval releases the money
  (escrow → freelancer minus the platform fee). Disputes and appeals move money only through the same ledger.
- Changing the deal: either side can ask to change the price or delivery time, or to cancel (`AgreementService`);
  the price is fixed once money is in escrow, and an accepted cancellation refunds what is held to the client.

### Auth and accounts

- Sanctum bearer tokens. The website stores the token in an http-only cookie and calls the API through its own
  server or the `/api/backend` proxy, so browser JavaScript never reads the token.
- Sign-up and login by email or phone with a one-time code (`OtpService`, codes in Redis); optional two-factor.
- Passwords are hashed with bcrypt (cost 12). reCAPTCHA v3 protects sign-up, quick start and the contact form.
- Staff are users with `is_admin`; `admin_roles` grant permissions (`config/admin.php`) checked by `admin.can:*`.
  Every admin write is recorded in `admin_logs` by the `AuditAdminActions` middleware.

### Settings that admins change

The `settings` table holds admin-edited values (fees, mail, maintenance, site details). `RuntimeConfig` lays them
over `config()` when the app boots and before each queued job, so code reads plain `config()` values.

## Website (`src/`)

| Folder | Job |
|--------|-----|
| `app/[locale]/` | Routes (App Router). Every page is a server component that loads its data from the API |
| `modules/<feature>/` | Feature code: components, client-side helpers and types per area (auth, projects, escrow, wallet, admin, ...) |
| `components/` | Shared UI: header, footer, form fields, modals, providers |
| `lib/api/` | `server.ts` (server-side calls with the session token), `client.ts` (browser calls via the proxy), shared types |
| `i18n/`, `messages/` | English and Arabic (RTL) texts via next-intl; the admin texts are only sent to the admin panel |
| `proxy.ts` | Locale routing, protected pages, guest-only pages |

Public reference data (categories, countries, currencies, site settings) is cached for 60 seconds in the Next.js
data cache; everything that depends on the user is fetched per request.

## Production

One VPS runs everything with Docker Compose (`deploy/docker-compose.yml`): `caddy`, `web`, `api`, `queue`,
`scheduler`, `postgres`, `redis`. Only Caddy publishes ports (80/443), and it only answers Cloudflare's IP ranges;
plain HTTP is redirected to HTTPS. Secrets live in `deploy/.env`, `backend.env` and `frontend.env` (mode 600, not in
git). Migrations run when the API container starts. Uploads, the database and Redis use named volumes.

Deploying, logs, backups and the Moyasar webhook are described in [deploy/README.md](../deploy/README.md).

## Quality gates

Run before every merge:

| Repo | Command | Expectation |
|------|---------|-------------|
| Backend | `vendor/bin/pint --test` | No style changes |
| Backend | `vendor/bin/phpstan analyse --memory-limit=1G` | Level 5, no new errors (known ones are in `phpstan-baseline.neon`) |
| Backend | `php artisan test` | All tests pass (SQLite in memory) |
| Website | `npx tsc --noEmit && npx eslint src --max-warnings=0` | No errors or warnings |
| Website | `npx knip` | No unused files, exports or dependencies |
| Both | `composer audit`, `npm audit` | No known vulnerabilities |
