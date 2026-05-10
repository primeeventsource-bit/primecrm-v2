<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dial_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('agent_id');
            $table->uuid('campaign_id')->nullable();
            $table->string('mode'); // predictive, progressive, manual, preview
            $table->string('status')->index(); // active, paused, stopped, ended
            $table->integer('leads_processed')->default(0);
            $table->integer('calls_initiated')->default(0);
            $table->integer('calls_connected')->default(0);
            $table->integer('calls_abandoned')->default(0);
            $table->integer('total_talk_seconds')->default(0);
            $table->integer('total_wrap_seconds')->default(0);
            $table->jsonb('settings')->nullable(); // mode-specific config
            $table->timestamp('started_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('agent_id')->references('id')->on('users');

            $table->index(['tenant_id', 'agent_id', 'status']);
            $table->index(['tenant_id', 'campaign_id', 'started_at']);
        });

        Schema::create('calls', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('lead_id')->nullable();
            $table->uuid('agent_id')->nullable();
            $table->uuid('dial_session_id')->nullable();
            $table->uuid('campaign_id')->nullable();

            // Telephony provider
            $table->string('provider')->default('twilio'); // twilio, telnyx, sip
            $table->string('provider_call_sid')->nullable()->index(); // Twilio CallSid
            $table->string('provider_parent_sid')->nullable(); // for transferred calls
            $table->string('from_number');
            $table->string('to_number');

            // State
            $table->string('direction'); // outbound, inbound, internal_transfer
            $table->string('status')->index();
            // queued, initiated, ringing, in_progress, completed, busy, no_answer, failed, canceled
            $table->string('substatus')->nullable(); // abandoned, voicemail_dropped, etc
            $table->string('disposition')->nullable()->index();
            // interested, not_interested, callback, no_answer, voicemail, dnc_request, sale, etc
            $table->text('disposition_notes')->nullable();

            // Timing
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('initiated_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->integer('ring_seconds')->default(0);
            $table->integer('duration_seconds')->default(0); // billable talk time
            $table->integer('wrap_up_seconds')->default(0);

            // Recording
            $table->string('recording_status')->default('not_recorded');
            $table->string('recording_provider_sid')->nullable();
            $table->string('recording_url')->nullable();
            $table->string('recording_s3_path')->nullable();
            $table->integer('recording_duration_seconds')->nullable();
            $table->timestamp('recording_paused_at')->nullable(); // PCI: paused for card capture

            // Transcription
            $table->string('transcription_status')->default('not_started');
            $table->text('transcription_text')->nullable();
            $table->string('sentiment')->nullable(); // positive, neutral, negative
            $table->jsonb('sentiment_timeline')->nullable();

            // Cost (for unit economics)
            $table->decimal('provider_cost', 10, 4)->nullable();
            $table->string('provider_cost_currency', 3)->nullable();

            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('lead_id')->references('id')->on('leads')->nullOnDelete();
            $table->foreign('agent_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('dial_session_id')->references('id')->on('dial_sessions')->nullOnDelete();

            $table->index(['tenant_id', 'agent_id', 'created_at']);
            $table->index(['tenant_id', 'status', 'created_at']);
            $table->index(['tenant_id', 'lead_id', 'created_at']);
        });

        // Append-only event log for every state transition.
        // Source of truth for call lifecycle reconstruction and debugging.
        Schema::create('call_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('call_id');
            $table->string('event_type'); // queued, ringing, answered, ended, recording_started, etc
            $table->string('source'); // twilio_webhook, agent_action, system_timeout
            $table->jsonb('payload');
            $table->string('idempotency_key')->nullable(); // Twilio CallSid + status combo
            $table->timestamp('occurred_at')->index();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('call_id')->references('id')->on('calls')->cascadeOnDelete();

            $table->index(['call_id', 'occurred_at']);
            $table->unique(['idempotency_key']);
        });

        // Real-time agent presence. Single row per agent, updated frequently.
        Schema::create('agent_statuses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('agent_id')->unique(); // one row per agent
            $table->string('status'); // available, on_call, wrap_up, on_break, offline
            $table->string('previous_status')->nullable();
            $table->uuid('current_call_id')->nullable();
            $table->uuid('current_session_id')->nullable();
            $table->timestamp('status_changed_at');
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->jsonb('metadata')->nullable(); // skill set, queue assignment
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('agent_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('current_call_id')->references('id')->on('calls')->nullOnDelete();

            $table->index(['tenant_id', 'status']);
        });

        // Call campaigns - logical grouping for predictive dialer pacing
        Schema::create('campaigns', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name');
            $table->string('status')->default('draft'); // draft, active, paused, completed
            $table->string('dialer_mode'); // predictive, progressive, manual
            $table->decimal('target_abandon_rate', 5, 4)->default(0.03); // FCC cap
            $table->decimal('safety_factor', 4, 2)->default(1.0);
            $table->integer('max_attempts_per_lead')->default(6);
            $table->integer('min_hours_between_attempts')->default(4);
            $table->time('earliest_call_local')->default('08:00:00');
            $table->time('latest_call_local')->default('21:00:00');
            $table->jsonb('script_template')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('agent_statuses');
        Schema::dropIfExists('call_events');
        Schema::dropIfExists('calls');
        Schema::dropIfExists('dial_sessions');
    }
};
