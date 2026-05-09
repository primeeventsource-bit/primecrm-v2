<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('active')->default(true);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->jsonb('default_rules')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'active', 'effective_from']);
        });

        Schema::create('commission_plan_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('commission_plan_id');
            $table->string('role'); // closer, fronter, supervisor, qa
            $table->string('rule_type'); // flat, percentage, tiered, bonus, override
            $table->string('trigger_event'); // payment.cleared, deal.closed, booking.confirmed
            $table->jsonb('config'); // amounts, thresholds, brackets - rule-type-specific
            $table->integer('priority')->default(0); // higher wins on overlap
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('commission_plan_id')->references('id')->on('commission_plans')->cascadeOnDelete();
            $table->index(['tenant_id', 'commission_plan_id', 'role', 'active']);
        });

        Schema::create('commission_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id');
            $table->uuid('commission_plan_id');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->jsonb('overrides')->nullable(); // user-specific rule overrides
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('commission_plan_id')->references('id')->on('commission_plans');
            $table->index(['tenant_id', 'user_id', 'effective_from']);
        });

        // APPEND-ONLY event log. Never UPDATE or DELETE rows here.
        // Source of truth for all commission state.
        Schema::create('commission_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('event_type'); // deal.closed_won, payment.cleared, payment.refunded, etc
            $table->string('source_entity_type'); // App\Modules\Sales\...\Deal
            $table->uuid('source_entity_id');
            $table->jsonb('payload'); // immutable snapshot of relevant data at event time
            $table->string('idempotency_key')->unique(); // event_type:entity_id:source_event_id
            $table->timestamp('occurred_at')->index();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'event_type', 'occurred_at']);
        });

        // Calculations are derived from events. Reversible by writing a negative calculation.
        Schema::create('commission_calculations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('commission_event_id');
            $table->uuid('user_id'); // recipient
            $table->uuid('commission_plan_rule_id');
            $table->string('role'); // closer, fronter, supervisor, override
            $table->decimal('base_amount', 12, 2); // amount the percentage was applied to
            $table->decimal('rate', 8, 4)->nullable(); // percentage, if applicable
            $table->decimal('amount', 12, 2); // final commission amount; can be negative for reversals
            $table->string('currency', 3)->default('USD');
            $table->jsonb('explanation'); // human-readable trace: which rule, base, math
            $table->boolean('is_reversal')->default(false);
            $table->uuid('reverses_calculation_id')->nullable();
            $table->string('status')->default('pending')->index();
            // pending, payable, on_hold, paid, voided
            $table->date('payable_period')->nullable(); // YYYY-MM-DD of period this rolls up to
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('commission_event_id')->references('id')->on('commission_events');
            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('commission_plan_rule_id')->references('id')->on('commission_plan_rules');
            $table->foreign('reverses_calculation_id')->references('id')->on('commission_calculations');

            $table->index(['tenant_id', 'user_id', 'status']);
            $table->index(['tenant_id', 'payable_period', 'status']);
        });

        Schema::create('commission_payouts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id');
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('total_earned', 12, 2);
            $table->decimal('total_reversed', 12, 2)->default(0);
            $table->decimal('total_adjustments', 12, 2)->default(0);
            $table->decimal('net_payable', 12, 2);
            $table->string('currency', 3)->default('USD');
            $table->string('status')->default('draft')->index();
            // draft, approved, paid, voided
            $table->uuid('approved_by_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_reference')->nullable(); // payroll batch ID
            $table->jsonb('calculation_ids'); // array of calculation UUIDs included
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('approved_by_id')->references('id')->on('users')->nullOnDelete();

            $table->unique(['tenant_id', 'user_id', 'period_start', 'period_end']);
        });

        Schema::create('commission_adjustments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id');
            $table->decimal('amount', 12, 2); // can be negative
            $table->string('reason');
            $table->text('description')->nullable();
            $table->uuid('created_by_id');
            $table->date('payable_period');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('created_by_id')->references('id')->on('users');

            $table->index(['tenant_id', 'user_id', 'payable_period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_adjustments');
        Schema::dropIfExists('commission_payouts');
        Schema::dropIfExists('commission_calculations');
        Schema::dropIfExists('commission_events');
        Schema::dropIfExists('commission_assignments');
        Schema::dropIfExists('commission_plan_rules');
        Schema::dropIfExists('commission_plans');
    }
};
