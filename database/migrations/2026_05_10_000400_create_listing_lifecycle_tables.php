<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The fulfillment + compliance side of the listing-marketing domain.
 *
 *   rental_inquiries       A potential renter expressed interest in a
 *                          listing via a partner site. The pipeline
 *                          between "live" and "booked".
 *
 *   compliance_recordings  Per-call disclosure capture for sales-floor
 *                          calls that closed a listing fee. Linked to
 *                          the existing `calls` table — we don't
 *                          duplicate the recording asset, only the
 *                          disclosure-pass markers.
 *
 *   refund_cases           Workflow for an owner asking for their fee
 *                          back. Distinct from the row-level Payment
 *                          refund — this orchestrates the investigation
 *                          and decision, not the financial event.
 *
 *   chargeback_cases       Processor disputes (Stripe etc). The
 *                          regulatory-tail end of the same pattern:
 *                          we have N days to assemble evidence.
 *
 * MySQL-safe: json (not jsonb), full unique indexes, all index
 * identifiers <= 64 chars.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_inquiries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('listing_id');
            $table->uuid('partner_site_id')->nullable();      // null = direct / unknown channel

            $table->string('renter_name');
            $table->string('renter_email')->nullable();
            $table->string('renter_phone', 30)->nullable();
            $table->date('requested_check_in')->nullable();
            $table->date('requested_check_out')->nullable();
            $table->decimal('offered_amount', 10, 2)->nullable();
            $table->text('message')->nullable();

            $table->string('status')->default('new');
            // new, responded, negotiating, booked, lost
            $table->uuid('handled_by')->nullable();
            $table->timestamp('responded_at')->nullable();

            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('listing_id')->references('id')->on('listings')->cascadeOnDelete();
            $table->foreign('partner_site_id')->references('id')->on('partner_sites')->nullOnDelete();
            $table->foreign('handled_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['tenant_id', 'status'], 'inquiries_status_idx');
            $table->index(['tenant_id', 'listing_id', 'status'], 'inquiries_listing_status_idx');
            $table->index(['tenant_id', 'created_at'], 'inquiries_created_idx');
        });

        // Wire the bookings.inquiry_id FK now that rental_inquiries
        // exists. The augment_bookings migration created the column;
        // we add the FK here to avoid forward-reference order issues.
        Schema::table('bookings', function (Blueprint $table): void {
            $table->foreign('inquiry_id')
                ->references('id')->on('rental_inquiries')
                ->nullOnDelete();
        });

        // Per-call disclosure capture. One row per closed sales call;
        // the recording asset itself stays on the parent calls.row
        // (recording_url, recording_s3_path, transcription_text). We
        // only carry the pass/fail markers + reviewer state here.
        Schema::create('compliance_recordings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('call_id');                          // FK -> calls (the recording lives there)
            $table->uuid('deal_id')->nullable();              // listing-fee agreement, if known
            $table->uuid('user_id');                          // the agent on the call

            // Mandatory disclosures — populated by transcript scanner
            // or manually ticked by reviewers.
            $table->boolean('tcpa_consent_captured')->default(false);
            $table->boolean('recording_disclosure_made')->default(false);
            $table->boolean('no_guarantee_disclosure_made')->default(false);
            $table->boolean('refund_policy_disclosure_made')->default(false);
            $table->boolean('total_fee_stated_clearly')->default(false);

            // Per-disclosure timestamp offsets in milliseconds from
            // call start. Helps the reviewer jump-to-section in the
            // recording playback.
            $table->json('disclosure_timestamps')->nullable();

            // Review state
            $table->string('compliance_status')->default('pending_review');
            // pending_review, passed, failed, flagged_for_audit
            $table->uuid('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();

            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('call_id')->references('id')->on('calls')->cascadeOnDelete();
            $table->foreign('deal_id')->references('id')->on('deals')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['tenant_id', 'compliance_status'], 'cr_status_idx');
            $table->index(['tenant_id', 'user_id', 'compliance_status'], 'cr_agent_status_idx');
            $table->index(['tenant_id', 'deal_id'], 'cr_deal_idx');
            // One compliance record per call — we may revisit this if
            // we ever split a call into multiple recordable segments.
            $table->unique(['call_id'], 'cr_call_unique');
        });

        Schema::create('refund_cases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('deal_id');                          // the listing agreement
            $table->uuid('opened_by');                        // who opened the case

            $table->decimal('refund_amount', 10, 2);
            $table->string('reason');
            // no_renter_found, service_not_delivered,
            // misrepresentation_claim, owner_changed_mind,
            // duplicate_charge, unauthorized, other
            $table->text('owner_statement')->nullable();
            $table->string('status')->default('opened');
            // opened, investigating, approved, denied, processed,
            // escalated_to_chargeback

            $table->timestamp('opened_at');
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('deal_id')->references('id')->on('deals')->cascadeOnDelete();
            $table->foreign('opened_by')->references('id')->on('users');

            $table->index(['tenant_id', 'status'], 'refund_cases_status_idx');
            $table->index(['tenant_id', 'opened_at'], 'refund_cases_opened_idx');
            $table->index(['tenant_id', 'deal_id'], 'refund_cases_deal_idx');
        });

        Schema::create('chargeback_cases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('deal_id');                          // the listing agreement

            $table->string('processor_case_id');              // Stripe dispute ID etc
            $table->decimal('disputed_amount', 10, 2);
            $table->string('reason_code', 20);                // 4853, 4855
            $table->date('respond_by_date');
            $table->string('status')->default('received');
            // received, evidence_gathering, evidence_submitted, won, lost

            // List of evidence artifacts assembled for the response —
            // recording IDs, signed contract IDs, partner site
            // screenshots, etc. The shape stays free-form so we can
            // iterate without a schema change.
            $table->json('evidence_attached')->nullable();

            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('deal_id')->references('id')->on('deals')->cascadeOnDelete();

            $table->index(['tenant_id', 'status', 'respond_by_date'], 'cb_status_due_idx');
            $table->unique(['tenant_id', 'processor_case_id'], 'cb_processor_case_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chargeback_cases');
        Schema::dropIfExists('refund_cases');
        Schema::dropIfExists('compliance_recordings');

        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropForeign(['inquiry_id']);
        });

        Schema::dropIfExists('rental_inquiries');
    }
};
