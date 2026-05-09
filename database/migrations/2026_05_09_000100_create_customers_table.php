<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customers — the post-conversion identity that survives a Lead's
 * `closed_won` transition. A Lead is the speculative pre-sale record;
 * a Customer is the durable relationship that lifetime value, repeat
 * bookings, and churn metrics attach to.
 *
 * The `lead_id` link is for traceability — most customers have an
 * originating lead, but operators can also create a Customer directly
 * (e.g. importing prior customer lists from a legacy CRM).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('lead_id')->nullable();   // origin lead, if any
            $table->uuid('user_id')->nullable();   // sales agent who closed them

            // Identity
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone');               // E.164
            $table->string('phone_hash', 64);      // SHA-256 of normalized phone
            $table->string('alternate_phone')->nullable();
            $table->string('country', 2)->nullable();
            $table->string('state', 2)->nullable();
            $table->string('city')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('timezone')->nullable();

            // Lifecycle status
            $table->string('status')->default('active')->index();
            // active, vip, prospect, churned, blacklisted
            $table->string('source')->nullable();   // copied from originating lead

            // Sales metrics — denormalized for dashboard speed; recomputed
            // by the Customer module's listeners on payment.cleared / refund.
            $table->decimal('lifetime_value', 12, 2)->default(0);
            $table->integer('total_deals')->default(0);
            $table->integer('total_bookings')->default(0);
            $table->timestamp('first_purchase_at')->nullable();
            $table->timestamp('last_purchase_at')->nullable();

            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('lead_id')->references('id')->on('leads')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();

            $table->index(['tenant_id', 'status', 'lifetime_value']);
            $table->index(['tenant_id', 'phone_hash']);  // dedup lookup
            $table->index(['tenant_id', 'user_id', 'created_at']);  // agent's customers
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
