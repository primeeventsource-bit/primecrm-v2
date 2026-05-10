<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stackable notes for leads and customers (and any future entity).
 *
 * Polymorphic on (notable_type, notable_id) so the same module powers
 * comms history on Lead, Customer, and later Deal/Booking timelines
 * without per-entity schema work.
 *
 * Author is captured by user_id for attribution on the timeline; it's
 * nullable because future system-generated notes (e.g. "auto-assigned
 * to Mike") may be authored by no human.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('notable_type'); // App\Modules\Lead\Domain\Models\Lead
            $table->uuid('notable_id');
            $table->uuid('user_id')->nullable();      // author
            $table->string('kind')->default('note');  // note, call, email, sms, system
            $table->text('body');
            $table->json('metadata')->nullable();     // {direction, channel, duration_s, ...}
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();

            // Primary access pattern: pull the timeline for an entity.
            $table->index(['tenant_id', 'notable_type', 'notable_id', 'created_at'], 'notes_entity_timeline_idx');
            $table->index(['tenant_id', 'user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};
