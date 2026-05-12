# Prime CRM — Call Center Platform

Production-grade CRM for vacation rental / timeshare call center sales. Modular monolith on Laravel 11, Vue 3 + Inertia, MySQL 8, Redis, Twilio, Stripe.

> **Database choice — history**: this repo was originally architected for PostgreSQL (partial unique indexes, advisory locks, JSONB+GIN). The project moved to MySQL on 2026-05-09; the migrations are MySQL-safe and `HoldService` dispatches advisory locks per driver. A few sections below still describe the original Postgres-leaning design with notes on what the MySQL deployment actually does.

**Build complete (5/5).** Vue 3 + Inertia frontend (dialer console, supervisor war room, pipeline, booking search, payment capture), real-time broadcasting on top of the domain event bus, k6 load tests for the three load-bearing surfaces (agent sessions, compliance gate, webhook flood), CI workflow, and a deployment runbook.

---

## Build plan

| Response | Scope | Status |
|---|---|---|
| 1 | Foundation: schema, tenant scoping, Horizon, Docker, base classes | ✅ done |
| 2 | Lead module + Compliance module (TCPA pre-dial pipeline) | ✅ done |
| 3 | Dialer + CallCenter (predictive pacing, Twilio, idempotent webhooks) | ✅ done |
| 4 | Booking + Payment + Commission (availability locks, event-sourced commissions) | ✅ done |
| 5 | Vue 3 dialer UI, supervisor war room, broadcasting, k6 load tests, deployment | ✅ done |

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
- **Leads**: phone-hashed for fast DNC matching, source tracking, vacation-rental fields. App-level `LeadDedupService` enforces soft-delete-safe phone uniqueness (the original Postgres partial unique index `… WHERE deleted_at IS NULL` is conditional on `pgsql` in the migration; MySQL relies on the service)
- **Lead imports**: batch tracking with rollback metadata
- **Compliance**: `dnc_entries` (hash-indexed, federal/state/wireless/internal), `consent_records` (TCPA express consent), `contact_attempts` (frequency caps), `calling_windows` (per-jurisdiction time-of-day rules)
- **Call center**: `calls`, `call_events` (append-only state log), `dial_sessions`, `agent_statuses`, `campaigns`
- **Sales**: `deals` with multi-closer split fields, SNR/VD deductions (Prime CRM compatible), `deal_stage_transitions`
- **Booking**: `resorts`, `inventory_units`, `inventory_availability`, `inventory_holds`, `bookings`. Double-booking prevention is now in-transaction (row lock + status check) on MySQL; the original Postgres partial unique index was the strongest backstop and is reinstalled automatically on `pgsql` deployments
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

Tests run against the same MySQL instance as dev, on a separate `crm_test` database. The `RefreshDatabase` trait re-creates the schema per test class. Tests that exercise the dialer (PacingEngine, AgentPresenceService, LeadQueueService) also need Redis — Redis logical DB 15 (`REDIS_DIALER_DB=15`) is reserved for tests so dev data isn't clobbered.

---

## What's in Response 3

### CallCenter module — telephony, presence, webhooks

- **Telephony abstraction** — [`TelephonyProvider`](app/Modules/CallCenter/Infrastructure/Telephony/TelephonyProvider.php) is the interface; [`TwilioTelephonyProvider`](app/Modules/CallCenter/Infrastructure/Telephony/TwilioTelephonyProvider.php) wraps the Twilio SDK so no other code in the system imports `Twilio\*`. Adding Telnyx/Plivo/SIP later is a second implementation, not a refactor. Tests bind a `FakeTelephonyProvider` via the container.

- **Call state machine** — [`CallStateService`](app/Modules/CallCenter/Application/Services/CallStateService.php) owns every transition: `markInitiated` / `markRinging` / `markAnswered` / `markEnded` / `setDisposition`. Each transition appends a row to `call_events` with an `idempotency_key` (Twilio CallSid + status) and updates the `calls` row atomically. The unique constraint on `call_events.idempotency_key` is what protects against Twilio's webhook retries — duplicate writes raise a constraint violation that the service swallows silently.

- **Agent presence** — [`AgentPresenceService`](app/Modules/CallCenter/Application/Services/AgentPresenceService.php) keeps a MySQL row + Redis hash in sync. The MySQL row is the durable source of truth; Redis (logical DB 2, the `dialer` connection) is the hot path the predictive engine reads on every pacing tick. If Redis loses state, `listAvailable()` rewarms from MySQL. Every transition emits `AgentStatusChanged` and writes an audit log entry.

- **Webhook idempotency** — [`WebhookEventStore`](app/Modules/CallCenter/Application/Services/WebhookEventStore.php). The `(provider, external_id)` unique constraint on `webhook_events` is the structural guarantee. The store wraps that with the lifecycle: `received` → `processing` → `processed` (or `failed`). The HTTP webhook controller does the absolute minimum (verify signature, ingest, dispatch job, 200 OK) and gets out of Twilio's retry budget fast.

- **Twilio webhooks** — [`TwilioWebhookController`](app/Modules/CallCenter/Http/Controllers/TwilioWebhookController.php) handles three endpoints (no auth, signature-verified): `/api/webhooks/twilio/voice/{callId}` returns TwiML to bridge the call to the assigned agent's softphone client; `/api/webhooks/twilio/status/{callId}` and `/api/webhooks/twilio/recording/{callId}` ingest to `webhook_events` and dispatch [`ProcessTwilioStatusWebhookJob`](app/Modules/CallCenter/Application/Jobs/ProcessTwilioStatusWebhookJob.php) / [`ProcessTwilioRecordingWebhookJob`](app/Modules/CallCenter/Application/Jobs/ProcessTwilioRecordingWebhookJob.php) on the `webhooks-twilio` queue. All module routes mount under the `api` prefix — Twilio/Stripe dashboards must include it.

- **Recording flow** — [`DownloadCallRecordingJob`](app/Modules/CallCenter/Application/Jobs/DownloadCallRecordingJob.php) streams the recording from Twilio straight into S3 (no full-file PHP buffer). Path shape: `recordings/{tenant_id}/{YYYY}/{MM}/{call_id}.mp3` with optional server-side encryption per `CALL_RECORDING_ENCRYPT`.

- **Listeners** — [`RecordContactAttemptOnCallInitiated`](app/Modules/CallCenter/Application/Listeners/RecordContactAttemptOnCallInitiated.php) writes a `contact_attempts` row at *initiate* time so even abandoned dials count toward TCPA frequency caps. [`UpdateContactOutcomeOnCallEnded`](app/Modules/CallCenter/Application/Listeners/UpdateContactOutcomeOnCallEnded.php) backfills the outcome and rolls the agent into wrap-up.

- **Supervisor controls** — [`SupervisorCallController`](app/Modules/CallCenter/Http/Controllers/SupervisorCallController.php) implements `kill` today; `whisper` and `barge` are stable contracts returning 501 until the dialer's TwiML conferences land in Response 5.

### Dialer module — the predictive engine

The pacing math is the centerpiece. [`PacingEngine`](app/Modules/Dialer/Application/Services/PacingEngine.php):

```
dial_rate = agents_available × (1 / connection_rate) × safety_factor
```

with hard caps (max_dials_per_agent, safety_factor min/max), a cold-start floor (min_connection_rate), and adaptive feedback on the safety factor:

```
if abandon_rate > 0.7 × target_abandon_rate    →  safety_factor *= 0.85
if abandon_rate < 0.3 × target_abandon_rate    →  safety_factor *= 1.10
```

The decision returned is a [`PacingDecision`](app/Modules/Dialer/Domain/ValueObjects/PacingDecision.php) value object carrying every input — agents available, connection rate, abandon rate, raw rate, safety factor, reason — so ops can answer "why did the dialer fire 47 calls just now?" without reading code.

- **Lead queue** — [`LeadQueueService`](app/Modules/Dialer/Application/Services/LeadQueueService.php) is a Redis sorted set per campaign (key: `tenant:{tenantId}:campaign:{campaignId}:leadq`). Score = lead.score; `ZPOPMAX` is the atomic pick. Refilled in batches from MySQL when depth drops below threshold. Atomic pop scales to thousands of agents — a MySQL `SELECT … FOR UPDATE SKIP LOCKED` pattern (supported since 8.0) serializes the dialer.

- **Session lifecycle** — [`DialerService`](app/Modules/Dialer/Application/Services/DialerService.php) handles `start` / `pause` / `resume` / `stop`, flipping agent presence in lockstep. Refuses to create a duplicate session if the agent already has one active (returns the existing one).

- **`DialLeadJob` — the structural chokepoint** — [`DialLeadJob`](app/Modules/Dialer/Application/Jobs/DialLeadJob.php) is the *only* code path that places an outbound call. Its first I/O after loading the lead is `$guardrail->mayDial(...)`. If the guardrail rejects, the call never gets placed; the lead is either removed from the campaign queue (DNC, terminal status — permanent) or requeued with a score penalty (frequency cap, calling window — transient). The manual click-to-call endpoint at `POST /api/dialer/sessions/{id}/dial-now` *also* runs the guardrail before delegating to this job — there's no bypass even from the agent UI.

- **Pacing tick** — [`PacingTickJob`](app/Modules/Dialer/Application/Jobs/PacingTickJob.php) per-tenant work; [`PacingTickSchedulerJob`](app/Modules/Dialer/Application/Jobs/PacingTickSchedulerJob.php) fans out across all active tenants. Scheduled `everyThirtySeconds()` in [routes/console.php](routes/console.php) with `onOneServer()` so multiple Horizon hosts don't double-fire.

### API surface (added in Response 3)

```
# Calls (read + disposition + end)
GET    /api/calls                         filter by agent/lead/live
GET    /api/calls/{id}
POST   /api/calls/{id}/disposition
POST   /api/calls/{id}/end

# Agent presence
GET    /api/agent-status                  supervisor war-room view
GET    /api/agent-status/me
POST   /api/agent-status                  set status (self or supervisor override)
POST   /api/agent-status/heartbeat

# Supervisor controls
POST   /api/supervisor/calls/{id}/kill
POST   /api/supervisor/calls/{id}/whisper   501 until Response 5
POST   /api/supervisor/calls/{id}/barge     501 until Response 5

# Dialer sessions
GET    /api/dialer/sessions/active
POST   /api/dialer/sessions               start
POST   /api/dialer/sessions/{id}/pause
POST   /api/dialer/sessions/{id}/resume
POST   /api/dialer/sessions/{id}/stop
POST   /api/dialer/sessions/{id}/dial-now  click-to-call (still gated by guardrail)

# Twilio webhooks (PUBLIC, signature-verified)
# NB: module routes mount under /api — Twilio dashboards must use the /api/ prefix.
POST   /api/webhooks/twilio/voice/{callId}     TwiML → <Dial><Client>agent-{id}</Client></Dial>
POST   /api/webhooks/twilio/status/{callId}    status callbacks
POST   /api/webhooks/twilio/recording/{callId} recording status
```

### Tests (Response 3 additions)

- `Feature/Dialer/DialLeadJobGuardrailTest` — **the assertion**: federal DNC, missing consent, calling-window-closed, no-agent-available all result in zero calls placed; the happy path places exactly one. This is the test that locks in the structural property.
- `Feature/Dialer/DialerSessionHttpTest` — manual `dial-now` is also gated by the guardrail (returns 422 with the decision payload on rejection); only happy paths place calls.
- `Feature/Dialer/PacingEngineTest` — pacing math under various conditions: zero agents, empty queue, per-agent cap clamps unreasonable rates, in-flight subtraction, abandon-rate adaptation reduces safety factor.
- `Feature/Dialer/DialerSessionLifecycleTest` — start/pause/resume/stop and the corresponding Available/OnBreak/Offline transitions.
- `Feature/CallCenter/WebhookIdempotencyTest` — duplicate Twilio webhooks produce one `webhook_events` row and one `call_events` row; bad signatures get 403; valid signatures route to the right job.
- `Feature/CallCenter/CallStateTransitionsTest` — every transition; refuses to downgrade in_progress → ringing on out-of-order webhooks; idempotent re-application via `idempotency_key`.
- `Feature/CallCenter/AgentPresenceTest` — MySQL + Redis stay in sync; rewarm-from-MySQL works after a Redis flush; every transition writes an audit log row.

`tests/Support/FakeTelephonyProvider` is the test double — bound via the container so feature tests can capture every call placed, control signature verification, and force provider failures.

### Tuning surfaces

Per-tenant overrides live on `tenants.settings`; global defaults in [config/telephony.php](config/telephony.php):

```php
'predictive' => [
    'target_abandon_rate' => 0.03,    // FCC cap, rolling 30 days
    'safety_factor_initial' => 1.0,
    'safety_factor_min' => 0.5,
    'safety_factor_max' => 2.5,
    'pacing_interval_seconds' => 30,
    'min_connection_rate' => 0.05,
    'max_dials_per_agent' => 4,
    'wrap_up_seconds_default' => 15,
],
```

Per-campaign overrides live on `campaigns` (target_abandon_rate, safety_factor, dialer_mode, calling-window bounds).

### Operational notes

- **Recordings disk** — set `CALL_RECORDING_DISK=s3` and the four `AWS_*` vars. The `recording_paused_at` field on `calls` is wired so payment capture flows in Response 4 can pause the recording stream during card entry (PCI).
- **Twilio signature verification** — set `TWILIO_VERIFY_SIGNATURE=true` (default) in production. The local docker stack runs without TLS; flip to `false` only there. `TWILIO_WEBHOOK_BASE_URL` must be the externally-reachable HTTPS host Twilio will receive in its signature; reverse proxies must preserve the host.
- **Dialer queue** — `supervisor-dialer` runs at 50 procs by default ([config/horizon.php](config/horizon.php)). Increase for larger fleets; the per-tenant `PacingTickJob` is bounded at 25s timeout so it won't cascade backlogs.

---

## What's in Response 4

### Sales module

- [Deal](app/Modules/Sales/Domain/Models/Deal.php) — multi-closer aware (`agent_id` primary closer, `fronter_id`, `additional_closer_ids` json), with SNR/VD deductions and a derived `payable_amount` that's the base for commission percentages.
- [DealStageTransition](app/Modules/Sales/Domain/Models/DealStageTransition.php) — append-only history; mirrors the call_events pattern.
- [DealService::advanceStage](app/Modules/Sales/Application/Services/DealService.php) writes the transition row + updates the deal atomically; emits `DealStageChanged` always and `DealClosedWon` when reaching ClosedWon. The Commission module subscribes.
- HTTP: `GET/POST /api/deals`, `GET /api/deals/{id}`, `POST /api/deals/{id}/advance-stage`.

### Booking module — double-booking prevented at the DB level

The strongest structural guarantee in this response. Two layers of defense, both engine-aware:

1. **Engine-aware advisory lock** in [HoldService::hold](app/Modules/Booking/Application/Services/HoldService.php). The lock is acquired outside the transaction and released in `finally`:
   - **MySQL** (production): `GET_LOCK('hold:{md5}', 5)` + matching `RELEASE_LOCK`. 5-second timeout — under contention spikes, callers fall through to step 2 instead of immediately raising.
   - **Postgres**: `pg_advisory_lock(hashtextextended(...))` + matching `pg_advisory_unlock`. Session-scoped to keep the acquire/release shape uniform with MySQL.
   - **SQLite** (tests): no-op. SQLite serializes writes anyway.
2. **Row lock + state check** inside the transaction: `InventoryAvailability::query()->lockForUpdate()->find(...)` then `isAvailable()`. This is the structural race guard that holds on every engine — the advisory lock just shortens the contention window. If two requests race past the advisory lock, only one's `isAvailable()` check returns true; the other raises `InventoryUnavailableException`.

> The original architecture relied on a Postgres-only partial unique index (`WHERE status IN ('available','held','booked')`) as the structural backstop. MySQL doesn't support partial unique indexes; the row lock + status check above is the MySQL-compatible replacement. `HoldService` still catches `UniqueConstraintViolationException` for any environment that reintroduces the index.

[DoubleBookingPreventionTest](tests/Feature/Booking/DoubleBookingPreventionTest.php) locks this in: two agents racing on the same availability row → exactly one wins, the other gets a 409. After release, the second agent can hold cleanly.

Hold lifecycle:
- Hold created → `inventory_availability.status = 'held'`, `current_hold_id` stamped, expires_at set per resort.hold_ttl_minutes
- Hold expired (sweep) → status back to 'available'; [ExpireInventoryHoldsJob](app/Modules/Booking/Application/Jobs/ExpireInventoryHoldsJob.php) scheduled every minute
- Hold converted to booking → status to 'booked', hold released with reason='converted'
- Booking cancelled → original availability row marked 'cancelled', fresh 'available' row inserted for the same unit/date so it can be rebooked. Booking history preserved.

[BookingService](app/Modules/Booking/Application/Services/BookingService.php) generates a customer-friendly confirmation number (`BK-XXXXXXXX`, alphabet excludes I/O/0/1).

API: `GET /api/inventory/search`, `POST /api/inventory/holds`, `DELETE /api/inventory/holds/{id}`, `POST /api/bookings/from-hold/{holdId}`, `POST /api/bookings/{id}/cancel`.

### Payment module — provider-agnostic, PCI-safe, idempotent

- [PaymentGateway](app/Modules/Payment/Infrastructure/Gateway/PaymentGateway.php) interface; [StripeGateway](app/Modules/Payment/Infrastructure/Gateway/StripeGateway.php) implementation. No `Stripe\*` import outside that file. Tests bind a [FakePaymentGateway](tests/Support/FakePaymentGateway.php).
- [ChargePaymentAction](app/Modules/Payment/Application/Actions/ChargePaymentAction.php) — creates the `payments` row BEFORE calling the gateway so a webhook arriving microseconds later has a row to land on. Failures roll back so we don't accumulate orphan "queued" rows.
- **PCI**: the action accepts a `pm_...` token, never raw card data. The agent UI uses Stripe Elements; the card never touches our server. The dialer's `pauseRecording` (Response 3) is called by the agent UI before card capture and resumed after — recording stays PCI-clean.
- [RefundPaymentAction](app/Modules/Payment/Application/Actions/RefundPaymentAction.php) — creates a NEW `payments` row of type=`refund` linked via `parent_payment_id`. The original is updated to `refunded`/`partially_refunded`. Both rows survive in history; nothing is mutated except the original's status.
- [Webhook idempotency](app/Modules/Payment/Application/Jobs/ProcessStripeWebhookJob.php) — uses the **same** `webhook_events` table introduced in Response 3 with the `(provider, external_id)` unique constraint. Stripe's `event.id` is the dedup key. The HTTP controller verifies signature → ingests → dispatches job → 200 OK; processing is async on the `webhooks-stripe` queue.
- Handled events: `payment_intent.succeeded` (sets `cleared_at`, fires `PaymentCleared`), `payment_intent.payment_failed`, `charge.dispute.created` (fires `ChargebackOccurred`), `charge.refunded` (no-op confirmation since we already wrote the row).
- **Critical: `cleared_at` is the commission trigger**, not authorization. The Commission module only pays on settled funds, not pending intents.

API: `GET/POST /api/payments`, `POST /api/payments/charge`, `POST /api/payments/{id}/refund` (supervisor-only), `POST /api/webhooks/stripe` (public, signature-verified).

### Commission module — event-sourced, reversible, audit-preserving

The hardest part is that commissions accrue on cleared payments but **chargebacks happen 30–180 days later**. Mutating prior records loses the audit trail. The append-only event log + negative reversal calculations solve this.

**Append-only event log** — [CommissionEvent](app/Modules/Commission/Domain/Models/CommissionEvent.php). Every business event that affects commissions writes one row keyed by `idempotency_key` (e.g. `payment.cleared:{payment_id}`, `payment.refunded:{refund_id}`). The unique constraint on the column means re-dispatched domain events collide at the DB level; [CommissionEventLog::append](app/Modules/Commission/Application/Services/CommissionEventLog.php) returns null on collision so callers skip downstream work.

**Forward path** — [CommissionEngine::process](app/Modules/Commission/Application/Services/CommissionEngine.php):
1. Load all active rules whose `trigger_event` matches the commission event.
2. For each rule, [RoleResolver](app/Modules/Commission/Application/Services/RoleResolver.php) resolves the recipient(s) — closer → `deal.agent_id`, fronter → `deal.fronter_id`, supervisor/QA/override → explicit user ids in rule config. Multi-closer split scenarios produce one calculation per closer with `config.split_among='all_closers'`.
3. Skip recipients without an active `commission_assignments` row for the rule's plan.
4. [RuleEvaluator](app/Modules/Commission/Application/Services/RuleEvaluator.php) computes the calculation per `rule_type`:
   - `flat`: `config.amount`
   - `percentage`: `config.rate × payload[config.base_field]`
   - `tiered`: brackets `[{up_to, rate}]`. Two modes — flat (matching tier rate × whole base) and `marginal=true` (true marginal split across brackets). `2000 × 0.05 + 3000 × 0.08 + 2000 × 0.12 = 580` on a $7000 base.
5. Persist as `commission_calculations` with explanation json (rule type, math trace) so agents can answer "why is my commission $237?" without engineering help. Status defaults to `payable` for `payment.cleared` events, `pending` for `deal.closed_won`.

**Reversal path** — [CommissionEngine::reverseFromEvent](app/Modules/Commission/Application/Services/CommissionEngine.php):
1. Find the original event by `idempotency_key` (e.g. `payment.cleared:{payment_id}`).
2. Skip any calculations that already have an active reversal — re-runs are no-ops.
3. For each unreversed original calculation: write a new row with `is_reversal=true`, `amount = -original.amount`, `base_amount = -original.base_amount`, `reverses_calculation_id = original.id`, status=`payable`. The original is voided if it was still pending; left alone if already paid (the negative claws back from the next payout).

[CommissionForwardAndReversalTest](tests/Feature/Commission/CommissionForwardAndReversalTest.php) asserts:
- Forward path produces the right amount/rate/recipient.
- Refund produces a negative reversal whose `reverses_calculation_id` points back; the original's `amount` is untouched.
- Two reversal events for the same original produce only ONE reversal calculation (idempotent on the calculation level, not just the event level).
- No assignment → no calculation, even if a matching rule exists.

**Listeners** — [OnPaymentClearedListener](app/Modules/Commission/Application/Listeners/OnPaymentClearedListener.php), [OnPaymentRefundedListener](app/Modules/Commission/Application/Listeners/OnPaymentRefundedListener.php), [OnChargebackOccurredListener](app/Modules/Commission/Application/Listeners/OnChargebackOccurredListener.php), [OnDealClosedWonListener](app/Modules/Commission/Application/Listeners/OnDealClosedWonListener.php). Wire-up is in [CommissionServiceProvider](app/Modules/Commission/CommissionServiceProvider.php).

**Payouts** — [PayoutService::buildForPeriod](app/Modules/Commission/Application/Services/PayoutService.php):
```
total_earned     = sum of positive non-reversal calculations in period
total_reversed   = sum of |negative| reversal calculations
total_adjustments = sum of CommissionAdjustment rows in period (signed)
net_payable      = earned − reversed + adjustments
```
Idempotent rebuild: re-running `buildForPeriod` updates a draft payout in place; refuses to mutate approved/paid rows. [PayoutBuildTest](tests/Feature/Commission/PayoutBuildTest.php) verifies the math with mixed earnings, reversals, and adjustments.

API:
```
GET    /api/commission/payouts
POST   /api/commission/payouts/build           supervisor-only
GET    /api/commission/payouts/{id}
GET    /api/commission/payouts/{id}/calculations
POST   /api/commission/payouts/{id}/approve    supervisor-only
POST   /api/commission/payouts/{id}/mark-paid  supervisor-only
GET    /api/commission/adjustments
POST   /api/commission/adjustments             supervisor-only (spiffs / claw-backs)
```

### End-to-end flow (Response 4 wires the full sales lifecycle)

```
DialLeadJob (R3) → guardrail → Twilio call placed
  ↓ (call ends, agent sets disposition)
Lead status → qualified/pitch_presented (R2)
  ↓
Deal created (R4 — POST /api/deals)
  ↓
Inventory hold acquired (R4 — POST /api/inventory/holds)
  ↓
Booking confirmed (R4 — POST /api/bookings/from-hold/{id})
  ↓
Payment charged (R4 — POST /api/payments/charge)
  ↓
Stripe payment_intent.succeeded webhook (R4 — POST /api/webhooks/stripe)
  ↓ webhook_events idempotent ingest
PaymentCleared event fires
  ↓ OnPaymentClearedListener
commission_event "payment.cleared:{payment_id}" appended (idempotent)
  ↓ CommissionEngine::process
commission_calculation row written with status=payable
  ↓ (later, end of period)
PayoutService::buildForPeriod rolls calculations into a payout
  ↓ supervisor approves
PayoutService::approve → PayoutService::markPaid
  ↓ (later, if chargeback)
charge.dispute.created webhook → ChargebackOccurred event
  ↓ OnChargebackOccurredListener
commission_event "payment.chargeback:{cb_id}" appended
  ↓ CommissionEngine::reverseFromEvent
NEGATIVE commission_calculation written, reverses_calculation_id set
  ↓ next payout build
Reversal nets against future earnings (audit trail preserved)
```

### Tests added in Response 4

- `Feature/Booking/DoubleBookingPreventionTest` — race scenario, expiration sweep, status guards
- `Feature/Booking/BookingFromHoldTest` — happy-path confirm, cancellation creates fresh available row
- `Feature/Sales/DealStageAdvancementTest` — transitions, ClosedWon event firing, no-op on same stage
- `Feature/Payment/StripeWebhookIdempotencyTest` — valid event sets cleared_at, duplicate event ids dedup, 403 on bad signature
- `Feature/Commission/CommissionForwardAndReversalTest` — the structural properties: forward math, reversal preserves original, idempotent reversal, no calc without assignment
- `Feature/Commission/PayoutBuildTest` — period rollup math (earned − reversed + adjustments)
- `Unit/Commission/RuleEvaluatorTieredTest` — flat-mode tier matching and true-marginal bracket splitting

`tests/Support/FakePaymentGateway` mirrors the FakeTelephonyProvider pattern from Response 3.

### Operational notes

- **Stripe webhook secret** — set `STRIPE_WEBHOOK_SECRET` in `.env`. The body must reach the controller raw (Laravel JSON middleware would corrupt the HMAC); we read `$request->getContent()` inside the controller, which is safe regardless of `Content-Type`.
- **Commission plan setup** — on first deploy, an operator creates a CommissionPlan, attaches CommissionPlanRules (e.g. closer 10% on payment.cleared), and assigns users via CommissionAssignment. Without an assignment, no calculations are produced — by design, so an unconfigured tenant doesn't pay agents random amounts.
- **Booking confirmation number collisions** — `BK-{8-char}` from a 32-character alphabet has ~10^12 unique strings. The `bookings.confirmation_number` UNIQUE constraint catches the unlikely collision; callers should retry on the violation. (Not yet wired as automatic retry — operationally a non-event, but a future polish item.)
- **PCI audio scope** — calls have `recording_paused_at` (Response 3 schema). The agent UI must call `POST /api/calls/{id}/pause-recording` before starting card capture; the contract is in [TelephonyProvider::pauseRecording](app/Modules/CallCenter/Infrastructure/Telephony/TelephonyProvider.php). Response 5's UI wires this on the payment screen.

---

## What's in Response 5

### Frontend bootstrap

- [package.json](package.json), [vite.config.ts](vite.config.ts), [tsconfig.json](tsconfig.json), [tailwind.config.js](tailwind.config.js), [postcss.config.js](postcss.config.js).
- TypeScript-first Vue 3 + Inertia + Pinia + Laravel Echo (Pusher protocol → Soketi locally, managed Pusher / Reverb in prod).
- [resources/views/app.blade.php](resources/views/app.blade.php) is the Inertia root template; [resources/js/app.ts](resources/js/app.ts) auto-resolves page components from `resources/js/Pages/`.
- [resources/js/types/api.ts](resources/js/types/api.ts) mirrors the Laravel Resource shapes — single source of truth for API wire formats.

### Real-time broadcasting

Domain events stay broadcast-naive. [BroadcastDomainEvents](app/Core/Shared/Broadcasting/BroadcastDomainEvents.php) is a single subscriber that translates `CallInitiated`, `CallConnected`, `CallEnded`, `AgentStatusChanged`, and `DialSkipped` into wire-format events ([CallEventBroadcast](app/Core/Shared/Broadcasting/CallEventBroadcast.php), [AgentPresenceBroadcast](app/Core/Shared/Broadcasting/AgentPresenceBroadcast.php), [DialSkippedBroadcast](app/Core/Shared/Broadcasting/DialSkippedBroadcast.php)) and pushes them onto two private channels:

- `tenant.{tid}.agent.{aid}` — the agent's own dialer console subscribes
- `tenant.{tid}.supervisor` — the war room subscribes

Channel auth in [routes/channels.php](routes/channels.php). Subscribers verified by tenant + role + ownership. Inertia ships per-deploy Pusher config into every page via [HandleInertiaRequests](app/Core/Shared/Http/Middleware/HandleInertiaRequests.php).

### Dialer Console — the agent's battle station

[resources/js/Pages/Dialer/Console.vue](resources/js/Pages/Dialer/Console.vue) is the entire screen. One-page layout, no scrolling, sub-200ms transitions. Composed of:

- [LeadInfoPanel](resources/js/Components/Dialer/LeadInfoPanel.vue) — name, phone in big mono font, score, priority, consent status, DNC flag warning
- [CallControlPanel](resources/js/Components/Dialer/CallControlPanel.vue) — 5xl call timer (driven by [useCallTimer](resources/js/Composables/useCallTimer.ts), local-only — no server polling), status indicator, mute/hold/transfer/end buttons
- [ScriptPanel](resources/js/Components/Dialer/ScriptPanel.vue) — dynamic script sections by lead source (inbound/referral/cold)
- [DispositionPanel](resources/js/Components/Dialer/DispositionPanel.vue) — 10-button grid with **keyboard shortcuts (1–8, S, T)** for one-handed wrap-up; notes textarea; auto-blocks shortcuts while typing in notes
- [SessionStatusBar](resources/js/Components/Dialer/SessionStatusBar.vue) — start/pause/resume/stop + live counters

State sources:
- REST for initial snapshot ([useDialerSession](resources/js/Composables/useDialerSession.ts), [useActiveCall](resources/js/Composables/useActiveCall.ts))
- WebSocket for transitions — [useEcho](resources/js/Composables/useEcho.ts) auto-cleans subscriptions on unmount
- Local for the timer (no server round-trip per second)

### Supervisor War Room

[resources/js/Pages/Supervisor/WarRoom.vue](resources/js/Pages/Supervisor/WarRoom.vue) — three-column live layout backed by [useSupervisorChannel](resources/js/Composables/useSupervisorChannel.ts):

- [MetricsRibbon](resources/js/Components/Supervisor/MetricsRibbon.vue) — 8 cards (available/on-call/wrap-up/break/offline counts + revenue/conversion/calls today)
- [AgentTile](resources/js/Components/Supervisor/AgentTile.vue) — color-coded, `animate-pulse` on call. Whisper / Kill controls inline
- [LiveCallsFeed](resources/js/Components/Supervisor/LiveCallsFeed.vue) — chronological event stream (capped at 100)
- [AlertsPanel](resources/js/Components/Supervisor/AlertsPanel.vue) — guardrail rejections, capped at 50

### Other pages

- [Login](resources/js/Pages/Login.vue) — Sanctum CSRF cookie + login flow
- [Dashboard](resources/js/Pages/Dashboard.vue) — landing tiles
- [Pipeline](resources/js/Pages/Pipeline/Index.vue) — stage columns with quick "→ next stage" advance
- [Booking/Search](resources/js/Pages/Booking/Search.vue) — inventory search with hold action; surfaces 409 `unit_no_longer_available`
- [Payment/Capture](resources/js/Pages/Payment/Capture.vue) — Stripe Elements; pauses call recording around card capture (PCI)

### Inertia controllers + routes

[InertiaPageController](app/Core/Shared/Http/Controllers/InertiaPageController.php) renders the pages. [routes/web.php](routes/web.php) gates everything behind `auth:sanctum` + `tenant`. The supervisor war room has an extra role check inside the controller.

### k6 load tests

Three scenarios in [tests/load/k6/](tests/load/k6/):

| Script | Targets |
|---|---|
| [agent-session.js](tests/load/k6/agent-session.js) | 1,000 concurrent agents holding a 5 min session, p95 < 200ms, errors < 1% |
| [pacing-tick.js](tests/load/k6/pacing-tick.js) | Compliance guardrail at 5,000 RPS, p99 < 50ms — proves the gate isn't the bottleneck |
| [webhook-flood.js](tests/load/k6/webhook-flood.js) | Twilio webhooks at 2,000 RPS, idempotency holds (post-run SQL verification) |

Setup, runbook, and failure-mode interpretation in [tests/load/README.md](tests/load/README.md).

### CI + deployment

- [.github/workflows/ci.yml](.github/workflows/ci.yml) — MySQL 8 + Redis service containers, Pint + Pest + Vite build on every PR
- [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) — Laravel Cloud, self-host Docker, and CI guidance + ops runbooks (federal DNC import, dialer pause, webhook replay, payout rebuild)
- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) — module dependency graph, the five structural properties, queue + Redis topology, where to add new features

### Demo seeders

`php artisan db:seed --class=DemoSeeder` populates a working tenant in seconds:

- 1 tenant: "Prime Vacations Demo"
- 1 admin / 1 supervisor / 1 QA / 3 closers / 2 fronters (all `password`)
- 4 resorts × 5 unit types × 8 weeks of availability
- 200 leads spread across statuses
- "Standard 10/2" commission plan: closer 10% + fronter 2% on payment.cleared
- Federal calling-window default ([BaseCallingWindowsSeeder](database/seeders/BaseCallingWindowsSeeder.php)) — required for the guardrail; seeded by `db:seed` even without `DemoSeeder`

```
admin@demo.test       Robert Hayes    (admin — full access)
supervisor@demo.test  Priya Anand     (supervisor — war room, dnc)
qa@demo.test          Lin Wei         (qa)
sofia@demo.test       Sofia Cruz      (top closer — has deals/payments)
marcus@demo.test      Marcus Webb     (closer)
jamie@demo.test       Jamie Rivera    (closer)
devon@demo.test       Devon Park      (fronter)
alex@demo.test        Alex Chen       (fronter)
                                       password: password
```

### Required external accounts before going live
- Twilio: account SID, auth token, a programmable voice number, webhook URL
- Stripe: API keys + webhook signing secret
- DocuSign: integration key, RSA private key for JWT auth
- AWS S3: bucket for call recordings + signed contracts

---

## Architecture decisions worth flagging

**Why modular monolith, not microservices.** Call center workflows are heavily transactional and cross-module: a call references a lead, creates a deal, holds inventory, takes a payment, generates a commission event, and writes audit logs — all in one human session. Network hops between services here means saga complexity, eventual consistency on workflows that need to be immediate, and 10× the operational surface. We get most of the modularity benefits with one deployment unit.

**Why MySQL (was Postgres).** The original design picked Postgres for three things MySQL didn't match cleanly: partial unique indexes (double-booking prevention; soft-delete-safe lead phone uniqueness), JSONB with GIN indexes (lead source metadata, sentiment timelines, audit log changes), and advisory locks (hold acquisition). The project moved to MySQL 8 on 2026-05-09 (operational reasons), and each of those got an engine-portable replacement:
- Partial unique indexes → row-lock-plus-status-check inside the transaction (see the Booking module section). The `HoldService` still catches `UniqueConstraintViolationException` so the index can be reintroduced on engines that support it without code changes.
- JSONB + GIN → MySQL 8 `json` columns with functional indexes where the query plan needs them. The query shape is unchanged; GIN's wide-key scans aren't on a hot path in this app.
- Advisory locks → `HoldService::acquireAdvisoryLock` dispatches per driver: `pg_advisory_lock` on Postgres, `GET_LOCK`/`RELEASE_LOCK` on MySQL, no-op on SQLite. Returns a release closure the caller invokes in `finally`.

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
4. **Frequency cap** (`FrequencyCapService`) — three conditional aggregates over `contact_attempts` covering cooldown (default 4h), daily cap (default 3), and 30-day cap (default 7). Originally written as a single Postgres `COUNT(*) FILTER` aggregate; the MySQL port uses three `SUM(CASE WHEN … THEN 1 ELSE 0 END)` expressions in the same query, against the same composite index.
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

Tests run against MySQL 8 (matches production). The schema uses MySQL `json` columns and conditional-sum aggregates; some tests will skip on SQLite. See [Running tests](#running-tests) below.

---

## Deploying to Laravel Cloud

This repo is structured to deploy to [Laravel Cloud](https://cloud.laravel.com) with no Dockerfile/buildpack changes — Cloud auto-detects the Laravel 11 layout and builds via FrankenPHP + Octane. The local `Dockerfile` and `docker-compose.yml` are for self-hosting and are ignored by Cloud.

### One-time setup

1. **Create the app** in Cloud's UI from this repo (`primeeventsource-bit/PrimeCRM-v2`, branch `main`).
2. **Attach a MySQL 8 database** and a **Redis (KeyDB)** instance — Cloud injects `DB_HOST`/`DB_PASSWORD`/`REDIS_HOST`/`REDIS_PASSWORD` automatically.
3. **Generate `APP_KEY`**: in Cloud's env editor add `APP_KEY=` and click "Generate", or paste output of `php artisan key:generate --show`.

### Required environment variables (set in Cloud's UI)

| Var | Value | Notes |
|---|---|---|
| `APP_ENV` | `production` | |
| `APP_DEBUG` | `false` | |
| `APP_URL` | `https://<your-cloud-domain>` | Cloud assigns this |
| `APP_KEY` | `base64:...` | generate once, never rotate without re-encrypting sessions |
| `DB_CONNECTION` | `mysql` | Cloud-attached MySQL 8 |
| `DB_PORT` | `3306` | MySQL default |
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

- **No `composer.lock`** committed — Cloud generates one on first build. To pin versions, commit a lockfile generated by `composer install` locally.
- **No Soketi on Cloud** — Soketi is local-only. On Cloud, leave `PUSHER_HOST` blank to fall back to managed Pusher, or run **Laravel Reverb** as a fourth process (`php artisan reverb:start --host=0.0.0.0 --port=$PORT`).
- **No SSE fallback** — WebSocket-only. The Inertia pages still work without live updates if WS auth fails; they just don't get real-time pushes.

### Health check

`bootstrap/app.php` registers `/up` as the health-check endpoint. Cloud probes this automatically — no extra config needed.
