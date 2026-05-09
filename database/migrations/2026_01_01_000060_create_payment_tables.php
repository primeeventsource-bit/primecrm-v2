<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('booking_id')->nullable();
            $table->uuid('deal_id')->nullable();
            $table->uuid('lead_id')->nullable();
            $table->uuid('processed_by_id')->nullable(); // agent who took payment
            $table->string('provider'); // stripe, authorizenet, manual
            $table->string('provider_payment_id')->nullable()->index();
            $table->string('provider_customer_id')->nullable();
            $table->string('payment_method'); // card, ach, wire, check
            $table->string('card_last_four', 4)->nullable();
            $table->string('card_brand')->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('USD');
            $table->string('type')->default('charge'); // charge, deposit, refund, chargeback
            $table->string('status')->default('pending')->index();
            // pending, processing, succeeded, failed, refunded, partially_refunded, chargeback
            $table->uuid('parent_payment_id')->nullable(); // refund/chargeback links to original
            $table->jsonb('provider_metadata')->nullable();
            $table->string('failure_code')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('authorized_at')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamp('cleared_at')->nullable(); // when funds settle - commission trigger
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('booking_id')->references('id')->on('bookings')->nullOnDelete();
            $table->foreign('deal_id')->references('id')->on('deals')->nullOnDelete();
            $table->foreign('lead_id')->references('id')->on('leads')->nullOnDelete();
            $table->foreign('processed_by_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('parent_payment_id')->references('id')->on('payments')->nullOnDelete();

            $table->index(['tenant_id', 'status', 'created_at']);
            $table->index(['tenant_id', 'booking_id']);
            $table->index(['tenant_id', 'cleared_at']);
        });

        Schema::create('contracts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('booking_id')->nullable();
            $table->uuid('deal_id')->nullable();
            $table->uuid('lead_id');
            $table->string('template_key'); // standard_vacation_week, presidential_upgrade
            $table->string('status')->index();
            // draft, sent, viewed, signed, declined, expired, voided
            $table->string('provider')->default('docusign'); // docusign, hellosign, internal
            $table->string('provider_envelope_id')->nullable()->index();
            $table->string('s3_path')->nullable();
            $table->string('signed_pdf_s3_path')->nullable();
            $table->jsonb('signers')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->jsonb('audit_trail')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('booking_id')->references('id')->on('bookings')->nullOnDelete();
            $table->foreign('deal_id')->references('id')->on('deals')->nullOnDelete();
            $table->foreign('lead_id')->references('id')->on('leads');

            $table->index(['tenant_id', 'status']);
        });

        // Idempotent webhook processing for ALL external providers
        Schema::create('webhook_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(); // some webhooks resolve tenant from payload
            $table->string('provider')->index(); // twilio, stripe, docusign
            $table->string('event_type')->index();
            $table->string('external_id'); // provider's event ID, used for dedup
            $table->jsonb('payload');
            $table->jsonb('headers')->nullable(); // signature header for re-verification
            $table->string('status')->default('received')->index();
            // received, processing, processed, failed, skipped_duplicate
            $table->integer('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();

            $table->unique(['provider', 'external_id']); // dedup hard constraint
            $table->index(['provider', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('contracts');
        Schema::dropIfExists('payments');
    }
};
