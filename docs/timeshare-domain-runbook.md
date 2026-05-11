# Timeshare Domain Build-Out — Runbook

This document covers operating the timeshare-listing-domain stack that
landed across sprints D1–D9 on the `feature/timeshare-domain-buildout`
branch.

> The CRM is not a generic sales CRM. It is purpose-built for one
> business: helping timeshare owners rent out unused weeks by listing
> the property on multiple partner marketing sites for an upfront
> listing fee. Every entity name and every metric reflects that.

---

## 1. The data model at a glance

| Table | Purpose |
|---|---|
| `leads` | The owner — pre-sale lead row also serves as post-sale owner identity. |
| `properties` | A timeshare the owner holds. Carries durable facts (resort, week, ownership type, verification status). |
| `deals` | The **listing agreement** (augmented in D1). Same table; the UI reads it as "listing agreement". |
| `listings` | A marketed offering — one property's specific weeks. Linked to a paid deal. |
| `partner_sites` | The external marketing channels we push to (Airbnb / Vrbo / RedWeek / SMTN / Timeshares.com). |
| `partner_site_listings` | Per-listing-per-site distribution row with status, view counts, inquiry counts. |
| `rental_inquiries` | Renter expressing interest in a listing. |
| `bookings` | A confirmed rental (renter side). Augmented in D1 with `listing_id`, `renter_*`, `our_commission`, `owner_notified_at`. |
| `compliance_recordings` | Per-call disclosure marker matrix. One row per closing call. |
| `refund_cases` | Workflow on a refund request — investigation + decision audit trail. |
| `chargeback_cases` | Processor dispute workflow with respond-by deadlines. |

See [Strategy B in §4 of the discovery report](../README-timeshare.md) for
the reconciliation rationale: rather than introduce `listing_agreements`
as a parallel table, we augment `deals` and keep the commission engine
intact.

---

## 2. Running the demo locally

```bash
# Fresh database with the timeshare-domain seed:
php artisan migrate:fresh --seed

# Or — re-run just the demo without wiping migrations:
php artisan db:seed --class=DemoSeeder --force
```

The seeder is **idempotent on the demo tenant** — it deletes any prior
demo tenant (by slug AND by any seed-known email) before re-creating.
Production tenants stay untouched.

Demo logins (password is always `password`):
- `admin@demo.test` — full access
- `supervisor@demo.test` — war room, compliance hub, partner-sites
- `sofia@demo.test`, `marcus@demo.test`, `jamie@demo.test` — closers
- `devon@demo.test`, `alex@demo.test` — fronters

---

## 3. The eight sprints, what they ship

| Sprint | Commit | What landed |
|---|---|---|
| **D1** | `0b75a33` | Domain migrations (Property, Listing, PartnerSite, RentalInquiry, ComplianceRecording, RefundCase, ChargebackCase); augmented `deals` for listing-agreement fields; augmented `bookings` for renter side; seeder with realistic timeshare data. |
| **D2** | `f042b2f` | Owner profile `/owners/{id}` — customer-service screen with the full dossier: properties, listings (with partner-site dots), agreements, renter bookings, financial ledger, open cases. |
| **D3** | `1cba121` | Listings hub `/listings` (tabbed: pending / live / inquiries / booked / expired / all) and detail `/listings/{id}` with the partner-site grid. |
| **D4** | `7e7830b` | Distribution driver pattern + push mechanism. Mock driver simulates a realistic lifecycle (80% live, 15% pending, 5% rejected). RedWeek driver reserved as the first real-integration slot. `/partner-sites` config page. Functional Re-push / Pause / Resume / Sync / Add-to-site on listing detail. |
| **D5** | `c56db32` | Bookings ledger `/bookings` with filters. Inquiry-to-booking flow (Respond / Mark lost / Book) with the owner-notification side effect. |
| **D6** | `2133287` | Compliance layer: 7-rule prohibited-phrase scanner, recording-review queue, refund + chargeback case workflows, **distribution gate** (`verificationGate`) that refuses push when TCPA + verification incomplete. Compliance hub `/compliance`. |
| **D7** | `6198d24` | Dashboard rebuild — new "Listing service health" section with Listings Live / Bookings This Week / Time-to-Live / Refund Rate KPIs, Partner Site Health table, Booking Pipeline funnel, Compliance Posture (with the **inverted closer leaderboard**), Owner Success Signals. |
| **D8** | `d8a1302` | AI Live Coach with the §6 system prompt verbatim, rules engine that gives compliance-rescue priority over every other suggestion, red-zone alert dispatcher (auto-flags recording + Note + structured log). LiveCoachPanel component drops into any page. |
| **D9** | (this commit) | AG-audit-ready evidence export, tests for the bright-line enforcement, this runbook. |

---

## 4. Compliance enforcement chain

The distribution gate is the **single point of enforcement** that
turns the disclosure checklist into a binding rule:

```
Closer takes payment
   │
   ▼
Deal: agreement_status = paid_pending_verification
   │
   ▼
Disclosure markers captured during call?
   ├─ no  → cannot move forward
   └─ yes ↓
Verifier callback completed?
   ├─ no  → status stays paid_pending_verification, distribution refused
   └─ yes ↓
Deal: agreement_status = verified_pending_listing
   │
   ▼
POST /api/listings/{id}/distributions → verificationGate() ALLOW
```

If any link in the chain breaks, every downstream `POST` to the
distribution endpoints returns **422** with a structured `code`:

| Code | Meaning |
|---|---|
| `tcpa_disclosure_missing` | The closing call didn't capture TCPA disclosures. |
| `verification_call_missing` | Verifier callback hasn't passed. |
| `agreement_terminal` | Agreement is cancelled / refunded / charged_back. |
| `no_agreement` | Listing has no underlying deal (data integrity error). |

`POST pause`, `POST resume`, `POST sync` are NOT gated — they don't
republish, so no new outbound representation occurs.

---

## 5. Generating an AG-audit evidence package

When compliance counsel or a regulator asks for the evidence trail on
a specific owner or agreement, the supervisor exports the package via:

```bash
# All agreements + recordings + distribution + bookings + cases for one owner
curl -H "Authorization: Bearer <token>" \
     "$BASE/api/compliance/audit-export/owner/<owner-uuid>" \
     -o audit-export-owner.json

# Same package, scoped to one agreement
curl -H "Authorization: Bearer <token>" \
     "$BASE/api/compliance/audit-export/agreement/<deal-uuid>" \
     -o audit-export-agreement.json
```

The JSON has top-level keys:

- `manifest` — who exported, when, scope
- `owner` — identity + consent records + DNC history
- `agreements` — disclosure-marker matrix per agreement
- `recordings` — call recording URLs + transcription + reviewer notes
- `financial` — every payment + refund + chargeback with totals
- `distribution` — every partner-site push with go-live timestamps
- `inquiries` — every renter inquiry + response history
- `bookings` — every renter booking that came through these listings
- `cases` — refund + chargeback cases with status histories
- `communications` — system Notes (including AI red-zone alerts)

The export is supervisor-only and tenant-scoped — every section
filters by `tenant_id` before any data leaves the database.

---

## 6. The inverted closer leaderboard

`GET /api/dashboard/compliance-posture` returns
`closer_refund_rates` — closers ranked by refund rate over the last
90 days, **worst first**. The dashboard renders this ordering
inverted from the standard leaderboard pattern:

```
Closers by refund rate · 90d
inverted leaderboard — bottom of list = coaching priority

Sofia Cruz       0.0%   (0/12)
Marcus Webb      2.4%   (1/41)
Jamie Rivera     8.7%   (3/35)   ← coaching priority
```

This makes "high close numbers AND high refund rates" visible as
the regulatory risk it is, rather than a top-performer signal.

---

## 7. AI Live Coach — adding a real LLM

The coach ships with a deterministic rules engine that runs without an
API key. To plug in a real LLM:

1. In `LiveCoachController::suggestion()`, after the rules engine call,
   add an `if (config('services.coach.driver') === 'anthropic') { ... }`
   block that calls the API with:

   - `system` = `CoachContextBuilder::systemPrompt()` + `callContext()`
   - `messages` = `[{ role: 'user', content: $utterance }]`
   - `max_tokens` = 200, timeout 1.5s

2. Replace the rule engine's hint with the model's response —
   **unless `$hint['priority'] === 'compliance_rescue'`**. The
   deterministic rescue script is the AG-defensible line we want
   the agent reading every time.

3. Cache by `md5($utterance)` for 30 seconds so the same line during
   a pause doesn't burn tokens.

4. Red-zone dispatch is unchanged — it's driven by the phrase
   scanner output, not the model.

---

## 8. Coordination with the parallel Prime Connect work

The `Prime Connect` (Twilio video) feature has been landing in
parallel on `main`. The two branches occasionally touch the same
files (`InertiaPageController`, `routes/web.php`, `SideNav.vue`).
On every D-sprint commit, the integrity check
`grep -cE "ownerShow|listingsIndex|..."` confirms my route methods
are intact before stage/commit. The patterns to watch for:

- After cherry-picking from parallel: re-run integrity check.
- If a file shows as modified in working tree without my touching it,
  it's the parallel session — `git checkout HEAD -- <file>` restores
  the branch's version, then re-apply my D-sprint changes.
- Both sides force-push the feature branch. Use `git fetch` then
  rebase rather than pull.

---

## 9. PR to `main` — merge checklist

When ready to land the branch:

1. Pull latest `main`, ensure `feature/timeshare-domain-buildout`
   rebased onto it cleanly.
2. Run the test suite: `composer test` (Pest, parallel).
   D9 ships these critical tests:
   - `tests/Unit/Compliance/ProhibitedPhraseScannerTest.php` — every
     bright-line rule has positive + negative coverage.
   - `tests/Unit/Compliance/CoachRuleEngineTest.php` — priority order
     is pinned; compliance rescue always wins.
   - `tests/Feature/Listing/DistributionGateTest.php` — the
     verification gate refuses push when disclosures are missing.
   - `tests/Feature/Compliance/RefundCaseWorkflowTest.php` — case
     state machine + audit-trail behaviour.
3. Run `php artisan migrate:fresh --seed` against a staging DB and
   verify the demo flow:
   - Sign in `admin@demo.test`
   - Open an owner profile → seven sections render with data
   - Open a listing → partner-site grid + action buttons functional
   - Open `/compliance` → all three queues populated
   - Open `/bookings` → ledger filters work
   - Dashboard's "Listing service health" section renders with non-zero numbers
4. Demo to compliance counsel before merging (per §8 of the spec).
5. Merge with `git merge --no-ff feature/timeshare-domain-buildout`
   so the branch boundary is preserved in history.

The PR description template lives in
[`.github/pull_request_template_timeshare.md`](../.github/pull_request_template_timeshare.md).
