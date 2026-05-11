<?php

declare(strict_types=1);

namespace App\Modules\Commission\Http\Controllers;

use App\Core\Shared\Services\AuditLogService;
use App\Modules\Commission\Domain\Models\CommissionPlan;
use App\Modules\Commission\Domain\Models\CommissionPlanRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * CRUD for commission_plan_rules — the per-event payout rules under a
 * plan.
 *
 * Destroy is implemented as `active = false` rather than DELETE, because
 * commission_calculations carry an FK to this row. Disabling stops the
 * engine from applying it to future events without invalidating past
 * calculations.
 */
final class RuleController extends Controller
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function store(Request $request, string $planId): JsonResponse
    {
        $this->authorizeSupervisor($request);
        $plan = CommissionPlan::query()->findOrFail($planId);

        $validated = $this->validateRule($request);

        $rule = CommissionPlanRule::query()->create([
            'tenant_id' => $plan->tenant_id,
            'commission_plan_id' => $plan->id,
            'role' => $validated['role'],
            'rule_type' => $validated['rule_type'],
            'trigger_event' => $validated['trigger_event'],
            'config' => $validated['config'],
            'priority' => $validated['priority'] ?? 10,
            'active' => (bool) ($validated['active'] ?? true),
        ]);

        $this->audit->record(
            action: 'commission_rule.created',
            entityType: 'commission_plan_rule',
            entityId: $rule->id,
            context: ['plan_id' => $plan->id, 'role' => $rule->role, 'type' => $rule->rule_type],
        );

        return response()->json($this->serialize($rule), 201);
    }

    public function update(Request $request, string $planId, string $ruleId): JsonResponse
    {
        $this->authorizeSupervisor($request);

        $rule = CommissionPlanRule::query()
            ->where('commission_plan_id', $planId)
            ->findOrFail($ruleId);

        $validated = $request->validate([
            'role' => ['sometimes', Rule::in([
                CommissionPlanRule::ROLE_CLOSER,
                CommissionPlanRule::ROLE_FRONTER,
                CommissionPlanRule::ROLE_SUPERVISOR,
                CommissionPlanRule::ROLE_QA,
                CommissionPlanRule::ROLE_OVERRIDE,
            ])],
            'rule_type' => ['sometimes', Rule::in([
                CommissionPlanRule::TYPE_FLAT,
                CommissionPlanRule::TYPE_PERCENTAGE,
                CommissionPlanRule::TYPE_TIERED,
                CommissionPlanRule::TYPE_BONUS,
                CommissionPlanRule::TYPE_OVERRIDE,
            ])],
            'trigger_event' => ['sometimes', 'string', 'max:80'],
            'config' => ['sometimes', 'array'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $rule->update($validated);

        $this->audit->record(
            action: 'commission_rule.updated',
            entityType: 'commission_plan_rule',
            entityId: $rule->id,
            changes: $validated,
        );

        return response()->json($this->serialize($rule->fresh()));
    }

    public function destroy(Request $request, string $planId, string $ruleId): JsonResponse
    {
        $this->authorizeSupervisor($request);

        $rule = CommissionPlanRule::query()
            ->where('commission_plan_id', $planId)
            ->findOrFail($ruleId);

        // FK-safe disable, not DELETE — commission_calculations link to
        // this row.
        $rule->update(['active' => false]);

        $this->audit->record(
            action: 'commission_rule.disabled',
            entityType: 'commission_plan_rule',
            entityId: $rule->id,
        );

        return response()->json(['disabled' => true]);
    }

    private function validateRule(Request $request): array
    {
        return $request->validate([
            'role' => ['required', Rule::in([
                CommissionPlanRule::ROLE_CLOSER,
                CommissionPlanRule::ROLE_FRONTER,
                CommissionPlanRule::ROLE_SUPERVISOR,
                CommissionPlanRule::ROLE_QA,
                CommissionPlanRule::ROLE_OVERRIDE,
            ])],
            'rule_type' => ['required', Rule::in([
                CommissionPlanRule::TYPE_FLAT,
                CommissionPlanRule::TYPE_PERCENTAGE,
                CommissionPlanRule::TYPE_TIERED,
                CommissionPlanRule::TYPE_BONUS,
                CommissionPlanRule::TYPE_OVERRIDE,
            ])],
            'trigger_event' => ['required', 'string', 'max:80'],
            'config' => ['required', 'array'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'active' => ['nullable', 'boolean'],
        ]);
    }

    private function authorizeSupervisor(Request $request): void
    {
        if (! $request->user()?->role->canSupervise()) {
            abort(403, 'Supervisor role required.');
        }
    }

    private function serialize(CommissionPlanRule $r): array
    {
        return [
            'id' => $r->id,
            'commission_plan_id' => $r->commission_plan_id,
            'role' => $r->role,
            'rule_type' => $r->rule_type,
            'trigger_event' => $r->trigger_event,
            'config' => $r->config,
            'priority' => $r->priority,
            'active' => (bool) $r->active,
        ];
    }
}
