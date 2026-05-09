# Architecture

A visual + textual map of how the modules cooperate.

## Module dependency graph

```
                        ┌─────────────┐
                        │  Tenant     │  ← root: tenants, users, auth
                        └──────┬──────┘
                               │
       ┌───────────────────────┼───────────────────────┐
       │                       │                       │
   ┌───▼────┐             ┌────▼─────┐         ┌───────▼────┐
   │  Lead  │             │Compliance│         │ CallCenter │
   └───┬────┘             └────┬─────┘         └───┬────────┘
       │                       │                   │
       │   ┌──────────┐        │                   │
       └──►│  Dialer  │◄───────┴───────────────────┘
           └────┬─────┘   guardrail.mayDial() called HERE only
                │
                ▼ (lead → call → deal → booking → payment → commission)
       ┌────────────┐    ┌────────────┐   ┌────────────┐   ┌───────────────┐
       │   Sales    │───►│  Booking   │──►│  Payment   │──►│  Commission   │
       └────────────┘    └────────────┘   └─────┬──────┘   └───────▲───────┘
                                                │                  │
                                                └──────────────────┘
                                                  PaymentCleared event
                                                  → CommissionEvent
                                                  → CommissionCalculation
```

## Structural properties (what the architecture guarantees)

These properties are enforced by the schema or the type system, not by
"please remember to" comments. Each one has a test that locks it in.

### 1. Tenant isolation
- Every tenant-scoped model uses `TenantScoped`. The global scope filters
  every query to the resolved tenant; with no tenant resolved, the scope
  returns an empty set rather than leaking.
- Queued jobs use `AppliesTenantContext` to capture the tenant at dispatch
  and re-apply it inside the worker.

### 2. Compliance gating
- `DialLeadJob` is the **only** code path that places an outbound call.
  Its first I/O after loading the lead is `$guardrail->mayDial(...)`.
- The manual click-to-call route (`POST /api/dialer/sessions/{id}/dial-now`)
  also runs the guardrail before delegating.
- Test: `DialLeadJobGuardrailTest` asserts zero calls placed on every
  rejection path; exactly one on happy path.

### 3. Webhook idempotency
- Twilio + Stripe + DocuSign webhooks all dedupe via `webhook_events`'s
  unique `(provider, external_id)` constraint. The DB rejects duplicates
  even if application code has a bug.
- Test: `WebhookIdempotencyTest`, `StripeWebhookIdempotencyTest`.

### 4. Double-booking prevention
- Two layers: Postgres `pg_advisory_xact_lock` per (unit, check_in_date),
  plus the partial unique index `inventory_availability_one_active`.
- Test: `DoubleBookingPreventionTest`.

### 5. Append-only commission accounting
- `commission_events` is append-only, idempotency-keyed.
- Reversals are **negative** `commission_calculations` referencing originals
  via `reverses_calculation_id`. Originals are never mutated.
- Test: `CommissionForwardAndReversalTest` asserts the original's amount
  is preserved post-reversal.

## Layered structure (per module)

Each module follows DDD-flavoured layering:

```
app/Modules/{Module}/
├── Domain/                    pure business logic
│   ├── Models/               Eloquent aggregates
│   ├── Events/               domain events (Dispatchable; no broadcasting)
│   ├── ValueObjects/
│   ├── Enums/
│   └── Exceptions/
├── Application/              orchestration
│   ├── Services/             stateless services
│   ├── Actions/              one-shot use cases
│   ├── Jobs/                 queued
│   ├── Listeners/            event handlers
│   └── DTOs/
├── Infrastructure/           external dependencies
│   ├── Repositories/         data access (where it's not a model)
│   ├── Gateway/              third-party SDK wrappers (Stripe, Twilio)
│   └── Persistence/
├── Http/                     web layer
│   ├── Controllers/
│   ├── Requests/             form requests = validation + auth
│   └── Resources/            wire format
├── routes.php                per-module API routes (auto-mounted under /api)
└── {Module}ServiceProvider.php
```

Cross-module communication is via:
- **Domain events** (in-process) for sync flows
- **Queued jobs** for async work
- **HTTP API** when one module's action is initiated by another via routes

Modules **never** import each other's `Application/` or `Infrastructure/`
classes. They import each other's `Domain/Models/` (a Booking knows what
a Deal is) and `Domain/Events/` (commission listens for `PaymentCleared`),
nothing else.

## Queue topology

Six Horizon supervisors with bounded concurrency
([config/horizon.php](../config/horizon.php)):

| Supervisor | Procs | Queues |
|---|---|---|
| `supervisor-dialer` | 50 | dialer, calls |
| `supervisor-webhooks` | 30 | webhooks, webhooks-twilio, webhooks-stripe |
| `supervisor-leads` | 20 | lead-import, lead-scoring, lead-assignment |
| `supervisor-background` | 20 | recordings, contracts, reports |
| `supervisor-commissions` | 10 | commissions |
| `supervisor-default` | 10 | default, notifications |

Job → queue assignment is centralized: jobs reference
`config('queue.names.dialer')` rather than string literals, so the
supervisor list and the job dispatchers never drift.

## Redis topology

| Logical DB | Purpose |
|---|---|
| 0 | Queue (Horizon) |
| 1 | Cache (`AgentPerformanceRepository`, calling-window rules) |
| 2 | Dialer hot path (agent presence, lead queues, pacing counters) |
| 3 | Broadcasting state |

The dialer DB sees the highest write rate; isolating it stops it from
pushing other workloads' keys out via LRU.

## Real-time topology

```
Domain event (CallInitiated, CallEnded, AgentStatusChanged, DialSkipped)
    │
    ▼
BroadcastDomainEvents listener (subscribed in AppServiceProvider)
    │
    ▼
Wraps in {CallEvent|AgentPresence|DialSkipped}Broadcast (ShouldBroadcast)
    │
    ▼
Pusher / Soketi / Reverb
    │
    ├──► tenant.{tid}.agent.{aid}      → Dialer Console subscribes
    └──► tenant.{tid}.supervisor       → War Room subscribes
```

The domain layer stays broadcast-naive. Tests bypass broadcasting via
`BROADCAST_CONNECTION=log` in `phpunit.xml`.

## End-to-end revenue flow

```
1. Lead lands              POST /api/leads          (R2)
2. Lead assigned           AutoAssignNewLead listener (R2)
3. Dialer queues lead      LeadQueueService.refill (R3)
4. Pacing tick             PacingEngine.decide → DialLeadJob (R3)
5. Compliance gate         ComplianceGuardrailService.mayDial (R2/R3)
6. Twilio call placed      InitiateCallAction → TelephonyProvider (R3)
7. Webhook → connected     ProcessTwilioStatusWebhookJob (R3)
8. Disposition: pitched    POST /api/calls/{id}/disposition (R3)
9. Deal created            POST /api/deals (R4)
10. Hold acquired          POST /api/inventory/holds (R4)
11. Booking confirmed      POST /api/bookings/from-hold/{id} (R4)
12. Payment captured       POST /api/payments/charge (R4)
13. Stripe webhook         ProcessStripeWebhookJob → PaymentCleared (R4)
14. Commission event       OnPaymentClearedListener → CommissionEvent (R4)
15. Calculation written    CommissionEngine.process (R4)
16. Period payout built    PayoutService.buildForPeriod (R4)
17. Approved + paid        POST /api/commission/payouts/{id}/approve|mark-paid (R4)
18. (chargeback later)     OnChargebackOccurredListener → reversal calc (R4)
```

Every arrow is a tested code path.

## Where to add a new feature

| Feature type | Where it goes |
|---|---|
| New routing rule | `LeadAssignmentService` + new `assignment.mode` config |
| New telephony provider | New class implementing `TelephonyProvider` |
| New gateway (Authorize.Net) | New class implementing `PaymentGateway` |
| New commission rule type | `CommissionPlanRule::TYPE_*` constant + `RuleEvaluator` switch |
| New webhook source | `WebhookEventStore::ingest('myProvider', ...)`; mirror Twilio's pattern |
| New Inertia page | `resources/js/Pages/{Module}/...` + route in `routes/web.php` via `InertiaPageController` |
| New WebSocket event | Subclass `ShouldBroadcast`; add to `BroadcastDomainEvents` subscribe |
