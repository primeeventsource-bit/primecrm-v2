<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Augment `deals` with listing-agreement semantics.
 *
 * Per the timeshare-listing domain reconciliation: the `deal` row IS
 * the signed listing agreement. Rather than rename the table (which
 * would cascade through the commission engine, the
 * Sales\Domain\Events\DealClosedWon listener, the demo seeder, the
 * dashboard's pipeline summary, and the existing pipeline kanban),
 * we keep the table name and add the columns the listing domain needs.
 * The UI re-labels deals as "listing agreements"; the backend keeps
 * its existing wiring intact.
 *
 * The `stage` column stays. We add `agreement_status` alongside it as
 * the listing-fulfillment state machine: a deal can be `closed_won`
 * (sales view) AND `verified_pending_listing` (fulfillment view) at
 * the same time. Two state machines, two columns.
 *
 * MySQL-safe: every column is nullable or has a default so legacy
 * rows stay valid without backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            // Listing-fee accounting. Distinct from `total_value` /
            // `payable_amount` which carry pre-existing SNR/VD
            // bookkeeping for the older agent-sells-vacation model.
            $table->decimal('listing_fee', 10, 2)->default(0)->after('payable_amount');
            $table->decimal('listing_fee_collected', 10, 2)->default(0)->after('listing_fee');
            $table->string('payment_status')->default('pending')->after('listing_fee_collected');
            // pending, partial, paid, refunded, charged_back

            // Fulfillment state machine — runs alongside `stage` (the
            // sales state machine). Sales says "closed_won"; this says
            // "verified_pending_listing", "live", "fulfilled", etc.
            $table->string('agreement_status')->default('pitched')->after('payment_status');
            // pitched, verbal_yes, pending_payment, paid_pending_verification,
            // verified_pending_listing, live, fulfilled, cancelled, refunded, charged_back
            $table->index(['tenant_id', 'agreement_status'], 'deals_agreement_status_idx');

            // Term — owner agrees to a listing window in months.
            $table->integer('listing_term_months')->default(12)->after('agreement_status');
            $table->date('term_expires_at')->nullable()->after('listing_term_months');

            // Refund cooling-off window. Set on payment; some states
            // require 3-day right-to-rescind. See refund_cases table.
            $table->date('refund_window_expires_at')->nullable()->after('term_expires_at');

            // TCPA + sales-disclosure capture markers. Each must be
            // ticked (by the disclosure-checklist UI or transcript
            // scanner) before the agreement leaves
            // paid_pending_verification.
            $table->boolean('tcpa_disclosure_completed')->default(false)->after('refund_window_expires_at');
            $table->timestamp('tcpa_disclosure_completed_at')->nullable()->after('tcpa_disclosure_completed');
            $table->string('tcpa_recording_uri', 512)->nullable()->after('tcpa_disclosure_completed_at');

            // Verification call — separate verifier (not the closer)
            // calls the owner back and re-reads the disclosures.
            $table->boolean('verification_call_completed')->default(false)->after('tcpa_recording_uri');
            $table->timestamp('verification_call_completed_at')->nullable()->after('verification_call_completed');
            $table->uuid('verifier_id')->nullable()->after('verification_call_completed_at');
            $table->foreign('verifier_id')->references('id')->on('users')->nullOnDelete();

            // Date the owner signed the listing agreement. The actual
            // signed-PDF row lives in `contracts` and is referenced
            // by the existing deals.contract_id column — we don't
            // duplicate that here.
            $table->date('agreement_signed_at')->nullable()->after('verifier_id');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            $table->dropIndex('deals_agreement_status_idx');
            $table->dropForeign(['verifier_id']);
            $table->dropColumn([
                'listing_fee',
                'listing_fee_collected',
                'payment_status',
                'agreement_status',
                'listing_term_months',
                'term_expires_at',
                'refund_window_expires_at',
                'tcpa_disclosure_completed',
                'tcpa_disclosure_completed_at',
                'tcpa_recording_uri',
                'verification_call_completed',
                'verification_call_completed_at',
                'verifier_id',
                'agreement_signed_at',
            ]);
        });
    }
};
