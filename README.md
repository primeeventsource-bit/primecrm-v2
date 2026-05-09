# Prime CRM — Call Center Platform

Production-grade CRM for vacation rental / timeshare call center sales. Modular monolith on Laravel 11, Vue 3 + Inertia, PostgreSQL, Redis, Twilio, Stripe.

**Response 2 of 5** is in: the Lead module (ingestion, dedup, scoring, performance-weighted routing) and the Compliance module (the TCPA pre-dial guardrail) are now wired end-to-end with full controllers, jobs, factories, and Pest tests.

---

## Build plan

| Response | Scope | Status |
|---|---|---|
| 1 | Foundation: schema, tenant scoping, Horizon, Docker, base classes | ✅ done |
| 2 | Lead module + Compliance module (TCPA pre-dial pipeline) | ✅ done |
| 3 | Dialer + CallCenter (predictive pacing, Twilio, idempotent webhooks) | next |
| 4 | Booking + Payment + Commission (availability locks, event-sourced commissions) | |
| 5 | Vue 3 dialer UI, supervisor war room, k6 load tests, deployment guide | |

Each response produces real, runnable code that integrates with the previous layer. No pseudocode, no `// TODO`.

---

## What's in Response 1

### Modular structure
```
app/
├── Core/Shared/                 → cross-module: TenantContext, AuditLog, PhoneNormalizer, ModuleServiceProvider
├── Modules/
│   ├── Tenant/                  → tenants, users, auth (this response)
│   ├── Lead/                    → response 2
│   ├── Compliance/              → response 2
│   ├── CallCenter/              → response 3
│   ├── Dialer/                  → response 3
│   ├── Sales/                   → response 4
│   ├── Booking/                 → response 4
│   ├── Payment/                 → response 4
│   ├── Commission/              → response 4
│   └── Reporting/               → response 5
└── Support/
    ├── Concerns/                → HasUuid, TenantScoped, AppliesTenantContext
    └── Enums/                   → UserRole, LeadStatus, CallStatus, DealStage, PaymentStatus, CommissionEventType
```

Each module follows DDD-flavored layering: `Domain/` (entities, enums, events), `Application/` (services, jobs, DTOs, actions), `Infrastructure/` (repositories, third-party integrations), `Http/` (controllers, requests, resources).

### Database schema (complete — all tables)
- **Tenants & users**: multi-tenant root, role-aware users, sessions, password resets
- **Leads**: phone-hashed for fast DNC matching, source tracking, vacation-rental fields, partial unique index for soft-delete-safe dedup
- **Lead imports**: batch tracking with rollback metadata
- **Compliance**: `dnc_entries` (hash-indexed, federal/state/wireless/internal), `consent_records` (TCPA express consent), `contact_attempts` (frequency caps), `calling_windows` (per-jurisdiction time-of-day rules)
- **Call center**: `calls`, `call_events` (append-only state log), `dial_sessions`, `agent_statuses`, `campaigns`
- **Sales**: `deals` with multi-closer split fields, SNR/VD deductions (Prime CRM compatible), `deal_stage_transitions`
- **Booking**: `resorts`, `inventory_units`, `inventory_availability`, `inventory_holds`, `bookings` — with a partial unique index on inventory to make double-booking impossible at the DB level
- **Payment**: `payments` with parent linkage for refunds/chargebacks, `contracts`, `webhook_events` for idempotent provider event handling
- **Commission**: event-sourced — `commission_events` (immutable), `commission_calculations` (derived, reversible), `commission_plans/rules/assignments`, `commission_payouts`, `commission_adjustments`
- **Audit log**: immutable `audit_logs` for every sensitive action

### Tenant isolation — non-negotiable
Every tenant-scoped model uses the `TenantScoped` trait. Its global scope:
- Filters every query to the active tenant resolved via `TenantContext`
- Auto-stamps `tenant_id` on creation
- Returns empty result sets if no tenant is resolved (rather than leaking)
- Provides `withoutTenantScope()` for system jobs that legitimately need cross-tenant access

Queued jobs use `AppliesTenantContext` to capture the tenant at dispatch time and re-apply it inside the worker. Without this, jobs run with no tenant context and silently see nothing.

### Queue separation (Horizon)
Six supervisors with bounded concurrency:
- `supervisor-dialer` (50 procs): dialer ticks, call initiation
- `supervisor-webhooks` (30): Twilio/Stripe/DocuSign with exponential backoff
- `supervisor-leads` (20): imports, scoring, assignment
- `supervisor-background` (20): recordings, contracts, reports
- `supervisor-commissions` (10): calc and reversal
- `supervisor-default` (10): everything else

Queue names are centralized in `config/queue.php` under `names`. Jobs reference them via `config('queue.names.dialer')` rather than string literals.

### Redis topology
Logical DBs separated by workload:
- DB 0: queue
- DB 1: cache
- DB 2: dialer state (hot path — agent presence, dial pacing counters)
- DB 3: broadcasting

This isolates the dialer's high-write traffic from cache invalidation and lets us tune memory policies independently.

---

## Running locally

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
docker compose exec app php artisan db:seed   # seed data lands in response 5
```

Open `http://localhost:8000` for the app, `http://localhost:8000/horizon` for queue health.

### Running tests

```bash
# One-time: create a separate test database
docker compose exec postgres createdb -U crm crm_test

docker compose exec app composer test            # full Pest suite
docker compose exec app vendor/bin/pest --filter=Compliance   # just the compliance specs
```

Tests run against the same Postgres instance as dev, on a separate `crm_test` database. The `RefreshDatabase` trait re-creates the schema per test class.

### Required external accounts before going live
- Twilio: account SID, auth token, a programmable voice number, webhook URL
- Stripe: API keys + webhook signing secret
- DocuSign: integration key, RSA private key for JWT auth
- AWS S3: bucket for call recordings + signed contracts

---

## Architecture decisions worth flagging

**Why modular monolith, not microservices.** Call center workflows are heavily transactional and cross-module: a call references a lead, creates a deal, holds inventory, takes a payment, generates a commission event, and writes audit logs — all in one human session. Network hops between services here means saga complexity, eventual consistency on workflows that need to be immediate, and 10× the operational surface. We get most of the modularity benefits with one deployment unit.

**Why PostgreSQL over MySQL.** Three things we use that MySQL can't match: partial unique indexes (the double-booking prevention; the soft-delete-safe lead phone uniqueness), JSONB with GIN indexes (lead source metadata, sentiment timelines, audit log changes), and advisory locks (used in Response 4 for hold acquisition). All are central enough to make Postgres the right call.

**Why Vue 3 + Inertia, not Livewire.** The dialer UI has 6+ independent real-time data sources (call state, timer, agent status, lead queue, supervisor whisper events, script position). Livewire's full-component re-render model fights this — you end up using Alpine for everything anyway. Supervisor dashboards with 50+ live agent tiles updating concurrently hit Livewire's serialization overhead. Inertia gives us SPA snappiness with Laravel's routing/auth. Livewire stays a great fit for CRUD-heavy screens (Prime CRM payroll, settings) but is the wrong tool for a softphone.

**Why event-sourced commissions.** Commissions on cleared payments must reverse on chargebacks 30-180 days later. Mutating prior records loses the audit trail. Append-only `commission_events` + derived `commission_calculations` (with negative reversal entries) gives us full history, idempotent re-processing, and answerability when an agent disputes a paycheck. This pattern ports directly from Prime CRM's existing payroll logic.

**Why every webhook goes through `webhook_events`.** Provider events (Twilio call status, Stripe payment, DocuSign envelope) get retried, replayed, and occasionally arrive out of order. The unique `(provider, external_id)` constraint dedupes at the DB level; the `status` field tracks processing state. Drop one Stripe payment_intent.succeeded and you have an unpaid deal that's actually paid. This table makes that bug structurally impossible.

**Why we capture `tenant_id` in jobs.** This bites everyone the first time. Without `AppliesTenantContext`, `LeadImportJob::handle()` runs in the worker with no resolved tenant, the global scope returns empty, and the job either no-ops silently or — worse — partially succeeds in a confusing way. The trait makes the boundary explicit.

---

## What's in Response 2

### Lead module

- **Domain**: `Lead`, `LeadImport` models with `TenantScoped` + `HasUuid`. Lead has scopes for `contactable`, `unassigned`, `staleAssignments(N min)`. Events: `LeadCreated`, `LeadAssigned`, `LeadStatusChanged`, `LeadRejectedByCompliance`.

- **Dedup engine** (`LeadDedupService`): three-tier match — exact phone hash → exact lowercased email → fuzzy first/last name (Levenshtein) **plus** a structural co-signal (matching `postal_code` or `city + state`). The co-signal requirement is the difference between sane fuzzy matching and silently merging "John Smith from NYC" with "John Smith from LA".

- **Scoring engine** (`LeadScoringService`): pure, deterministic weighted-sum scoring with explicit per-component breakdown. Weights live in `config/leads.php` and can be overridden per-tenant via `tenants.settings`. Ceiling 0–1000, with negative penalties for stale leads and many failed contact attempts.

- **Assignment engine** (`LeadAssignmentService`) with three modes:
  - `round_robin` — cycles eligible agents via a Redis counter
  - `performance` (default) — weighted random selection from the top-N agents by `AgentScore = 0.4·conversion + 0.3·revenue + 0.2·callSpeed + 0.1·qa`
  - `skill_based` — filters by required skills first, then falls through to performance-weighted; falls back gracefully when the roster has no skill match
  - **Hot leads** (`priority=hot`) bypass the pool and go directly to the top performer
  - **Capacity guard** — agents at `max_open_leads_per_agent` are skipped
  - Every assignment is audit-logged with the decision rationale (mode, pool size, winning score)

- **Performance metrics** (`AgentPerformanceRepository`): 30-day rolling window queries against `leads`, `deals`, `calls`. Per-agent results cached 5 min in Redis to keep routing decisions cheap.

- **CSV ingestion**: `POST /api/leads/import` (multipart, supervisor-only). File goes to S3, `ImportLeadsBatchJob` runs async on the `lead-import` queue, processing one row at a time through the same `CreateLeadAction` as the API. Per-row errors are captured (capped at 100 samples) without aborting the batch.

- **Stale reassignment**: `ReassignStaleLeadsJob` scheduled every minute via `routes/console.php`, sweeps every active tenant for leads idle past `stale_assignment_minutes` (default 10) and re-routes them.

### Compliance module

The dialer in Response 3 calls a single method — `$guardrail->mayDial($lead, $dialerMode)` — and aborts on rejection. The guardrail is structurally non-bypassable.

The pre-dial pipeline runs four gates in order, each independent and final:

1. **Lead state** — flagged DNC, terminal status, missing phone (cheap, no I/O)
2. **DNC** (`DncCheckService`) — single indexed lookup against `dnc_entries` joining tenant-scoped + global (federal/state/wireless) lists. Severity ranking picks the most serious match for reporting.
3. **Consent** (`ConsentCheckService`) — express written consent required for autodialer/predictive/progressive modes; manual mode has a softer bar. Detects revoked consent.
4. **Frequency cap** (`FrequencyCapService`) — single PostgreSQL `COUNT(*) FILTER` aggregate covering cooldown (default 4h), daily cap (default 3), and 30-day cap (default 7). All three windows in one indexed query.
5. **Calling window** (`CallingWindowService`) — TCPA 8am-9pm local time at the called party's location. Timezone resolution: `lead.timezone` → area code lookup (NANP map covering US/Canada) → tenant default → UTC. Per-jurisdiction state overrides via `calling_windows`. Cached 5 min so dialing doesn't hammer the rules table.

**Decision shape**: `GuardrailDecision` value object — `allowed: bool`, `rejection_code` enum (`dnc_federal`, `consent_revoked`, `frequency_daily_cap`, `outside_calling_window`, …), human reason, structured metadata. Rejections audit-log themselves and emit `LeadRejectedByCompliance`.

**Federal DNC import**: `compliance:dnc:import-federal {path} --disk=s3` — bulk-imports newline-delimited delta files into the global `dnc_entries` set with idempotent insert (re-running the same file inserts nothing). The actual SFTP fetch from telemarketing.donotcall.gov runs outside the application via cron + a download script that drops the file at the configured path; this keeps subscriber credentials out of the app.

**Side effect**: adding any DNC entry whose phone matches existing leads stamps `lead.is_on_dnc = true` so the cheap pre-flight gate catches them before hitting the DNC table again. Federal additions cross all tenants.

### API surface

```
# Lead
GET    /api/leads                       list with filters (status, agent, priority, score, q)
POST   /api/leads                       single create (handles dedup + auto-assignment)
GET    /api/leads/{id}
PUT    /api/leads/{id}                  status changes emit LeadStatusChanged
DELETE /api/leads/{id}                  soft delete

POST   /api/leads/import                supervisor-only; multipart CSV → batch
GET    /api/leads/imports               list batches
GET    /api/leads/imports/{id}          poll progress
POST   /api/leads/{id}/assign           routing engine OR direct (with agent_id)

# Compliance
GET    /api/compliance/dnc              tenant + global lists
POST   /api/compliance/dnc              add internal/customer/litigator entries
DELETE /api/compliance/dnc/{id}         remove (user-editable sources only)

GET    /api/compliance/consent          filter by lead/phone_hash/type/active
POST   /api/compliance/consent          record TCPA consent (web/recording/paper)
POST   /api/compliance/consent/{id}/revoke

GET    /api/compliance/guardrail/check/{leadId}    diagnostic: would the dialer dial this?
```

### Tests

Pest test suite for the new code:

- `Unit/` — `LeadScoringService`, `PhoneNormalizer`, `AreaCodeTimezoneResolver`, `GuardrailDecision`
- `Feature/Lead/` — dedup tiers (phone exact, email exact, fuzzy with co-signal), HTTP CRUD + import, assignment modes, capacity guards, hot-lead short-circuit, manual reassignment
- `Feature/Compliance/` — every guardrail rejection path (DNC federal/wireless, missing/revoked consent, frequency cooldown/daily cap, outside calling window, terminal status), federal DNC delta import (idempotency), consent revocation flag-flipping

Tests run against a real PostgreSQL DB — the schema uses partial unique indexes, JSONB, and `FILTER` aggregates that have no faithful SQLite equivalent. See [Running tests](#running-tests) below.

---

## Deploying to Laravel Cloud

This repo is structured to deploy to [Laravel Cloud](https://cloud.laravel.com) with no Dockerfile/buildpack changes — Cloud auto-detects the Laravel 11 layout and builds via FrankenPHP + Octane. The local `Dockerfile` and `docker-compose.yml` are for self-hosting and are ignored by Cloud.

### One-time setup

1. **Create the app** in Cloud's UI from this repo (`primeeventsource-bit/PrimeCRM-v2`, branch `main`).
2. **Attach a Postgres database** and a **Redis (KeyDB)** instance — Cloud injects `DB_HOST`/`DB_PASSWORD`/`REDIS_HOST`/`REDIS_PASSWORD` automatically.
3. **Generate `APP_KEY`**: in Cloud's env editor add `APP_KEY=` and click "Generate", or paste output of `php artisan key:generate --show`.

### Required environment variables (set in Cloud's UI)

| Var | Value | Notes |
|---|---|---|
| `APP_ENV` | `production` | |
| `APP_DEBUG` | `false` | |
| `APP_URL` | `https://<your-cloud-domain>` | Cloud assigns this |
| `APP_KEY` | `base64:...` | generate once, never rotate without re-encrypting sessions |
| `DB_CONNECTION` | `pgsql` | |
| `DB_SSLMODE` | `require` | Cloud's managed Postgres requires TLS |
| `OCTANE_SERVER` | `frankenphp` | the default; Swoole is not available on Cloud |
| `OCTANE_HTTPS` | `true` | Cloud terminates TLS at the edge |
| `QUEUE_CONNECTION` | `redis` | |
| `CACHE_STORE` | `redis` | |
| `SESSION_DRIVER` | `redis` | |
| `LOG_CHANNEL` | `stderr` | Cloud aggregates stdout/stderr |
| `BROADCAST_CONNECTION` | `pusher` or `reverb` | Soketi only works locally |
| `SANCTUM_STATEFUL_DOMAINS` | `<your-cloud-domain>` | required for Inertia + Sanctum SPA auth |
| `FILESYSTEM_DISK` | `s3` | + the four `AWS_*` vars |
| Twilio / Stripe / DocuSign | as in `.env.example` | provider-specific secrets |

Leave Soketi-specific vars (`PUSHER_HOST`, `PUSHER_PORT`, `PUSHER_SCHEME`) **unset** on Cloud — `config/broadcasting.php` falls back to the real Pusher endpoint when `PUSHER_HOST` is empty.

### Process types

Configure these in Cloud's process editor:

| Type | Command | Why |
|---|---|---|
| Web | (default — Cloud runs `octane:start --server=frankenphp`) | Octane HTTP server |
| Worker | `php artisan horizon` | one process; Horizon manages all six supervisors internally |
| Scheduler | `php artisan schedule:work` | drives the cron entries in `routes/console.php` |

Do **not** add a separate worker per Horizon supervisor — Horizon spawns its own subprocess pool and sizes them per `config/horizon.php`.

### Build & deploy hooks

Cloud's default Laravel build runs `composer install --no-dev` and `php artisan optimize`. Add these post-deploy commands in Cloud's UI:

```bash
php artisan migrate --force
php artisan storage:link
php artisan horizon:terminate   # graceful restart of queue workers
```

### What's intentionally not here

- **No `package.json` / Vite** — the Vue UI lands in Response 5. Until then Cloud's frontend build phase is a no-op. When the UI ships, Cloud will auto-detect `package.json` and run `npm ci && npm run build`.
- **No `composer.lock`** committed — Cloud generates one on first build. To pin versions, commit a lockfile generated by `composer install` locally.
- **No Soketi** — replace with managed Pusher or run **Laravel Reverb** as a fourth Cloud process (`php artisan reverb:start --host=0.0.0.0 --port=$PORT`).

### Health check

`bootstrap/app.php` registers `/up` as the health-check endpoint. Cloud probes this automatically — no extra config needed.
