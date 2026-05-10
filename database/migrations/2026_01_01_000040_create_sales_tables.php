<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('lead_id');
            $table->uuid('agent_id'); // primary closer
            $table->uuid('fronter_id')->nullable(); // who handed off the lead
            $table->jsonb('additional_closer_ids')->nullable(); // multi-closer split scenarios

            $table->string('stage')->default('new')->index();
            // new, contacted, qualified, pitch_presented, negotiating, closed_won, closed_lost
            $table->string('previous_stage')->nullable();
            $table->timestamp('stage_changed_at')->nullable();
            $table->string('lost_reason')->nullable();

            $table->decimal('total_value', 12, 2)->default(0);
            $table->decimal('snr_amount', 12, 2)->default(0); // Sales & Reservation deduction
            $table->decimal('vd_amount', 12, 2)->default(0);  // Vacation Discount deduction
            $table->decimal('payable_amount', 12, 2)->default(0); // total - snr - vd
            $table->string('currency', 3)->default('USD');

            $table->uuid('booking_id')->nullable(); // becomes set on booking creation
            $table->uuid('contract_id')->nullable();

            $table->jsonb('pitch_data')->nullable(); // resort, dates, package
            $table->text('notes')->nullable();
            $table->timestamp('expected_close_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('lead_id')->references('id')->on('leads');
            $table->foreign('agent_id')->references('id')->on('users');
            $table->foreign('fronter_id')->references('id')->on('users')->nullOnDelete();

            $table->index(['tenant_id', 'agent_id', 'stage']);
            $table->index(['tenant_id', 'stage', 'closed_at']);
        });

        // Append-only stage transition log
        Schema::create('deal_stage_transitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('deal_id');
            $table->uuid('changed_by_id');
            $table->string('from_stage')->nullable();
            $table->string('to_stage');
            $table->text('reason')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('deal_id')->references('id')->on('deals')->cascadeOnDelete();
            $table->foreign('changed_by_id')->references('id')->on('users');

            $table->index(['deal_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_stage_transitions');
        Schema::dropIfExists('deals');
    }
};
