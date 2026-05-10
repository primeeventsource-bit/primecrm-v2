<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-participant records for both video rooms and (eventually) voice
 * conferences. A 1:1 voice dial doesn't insert participant rows — the
 * existing `calls.agent_id` and the dialed party are sufficient. Video
 * rooms write one row per Twilio Participant SID at join time and stamp
 * `left_at` when participant-disconnected fires.
 *
 * `user_id` is null for the customer (customers aren't users in this
 * system); set for agents and supervisors for war-room queries like
 * "calls where Sofia was a supervisor today".
 *
 * MySQL-safe: string role column casts to RoomParticipantRole enum at
 * the model layer, no jsonb, all index names <= 64 chars.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_participants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('call_id');

            // Twilio's per-participant SID (PA*) is our cross-system
            // correlation key for participant-* webhooks.
            $table->string('twilio_participant_sid', 34);

            // The identity string presented to Twilio at join time —
            // "{role}:{userId}" for staff, "customer:{leadId}" or
            // "customer:{customerId}" for the called party. Stored
            // verbatim so reconstructing who joined doesn't require
            // re-deriving the identity scheme.
            $table->string('identity', 128);

            // Agents / supervisors are users; customers are not.
            $table->uuid('user_id')->nullable();

            // Casts to RoomParticipantRole (string enum). Drives the
            // supervisor controller's audio-routing decisions.
            $table->string('role');

            $table->timestamp('joined_at');
            $table->timestamp('left_at')->nullable();

            $table->json('metadata')->nullable(); // device info, network ratings

            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('call_id')->references('id')->on('calls')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();

            // Webhook lookup pattern: find the participant for an
            // incoming participant-disconnected event by its SID.
            $table->index('twilio_participant_sid', 'call_participants_sid_idx');

            // Per-call roster lookups (the in-call ParticipantTile grid
            // and the supervisor whisper target picker both use this).
            $table->index(['call_id', 'role'], 'call_participants_call_role_idx');

            // "Show me every call Sofia supervised this week."
            $table->index(['tenant_id', 'user_id', 'joined_at'], 'call_participants_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_participants');
    }
};
