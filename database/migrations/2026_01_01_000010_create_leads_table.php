<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');

            // Identity
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone'); // E.164 format enforced at app layer
            $table->string('phone_hash', 64); // SHA-256 of normalized phone, for DNC matching
            $table->string('alternate_phone')->nullable();
            $table->string('alternate_phone_hash', 64)->nullable();
            $table->string('country', 2)->nullable();
            $table->string('state', 2)->nullable();
            $table->string('city')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('timezone')->nullable(); // resolved from area code or postal

            // Pipeline state
            $table->string('status')->default('new')->index();
            // new, contacted, qualified, pitch_presented, closed_won, closed_lost, dnc, do_not_contact
            $table->string('substatus')->nullable();
            $table->integer('score')->default(0)->index();
            $table->string('priority')->default('normal'); // low, normal, high, hot

            // Source tracking
            $table->string('source')->index(); // facebook, google, referral, csv_import, api, etc
            $table->string('source_campaign')->nullable();
            $table->string('source_medium')->nullable();
            $table->json('source_metadata')->nullable();
            $table->uuid('imported_via_id')->nullable(); // links to lead_imports

            // Vacation-rental specific
            $table->string('resort_interest')->nullable();
            $table->string('property_type')->nullable(); // owns_timeshare, vacation_renter, etc
            $table->decimal('estimated_value', 12, 2)->nullable();

            // Assignment & contact
            $table->uuid('assigned_agent_id')->nullable()->index();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('last_contacted_at')->nullable()->index();
            $table->integer('contact_attempts')->default(0);
            $table->timestamp('do_not_contact_until')->nullable(); // soft bounce, callback scheduled

            // Compliance flags
            $table->boolean('is_on_dnc')->default(false)->index();
            $table->boolean('has_express_consent')->default(false);
            $table->timestamp('consent_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('assigned_agent_id')->references('id')->on('users')->nullOnDelete();

            // Compound indexes for common query patterns
            $table->index(['tenant_id', 'status', 'score']);
            $table->index(['tenant_id', 'assigned_agent_id', 'status']);
            $table->index(['tenant_id', 'phone_hash']); // DNC enforcement lookup
            $table->index(['tenant_id', 'source', 'created_at']);
        });

        // Tenant-scoped uniqueness: same phone in different tenants is fine.
        // Allow nullable email duplicates (unknown contacts) but dedupe phone within tenant.
        // MySQL doesn't support partial unique indexes; the app-level dedup

        // engine (LeadDedupService / HoldService) covers the same cases on MySQL,

        // but the structural backstop is Postgres-only.

        if (DB::connection()->getDriverName() === 'pgsql') {

            DB::statement('CREATE UNIQUE INDEX leads_tenant_phone_unique ON leads (tenant_id, phone_hash) WHERE deleted_at IS NULL');

        }
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
