# Prime CRM — Call Center Platform

Production-grade CRM for vacation rental / timeshare call center sales. Modular monolith on Laravel 11, Vue 3 + Inertia, PostgreSQL, Redis, Twilio, Stripe.

This is **Response 1 of 5**: the foundation. It contains the complete database schema, the modular structure, tenant isolation, queue separation, audit logging, and Docker setup. Subsequent responses build the modules on top of this.

---

## Build plan

| Response | Scope | Status |
|---|---|---|
| 1 | Foundation: schema, tenant scoping, Horizon, Docker, base classes | this response |
| 2 | Lead module + Compliance module (TCPA pre-dial pipeline) | next |
| 3 | Dialer + CallCenter (predictive pacing, Twilio, idempotent webhooks) | |
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

## What Response 2 will add

- `Lead` module: ingestion (CSV import via `lead-import` queue), dedup engine (phone-hash + email + fuzzy name), scoring service (configurable weights, runs on `lead-scoring` queue), assignment service with performance-weighted routing, full controller set with form requests and resources
- `Compliance` module: `ComplianceGuardrailService` — the pre-dial pipeline that runs DNC check → consent check → frequency cap check → calling window check before any number is handed to the dialer. Imports federal DNC delta files. Enforces TCPA at the queue level, not the UI.

The dialer in Response 3 will not be allowed to bypass the guardrail. That's a structural property: `DialLeadJob::handle()` calls `$guardrail->mayDial($lead)` and aborts on rejection.
