<?php

declare(strict_types=1);

namespace App\Modules\Commission\Http\Controllers;

use App\Core\Shared\Services\AuditLogService;
use App\Modules\Commission\Domain\Models\CommissionPlan;
use App\Modules\Commission\Domain\Models\CommissionPlanRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Commission plans CRUD.
 *
 * Plans are tenant-scoped templates that, via CommissionAssignment,
 * govern what an agent earns. The plan itself owns metadata (name,
 * dates, active flag); each plan has one or more CommissionPlanRule
 * rows that describe the actual payout logic.
 *
 * Listing is open to anyone authenticated; writes require supervisor.
 */
final class PlanController extends Controller
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function index(Request $request): JsonResponse
    {
        // Permissive validation. $request->boolean() further down already
        // coerces 'true'/'false'/'1'/'0'/'on'/'yes'/'' safely; the
        // stricter 'boolean' rule was rejecting the page's load on every
        // mount with a 422 (cause: TBD — possibly an axios query-string
        // shape Laravel's boolean validator doesn't accept). Accepting
        // anything for these query flags loses nothing — invalid values
        // simply read as false via boolean().
        $request->validate([
            'active_only' => ['nullable', 'string'],
            'with_rules' => ['nullable', 'string'],
        ]);

        $query = CommissionPlan::query()->orderBy('name');

        if ($request->boolean('active_only', false)) {
            $today = now()->toDateString();
            $query->activeOn($today);
        }

        if ($request->boolean('with_rules', false)) {
            $query->with(['rules' => fn ($q) => $q->orderBy('priority')]);
        }

        $plans = $query->get()->map(fn (CommissionPlan $p) => $this->serialize($p, $request->boolean('with_rules')));

        return response()->json(['data' => $plans]);
    }

    public function show(string $id): JsonResponse
    {
        $plan = CommissionPlan::query()
            ->with(['rules' => fn ($q) => $q->orderBy('priority')])
            ->findOrFail($id);

        return response()->json($this->serialize($plan, true));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeSupervisor($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:500'],
            'active' => ['nullable', 'boolean'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ]);

        $plan = CommissionPlan::query()->create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'active' => (bool) ($validated['active'] ?? true),
            'effective_from' => $validated['effective_from'],
            'effective_to' => $validated['effective_to'] ?? null,
            'default_rules' => null,
        ]);

        $this->audit->record(
            action: 'commission_plan.created',
            entityType: 'commission_plan',
            entityId: $plan->id,
            context: ['name' => $plan->name, 'created_by' => $request->user()->id],
        );

        return response()->json($this->serialize($plan->fresh(), true), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->authorizeSupervisor($request);
        $plan = CommissionPlan::query()->findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'active' => ['sometimes', 'boolean'],
            'effective_from' => ['sometimes', 'date'],
            'effective_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:effective_from'],
        ]);

        $plan->update($validated);

        $this->audit->record(
            action: 'commission_plan.updated',
            entityType: 'commission_plan',
            entityId: $plan->id,
            changes: $validated,
        );

        return response()->json($this->serialize($plan->fresh(), true));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->authorizeSupervisor($request);
        $plan = CommissionPlan::query()->findOrFail($id);

        // Soft-delete the plan itself (model uses SoftDeletes) but ALSO
        // mark all of its rules inactive so any in-flight calculations
        // still resolve cleanly. Rules don't soft-delete and are
        // referenced by FK from commission_calculations.
        $plan->rules()->update(['active' => false]);
        $plan->update(['active' => false]);
        $plan->delete();

        $this->audit->record(
            action: 'commission_plan.archived',
            entityType: 'commission_plan',
            entityId: $plan->id,
        );

        return response()->json(['archived' => true]);
    }

    private function authorizeSupervisor(Request $request): void
    {
        if (! $request->user()?->role->canSupervise()) {
            abort(403, 'Supervisor role required.');
        }
    }

    private function serialize(CommissionPlan $p, bool $withRules = false): array
    {
        $data = [
            'id' => $p->id,
            'name' => $p->name,
            'description' => $p->description,
            'active' => (bool) $p->active,
            'effective_from' => $p->effective_from?->toDateString(),
            'effective_to' => $p->effective_to?->toDateString(),
        ];

        if ($withRules) {
            $rules = $p->relationLoaded('rules')
                ? $p->rules
                : $p->rules()->orderBy('priority')->get();

            $data['rules'] = $rules->map(fn (CommissionPlanRule $r) => [
                'id' => $r->id,
                'role' => $r->role,
                'rule_type' => $r->rule_type,
                'trigger_event' => $r->trigger_event,
                'config' => $r->config,
                'priority' => $r->priority,
                'active' => (bool) $r->active,
            ])->values();
        }

        return $data;
    }
}
