<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Commission\Domain\Models\CommissionPlan;
use App\Modules\Commission\Domain\Models\CommissionPlanRule;
use App\Modules\Tenant\Domain\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * Default commission plans for every existing tenant.
 *
 * Idempotent: re-running won't duplicate plans (updateOrCreate keyed on
 * tenant_id + name). Safe to run on a populated production DB to backfill
 * defaults, and safe to re-run after schema changes.
 *
 * Three plans cover the common floor structure:
 *   - Standard Closer 8%       (paid when payment.cleared)
 *   - Standard Fronter 4%      (paid when payment.cleared)
 *   - Supervisor Override 1%   (paid on top of closer commissions)
 *
 * Per-agent overrides happen via CommissionAssignment.overrides.rate_pct —
 * the Add Agent form already wires this up.
 */
final class CommissionPlansSeeder extends Seeder
{
    public function run(): void
    {
        // Tenant itself is not TenantScoped; query directly. The seeded
        // CommissionPlan / CommissionPlanRule rows ARE tenant-scoped, so
        // we use withoutTenantScope() below since this runs from CLI with
        // no resolved TenantContext (otherwise the scope returns empty
        // results and updateOrCreate always inserts duplicates).
        Tenant::query()->cursor()->each(function (Tenant $tenant): void {
            $this->seedForTenant($tenant);
        });
    }

    private function seedForTenant(Tenant $tenant): void
    {
        $effectiveFrom = '2026-01-01';

        $plans = [
            [
                'name' => 'Standard Closer 8%',
                'description' => 'Default closer plan: 8% of cleared payments.',
                'rules' => [[
                    'role' => CommissionPlanRule::ROLE_CLOSER,
                    'rule_type' => CommissionPlanRule::TYPE_PERCENTAGE,
                    'trigger_event' => 'payment.cleared',
                    'config' => ['rate' => 0.08, 'base_field' => 'amount'],
                    'priority' => 10,
                ]],
            ],
            [
                'name' => 'Standard Fronter 4%',
                'description' => 'Default fronter plan: 4% of cleared payments.',
                'rules' => [[
                    'role' => CommissionPlanRule::ROLE_FRONTER,
                    'rule_type' => CommissionPlanRule::TYPE_PERCENTAGE,
                    'trigger_event' => 'payment.cleared',
                    'config' => ['rate' => 0.04, 'base_field' => 'amount'],
                    'priority' => 10,
                ]],
            ],
            [
                'name' => 'Supervisor Override 1%',
                'description' => 'Supervisor override: 1% on top of closer commissions.',
                'rules' => [[
                    'role' => CommissionPlanRule::ROLE_OVERRIDE,
                    'rule_type' => CommissionPlanRule::TYPE_OVERRIDE,
                    'trigger_event' => 'payment.cleared',
                    'config' => ['override_rate' => 0.01],
                    'priority' => 20,
                ]],
            ],
        ];

        foreach ($plans as $spec) {
            $plan = CommissionPlan::query()
                ->withoutTenantScope()
                ->updateOrCreate(
                    [
                        'tenant_id' => $tenant->id,
                        'name' => $spec['name'],
                    ],
                    [
                        'description' => $spec['description'],
                        'active' => true,
                        'effective_from' => $effectiveFrom,
                        'effective_to' => null,
                        'default_rules' => null,
                    ],
                );

            // Rules: keyed on (plan, role, trigger_event, rule_type) so re-runs
            // update config in place rather than appending duplicate rows.
            foreach ($spec['rules'] as $rule) {
                CommissionPlanRule::query()
                    ->withoutTenantScope()
                    ->updateOrCreate(
                        [
                            'tenant_id' => $tenant->id,
                            'commission_plan_id' => $plan->id,
                            'role' => $rule['role'],
                            'trigger_event' => $rule['trigger_event'],
                            'rule_type' => $rule['rule_type'],
                        ],
                        [
                            'config' => $rule['config'],
                            'priority' => $rule['priority'],
                            'active' => true,
                        ],
                    );
            }
        }
    }
}
