# Deployment

Three target environments: Laravel Cloud (managed), self-hosted Docker
(`docker-compose.yml`), and CI (GitHub Actions). All three share the same
codebase; only the runtime config differs.

## Prerequisites (any target)

- PHP 8.3 with `pdo_pgsql`, `redis`, `intl`, `bcmath`
- PostgreSQL 16
- Redis 7 (or KeyDB)
- Node 20 (build only)

External accounts:

- **Twilio** — account SID, auth token, programmable voice number, public webhook URL
- **Stripe** — API key, webhook signing secret
- **DocuSign** — integration key + RSA private key (Response 4 contract flow)
- **AWS S3** — bucket + IAM keys for call recordings + signed contracts

## Laravel Cloud

The repo is structured to deploy to [Laravel Cloud](https://cloud.laravel.com)
with no buildpack changes. Cloud auto-detects the Laravel 11 layout and
builds via FrankenPHP + Octane.

### One-time

1. **Create the app** in Cloud's UI from this repo.
2. **Attach managed services**:
   - Postgres 16 — Cloud injects DB_*
   - Redis (KeyDB) — Cloud injects REDIS_*
3. **Generate APP_KEY** (Cloud's env editor → Generate).
4. **Set required env vars** (Cloud UI):

| Variable | Value | Notes |
|---|---|---|
| `APP_ENV` | `production` | |
| `APP_DEBUG` | `false` | |
| `APP_URL` | `https://<cloud-domain>` | |
| `DB_SSLMODE` | `require` | Managed Postgres requires TLS |
| `OCTANE_SERVER` | `frankenphp` | Default; Swoole isn't available |
| `OCTANE_HTTPS` | `true` | TLS at Cloud's edge |
| `QUEUE_CONNECTION` | `redis` | |
| `BROADCAST_CONNECTION` | `pusher` or `reverb` | Soketi is local-only |
| `SANCTUM_STATEFUL_DOMAINS` | `<cloud-domain>` | Required for Inertia + Sanctum |
| `FILESYSTEM_DISK` | `s3` | + the four `AWS_*` vars |
| `TWILIO_*` | (provider-specific) | See `.env.example` |
| `STRIPE_*` | (provider-specific) | |
| `DOCUSIGN_*` | (provider-specific) | |

### Process types

| Type | Command | Why |
|---|---|---|
| Web | (default — Cloud runs `octane:start --server=frankenphp`) | Serves HTTP + Inertia + API |
| Worker | `php artisan horizon` | One process; Horizon spawns the 6 supervisors internally |
| Scheduler | `php artisan schedule:work` | Drives `routes/console.php` (lead reassign, dialer pacing tick, hold expiration) |

Do NOT add a separate worker per Horizon supervisor — that creates two
sets of pools and breaks dispatched-to-named-queue routing.

### Post-deploy hooks

```bash
php artisan migrate --force
php artisan storage:link
php artisan horizon:terminate   # graceful queue restart
php artisan optimize
```

### Health check

`bootstrap/app.php` registers `/up`. Cloud probes it automatically.

---

## Self-hosted Docker

`docker-compose.yml` brings up the full stack: app (Octane/Swoole),
horizon, scheduler, postgres, redis, soketi.

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
docker compose exec app php artisan db:seed --class=DemoSeeder

# Frontend assets
docker compose exec app npm ci
docker compose exec app npm run build
```

Open `http://localhost:8000`. Horizon at `http://localhost:8000/horizon`.

### Production-grade self-host

The compose stack is fine for dev. For production, run the same images
behind:

- A **reverse proxy with TLS termination** (Caddy, nginx). Twilio webhook
  signature verification depends on the URL Twilio sees matching what
  the controller computes — the proxy MUST preserve the `Host` header
  (`X-Forwarded-Host`) and forward the original `https://`.
- **External Postgres** with replication (we use the read replica
  connection in `config/database.php` for reporting).
- **External Redis cluster** if you outscale a single instance (the
  `dialer` logical DB sees the highest write rate).

### Critical Redis topology

The platform uses **four separate logical Redis databases**:

| DB | Purpose | Why isolated |
|---|---|---|
| 0 | Queue | Horizon job state |
| 1 | Cache | App cache (`AgentPerformanceRepository`, calling-window rules) |
| 2 | Dialer | Agent presence, lead queue, pacing counters — hot writes |
| 3 | Broadcasting | WebSocket topic state |

Don't collapse these onto one DB. The dialer's write traffic fights the
cache's eviction policy if they share.

---

## CI

`.github/workflows/ci.yml` runs on every push:

1. PHP setup with required extensions
2. composer install (cached)
3. spin up postgres + redis service containers
4. `php artisan migrate` against the test DB
5. `vendor/bin/pint --test` (style)
6. `vendor/bin/pest --parallel` (full test suite)
7. Frontend job: `npm ci && npm run build`

Tests run against real Postgres + Redis. Don't switch to SQLite — the
schema uses partial unique indexes and `FILTER` aggregates that have no
faithful SQLite equivalent (the lead phone uniqueness, the
double-booking prevention, the frequency-cap query all depend on it).

---

## Operational runbooks

### Federal DNC import

```bash
# Cron-driven SFTP fetch from telemarketing.donotcall.gov happens
# OUTSIDE the application — a thin shell script downloads the daily
# delta to /var/lib/dnc/{date}.txt, then dispatches the import job.

php artisan compliance:dnc:import-federal /var/lib/dnc/2026-05-13.txt --disk=local
```

The job is idempotent (the table's unique constraint dedupes phone hashes),
so if the cron fires twice the second run inserts nothing.

### Pause the dialer fleet

```bash
# Operator-facing: flip every active dial_session to paused at once.
php artisan tinker --execute='
    \App\Modules\Dialer\Domain\Models\DialSession::query()
      ->withoutTenantScope()
      ->where("status", "active")
      ->update(["status" => "paused", "paused_at" => now()]);
'
# The pacing tick ignores paused sessions, so dial volume drops to zero
# within one tick (~30s).
```

### Replay a specific Stripe webhook

```bash
# When something arrived but didn't process (rare), look up the row
# in webhook_events and re-dispatch the job:
php artisan tinker --execute='
    $event = \App\Modules\CallCenter\Domain\Models\WebhookEvent::query()
      ->where("provider", "stripe")
      ->where("external_id", "evt_abc123")
      ->first();
    \App\Modules\Payment\Application\Jobs\ProcessStripeWebhookJob::dispatch($event->id);
'
```

The job is idempotent; re-running it on a payment that's already cleared
is a no-op.

### Rebuild a payout

```bash
# After adjustments land, rebuild the period's payout:
curl -X POST https://stg.example.com/api/commission/payouts/build \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"user_id":"<uuid>","period_start":"2026-05-01","period_end":"2026-05-31"}'
```

Refused if the payout is already approved/paid — those must be voided
and reissued, never mutated.

---

## What's intentionally not here

- **No `composer.lock`** committed — Cloud generates one on first build.
  To pin versions, commit a lockfile generated locally.
- **No SSE fallback** — Soketi is the local broadcaster, Pusher/Reverb
  for prod. Inertia falls back gracefully if WebSocket auth fails (the
  pages still work, just without live updates).
- **No multi-region failover** — single-region Postgres + Redis. The
  schema is multi-region-safe (UUID v7 primary keys, append-only event
  logs) but the orchestration to flip regions is out of scope.
