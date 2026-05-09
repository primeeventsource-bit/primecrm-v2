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
        Schema::create('resorts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name');
            $table->string('brand')->nullable(); // Westgate, Wyndham, DVC, etc
            $table->string('slug');
            $table->string('country', 2);
            $table->string('state', 2)->nullable();
            $table->string('city');
            $table->string('timezone');
            $table->jsonb('address')->nullable();
            $table->jsonb('amenities')->nullable();
            $table->jsonb('media')->nullable(); // photo URLs
            $table->integer('hold_ttl_minutes')->default(30); // resort-specific hold duration
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'active', 'brand']);
        });

        Schema::create('inventory_units', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('resort_id');
            $table->string('unit_type'); // studio, 1br, 2br, 3br, presidential
            $table->integer('sleeps')->default(2);
            $table->jsonb('features')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('resort_id')->references('id')->on('resorts')->cascadeOnDelete();
            $table->index(['tenant_id', 'resort_id', 'unit_type']);
        });

        // Per-week availability. One row per (unit, check-in date).
        Schema::create('inventory_availability', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('resort_id');
            $table->uuid('inventory_unit_id');
            $table->date('check_in_date');
            $table->date('check_out_date');
            $table->integer('nights')->default(7);
            $table->string('status')->default('available')->index();
            // available, held, booked, blocked, maintenance
            $table->decimal('base_price', 10, 2);
            $table->decimal('current_price', 10, 2);
            $table->string('currency', 3)->default('USD');
            $table->uuid('current_hold_id')->nullable();
            $table->uuid('booking_id')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('resort_id')->references('id')->on('resorts');
            $table->foreign('inventory_unit_id')->references('id')->on('inventory_units')->cascadeOnDelete();

            $table->index(['tenant_id', 'resort_id', 'check_in_date']);
            $table->index(['tenant_id', 'status', 'check_in_date']);
        });

        // CRITICAL: prevent double-booking via partial unique index.
        // One non-released hold per (unit, check-in) at any time.
        DB::statement('
            CREATE UNIQUE INDEX inventory_availability_one_active
            ON inventory_availability (inventory_unit_id, check_in_date)
            WHERE status IN (\'available\', \'held\', \'booked\')
        ');

        Schema::create('inventory_holds', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('inventory_availability_id');
            $table->uuid('lead_id')->nullable();
            $table->uuid('deal_id')->nullable();
            $table->uuid('held_by_id'); // agent who created hold
            $table->timestamp('expires_at')->index(); // job releases expired holds
            $table->timestamp('released_at')->nullable();
            $table->string('release_reason')->nullable(); // expired, converted, agent_released
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('inventory_availability_id')->references('id')->on('inventory_availability')->cascadeOnDelete();
            $table->foreign('lead_id')->references('id')->on('leads')->nullOnDelete();
            $table->foreign('deal_id')->references('id')->on('deals')->nullOnDelete();
            $table->foreign('held_by_id')->references('id')->on('users');

            $table->index(['tenant_id', 'expires_at', 'released_at']);
        });

        Schema::create('bookings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('lead_id');
            $table->uuid('deal_id')->nullable();
            $table->uuid('inventory_availability_id');
            $table->uuid('agent_id');
            $table->string('status')->default('confirmed')->index();
            // confirmed, paid, cancelled, refunded, completed (after stay)
            $table->decimal('total_price', 12, 2);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->string('currency', 3)->default('USD');
            $table->date('check_in_date');
            $table->date('check_out_date');
            $table->jsonb('guest_details')->nullable();
            $table->string('confirmation_number')->unique();
            $table->timestamp('confirmed_at');
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('lead_id')->references('id')->on('leads');
            $table->foreign('deal_id')->references('id')->on('deals')->nullOnDelete();
            $table->foreign('inventory_availability_id')->references('id')->on('inventory_availability');
            $table->foreign('agent_id')->references('id')->on('users');

            $table->index(['tenant_id', 'agent_id', 'status']);
            $table->index(['tenant_id', 'check_in_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('inventory_holds');
        Schema::dropIfExists('inventory_availability');
        Schema::dropIfExists('inventory_units');
        Schema::dropIfExists('resorts');
    }
};
