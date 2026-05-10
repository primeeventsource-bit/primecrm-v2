<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends the calls table to host Prime Connect video rooms alongside
 * voice calls. The `medium` discriminator routes queries: dialer +
 * supervisor Voice surfaces filter `medium = 'voice'`; the Prime Connect
 * lobby filters `medium = 'video'`. Existing rows backfill to 'voice'
 * via the column default.
 *
 * Voice rows leave the room_* columns null; video rows leave the
 * voice-only fields (provider_call_sid, ring_seconds, etc) null. Both
 * still flow through the same webhook idempotency table and the same
 * recording lifecycle helpers — only the disk and the composition step
 * differ.
 *
 * MySQL-safe: `string` columns (not jsonb), full unique on the room SID,
 * all index identifiers <= 64 chars.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calls', function (Blueprint $table): void {
            // Discriminator. Default 'voice' so existing rows are correct
            // without a UPDATE. Indexed because every Prime Connect lobby
            // query filters by it.
            $table->string('medium')->default('voice')->after('direction');

            // Twilio Video room SID (RM*). Unique per Twilio account, so
            // also unique here (we never have two rows for the same room).
            $table->string('twilio_room_sid', 34)->nullable()->after('provider_parent_sid');
            // Composition SID (CJ*) is set after the room ends and Twilio
            // composes the per-track recordings into a single MP4.
            $table->string('twilio_composition_sid', 34)->nullable()->after('twilio_room_sid');
            $table->string('room_name', 128)->nullable()->after('twilio_composition_sid');

            // Distinct from `status`/`substatus` which describe a voice
            // call leg. Room state machine: created → in_progress →
            // completed | failed.
            $table->string('room_status')->nullable()->after('substatus');

            // Scheduled rooms are inserted before any Twilio call is
            // made; the SDK call happens lazily on the first join.
            $table->timestamp('scheduled_for')->nullable()->after('queued_at');

            // Lobby-only metadata: invited identities, room type
            // overrides, deal context for the in-call hint context.
            $table->json('lobby_metadata')->nullable()->after('metadata');
        });

        Schema::table('calls', function (Blueprint $table): void {
            $table->index('medium');
            $table->unique('twilio_room_sid');
            // Lobby's primary access pattern: list active video rooms
            // for a tenant ordered by recency.
            $table->index(['tenant_id', 'medium', 'room_status'], 'calls_tenant_medium_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('calls', function (Blueprint $table): void {
            $table->dropIndex('calls_tenant_medium_status_idx');
            $table->dropUnique(['twilio_room_sid']);
            $table->dropIndex(['medium']);
        });

        Schema::table('calls', function (Blueprint $table): void {
            $table->dropColumn([
                'medium',
                'twilio_room_sid',
                'twilio_composition_sid',
                'room_name',
                'room_status',
                'scheduled_for',
                'lobby_metadata',
            ]);
        });
    }
};
