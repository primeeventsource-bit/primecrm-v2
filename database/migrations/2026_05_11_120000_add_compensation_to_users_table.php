<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compensation package fields on users.
 *
 * Base pay is per-user. Commission flows through commission_assignments
 * (links user → commission_plan with optional rate overrides). Together
 * they describe a complete compensation package — hourly + commission,
 * salary + commission, commission-only, or hybrid.
 *
 * `base_rate_cents` is interpreted by `pay_type`:
 *   - hourly        → cents-per-hour
 *   - salary        → cents-per-year
 *   - commission_only → ignored (nullable)
 *   - hybrid        → cents-per-hour (treat the salary side as hourly equivalent)
 *
 * Stored in cents to avoid float drift; the form sends decimal dollars.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('pay_type', 32)->default('commission_only')->after('is_panama_based');
            // hourly, salary, commission_only, hybrid
            $table->bigInteger('base_rate_cents')->nullable()->after('pay_type');
            $table->string('pay_currency', 3)->default('USD')->after('base_rate_cents');
            $table->string('pay_notes', 500)->nullable()->after('pay_currency');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['pay_type', 'base_rate_cents', 'pay_currency', 'pay_notes']);
        });
    }
};
