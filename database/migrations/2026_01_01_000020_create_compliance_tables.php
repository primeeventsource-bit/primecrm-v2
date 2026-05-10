<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // DNC sources: federal_dnc, state_dnc, wireless_dnc, internal_dnc, litigator_dnc, customer_request
        Schema::create('dnc_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(); // null = global (federal lists shared across tenants)
            $table->string('phone_hash', 64);
            $table->string('phone'); // E.164 stored for audit, never used for lookup
            $table->string('source')->index();
            $table->string('reason')->nullable();
            $table->string('added_by')->nullable(); // user_id, 'system', 'import:federal-dnc-2026-05'
            $table->date('effective_date')->nullable();
            $table->date('expires_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            // Hot path: check before every dial. Must be fast.
            $table->index(['tenant_id', 'phone_hash']);
            $table->index('phone_hash'); // for global lookup including null tenant rows
        });

        // Express written consent records — TCPA-required for autodialer use
        Schema::create('consent_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('lead_id')->nullable(); // may not have a lead yet at consent time
            $table->string('phone_hash', 64);
            $table->string('phone');
            $table->string('consent_type'); // marketing, transactional, autodialer, sms
            $table->string('source'); // web_form, ivr, paper_signed, verbal_recorded
            $table->string('source_url')->nullable();
            $table->string('source_ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('recording_url')->nullable(); // verbal consent recording
            $table->json('consent_text_snapshot')->nullable(); // exact disclosure shown
            $table->timestamp('consented_at');
            $table->timestamp('revoked_at')->nullable();
            $table->string('revocation_reason')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('lead_id')->references('id')->on('leads')->nullOnDelete();

            $table->index(['tenant_id', 'phone_hash', 'consent_type']);
            $table->index(['tenant_id', 'lead_id']);
        });

        // Per-number contact frequency tracking — most state TCPA laws cap attempts
        Schema::create('contact_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('phone_hash', 64);
            $table->uuid('lead_id')->nullable();
            $table->uuid('agent_id')->nullable();
            $table->uuid('call_id')->nullable();
            $table->string('attempt_type'); // outbound_call, sms, email
            $table->string('outcome')->nullable(); // connected, no_answer, voicemail, busy, etc
            $table->timestamp('attempted_at')->index();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('lead_id')->references('id')->on('leads')->nullOnDelete();
            $table->foreign('agent_id')->references('id')->on('users')->nullOnDelete();

            // Hot path: count attempts for a number in the last N days
            $table->index(['tenant_id', 'phone_hash', 'attempted_at']);
        });

        // Calling window rules per state/jurisdiction
        Schema::create('calling_windows', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(); // null = federal default
            $table->string('jurisdiction'); // 'US-FED', 'US-CA', 'US-FL', etc
            $table->time('earliest_local')->default('08:00:00');
            $table->time('latest_local')->default('21:00:00');
            $table->json('blocked_weekdays')->nullable(); // [0,6] = Sun, Sat
            $table->json('blocked_dates')->nullable(); // holidays
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'jurisdiction']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calling_windows');
        Schema::dropIfExists('contact_attempts');
        Schema::dropIfExists('consent_records');
        Schema::dropIfExists('dnc_entries');
    }
};
