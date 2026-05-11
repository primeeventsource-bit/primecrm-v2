<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make `bookings` accept partner-originated rows.
 *
 * Until now `bookings.agent_id` was NOT NULL — every booking was created
 * inside the floor (manual rental, or agent-sold legacy package).
 * Partner webhooks have no auth user; we still try to attribute the
 * booking (inquiry handler > listing deal's closer > null) but if all
 * of those fail we'd rather record the booking with agent_id=null than
 * reject the webhook and lose the revenue event.
 *
 *   agent_id              → nullable, FK rebound with nullOnDelete.
 *   partner_site_id       → new FK to partner_sites, nullable.
 *   external_booking_id   → partner's id for the booking, used for
 *                           idempotency. UNIQUE within partner_site so
 *                           two partners can share the same id space.
 *   partner_metadata      → raw payload audit (timestamp, fees, etc).
 *
 * MySQL-safe: drop FK before ALTER NULLABLE, re-add after.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Add new columns first so we have the indexes ready.
        Schema::table('bookings', function (Blueprint $table): void {
            $table->uuid('partner_site_id')->nullable()->after('inquiry_id');
            $table->string('external_booking_id')->nullable()->after('partner_site_id');
            $table->json('partner_metadata')->nullable()->after('payment_status');
        });

        // 2. Make agent_id nullable. MySQL requires dropping the FK
        //    before altering the column type, then re-adding.
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropForeign(['agent_id']);
            $table->uuid('agent_id')->nullable()->change();
            $table->foreign('agent_id')->references('id')->on('users')->nullOnDelete();
        });

        // 3. FK + dedup index for partner-originated bookings.
        Schema::table('bookings', function (Blueprint $table): void {
            $table->foreign('partner_site_id')
                ->references('id')->on('partner_sites')
                ->nullOnDelete();

            // Idempotency key for the webhook handler. Unique within a
            // partner_site, NULL-safe (multiple non-partner bookings
            // can have NULL external_booking_id without colliding).
            $table->unique(
                ['partner_site_id', 'external_booking_id'],
                'bookings_partner_external_unique'
            );

            // "Show me every booking partner X sent us last week."
            $table->index(
                ['partner_site_id', 'created_at'],
                'bookings_partner_created_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropForeign(['partner_site_id']);
            $table->dropUnique('bookings_partner_external_unique');
            $table->dropIndex('bookings_partner_created_idx');
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropForeign(['agent_id']);
            $table->uuid('agent_id')->nullable(false)->change();
            $table->foreign('agent_id')->references('id')->on('users');
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn(['partner_site_id', 'external_booking_id', 'partner_metadata']);
        });
    }
};
