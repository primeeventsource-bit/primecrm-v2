# Timeshare Listing Domain Build-Out (D1 → D9)

Lands the timeshare-rental-marketing domain across nine sprints on
the `feature/timeshare-domain-buildout` branch.

## Why this PR

The CRM was generic. The actual business is timeshare-owner listing
marketing: owners pay an upfront fee, we list their unused weeks on
multiple partner sites, and we earn commission when a renter books.
Every entity, every metric, and every workflow in this PR is shaped
to that operating model — not to a generic sales pipeline.

See [`docs/timeshare-domain-runbook.md`](../docs/timeshare-domain-runbook.md)
for the full sprint walkthrough.

## What's in the PR

| Sprint | Commit | What landed |
|---|---|---|
| **D1** | `0b75a33` | Domain migrations (properties, listings, partner_sites, partner_site_listings, rental_inquiries, compliance_recordings, refund_cases, chargeback_cases); augmented `deals` for listing-agreement fields; augmented `bookings` for renter side; demo seeder. |
| **D2** | `f042b2f` | Owner profile `/owners/{id}` — customer-service screen. |
| **D3** | `1cba121` | Listings hub `/listings` + detail `/listings/{id}`. |
| **D4** | `7e7830b` | Distribution driver pattern + push mechanism. RedWeek reserved as first real-integration slot. `/partner-sites` config page. |
| **D5** | `c56db32` | Bookings ledger `/bookings` + inquiry-to-booking flow. |
| **D6** | `2133287` | Compliance layer: phrase scanner, recording-review queue, refund + chargeback workflows, **distribution gate** that refuses push when TCPA + verification incomplete. |
| **D7** | `6198d24` | Dashboard rebuild — listing-service health alongside call floor. |
| **D8** | `d8a1302` | AI Live Coach with the §6 system prompt verbatim, compliance-rescue priority, red-zone alert dispatcher. |
| **D9** | (this commit) | AG-audit evidence export, tests for bright-line enforcement, runbook. |

## Architecture decisions

**Strategy B (augment, don't rename)** — `deals` table keeps its name
even though the UI reads it as "listing agreement". Avoids cascading
through the commission engine, the `OnDealClosedWon` listener, the
dashboard pipeline summary, and the existing kanban. Re-labels are
UI-only; backend wiring is preserved.

**MySQL stays.** Every migration is MySQL-safe (no `jsonb`, no
partial unique indexes, no `COUNT(*) FILTER`). The Postgres switch
was declined; see memory `db-must-be-postgres-not-mysql.md`.

**Driver pattern for partner sites.** Adding a real Airbnb/Vrbo/etc
integration is a three-line change (implement `PartnerDriver`, add
to slug map, configure credentials). MockPartnerDriver simulates a
realistic lifecycle so the demo flow works end-to-end.

**Compliance enforcement is server-side.** The distribution gate
(`ListingDistributionController::verificationGate`) is the single
load-bearing piece of regulatory enforcement: no listing can reach
a partner site unless TCPA disclosures are captured AND the verifier
callback is complete AND the agreement isn't in a terminal state.
The disclosure-checklist UI is the *agent affordance*; the gate is
the *enforcement*.

## What the PR does NOT include

- **No real LLM API call** — the coach architecture is ready, but
  the Anthropic/OpenAI HTTP integration is reserved for its own
  sprint with auth/retry/rate-limit/caching/telemetry. The rules
  engine ships compliance-rescue scripts deterministically; those
  always win over a model hint.
- **No real partner-site API integrations** — RedWeek driver is the
  reserved slot; it currently delegates to mock. Each real one is
  a separate integration project.
- **No payment-processor changes** — assumes existing Stripe wiring.
- **No native mobile** — explicitly out of scope per §9 of the spec.
- **No state-specific compliance addenda** (FL 721.20, CA) — those
  want a per-state config table; reserved for a follow-up.

## Testing

```bash
composer test
```

Key tests:
- `tests/Unit/Compliance/ProhibitedPhraseScannerTest.php` — every
  bright-line rule has positive + negative coverage.
- `tests/Unit/Compliance/CoachRuleEngineTest.php` — priority is
  pinned; compliance rescue always wins.
- `tests/Feature/Listing/DistributionGateTest.php` — verification
  gate refuses push when disclosures are missing.
- `tests/Feature/Compliance/RefundCaseWorkflowTest.php` — case
  state machine + audit-trail behaviour.

## Manual demo path

After merge + deploy + `php artisan db:seed --class=DemoSeeder`:

1. Sign in as `admin@demo.test` / `password`.
2. Open an owner from `/leads` → owner profile shows seven sections.
3. Open `/listings` → tabbed hub with partner-site distribution dots.
4. Open `/listings/{id}` → Re-push / Pause / Sync / Add-to-site work.
5. Open an inquiry on a live listing → Respond / Book → confirm the
   listing flips to `booked` and owner_notified_at gets stamped.
6. Open `/bookings` → ledger filters work; owner-notified % displays.
7. Open `/compliance` → recording queue + refund cases + chargebacks
   each have stats panels and workflow buttons.
8. Open `/dashboard` → scroll to "Listing service health" section
   below the call-floor surface; all 5 panels render real numbers.
9. On owner profile, click **📂 Audit export** → JSON dossier
   downloads with the full evidence trail.

## Risks + watchpoints

- **Parallel session coordination.** The Prime Connect (Twilio
  video) work has been landing on `main` in parallel. Both branches
  touched `routes/web.php`, `InertiaPageController`, and `SideNav.vue`.
  Each D-sprint commit verified its routes survived; re-verify after
  any post-merge rebase.

- **Distribution gate strictness.** Cannot distribute without
  verification = correct behaviour by design. If staging shows
  unexpected 422s, check that DemoSeeder's `augmentDealsForListingAgreements`
  ran (it sets `tcpa_disclosure_completed` + `verification_call_completed`).

- **Audit export file size.** For owners with extensive history, the
  JSON payload can be large. Today it's served as a single JSON
  blob; if it ever exceeds 5MB we should chunk into a zip with a
  manifest file + per-section files.

## Demo to compliance counsel before merge

Per §8 D9 DoD: "All tests green; demo to compliance counsel before
merge." The disclosure-capture and audit-export flow should be
reviewed against the relevant state statutes (FL 721.20, CA VOTSA)
before landing in production.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
