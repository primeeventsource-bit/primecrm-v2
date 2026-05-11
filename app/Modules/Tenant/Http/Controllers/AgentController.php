<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Http\Controllers;

use App\Core\Shared\Services\AuditLogService;
use App\Modules\Commission\Domain\Models\CommissionAssignment;
use App\Modules\Tenant\Domain\Models\User;
use App\Support\Enums\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * CRUD for users (sales agents + supervisors).
 *
 * "Agent" in business terms = anyone on the call/sales floor: agent,
 * fronter, closer, supervisor, manager, QA, admin. The role enum
 * (UserRole) gates what each can do.
 *
 * Listing + create are supervisor-only — agents can't add agents.
 */
final class AgentController extends Controller
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'role' => ['nullable', 'string'],
            'q' => ['nullable', 'string', 'max:120'],
            'is_panama_based' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $q = User::query()->orderBy('first_name');

        if ($request->filled('role')) {
            $q->where('role', $request->string('role')->value());
        }
        if ($request->filled('is_panama_based')) {
            $q->where('is_panama_based', $request->boolean('is_panama_based'));
        }
        if ($request->filled('q')) {
            $needle = '%'.mb_strtolower((string) $request->string('q')).'%';
            $q->where(function ($qq) use ($needle): void {
                $qq->whereRaw('LOWER(first_name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(last_name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$needle]);
            });
        }

        $page = $q->paginate(min(200, (int) $request->integer('per_page', 50)));

        return response()->json([
            'data' => $page->getCollection()->map(fn (User $u) => $this->serialize($u))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()?->role->canSupervise()) {
            return response()->json(['error' => 'Supervisor role required.'], 403);
        }

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:200', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => [
                'required',
                Rule::in(array_map(fn (UserRole $r) => $r->value, UserRole::cases())),
            ],
            'phone' => ['nullable', 'string', 'max:32'],
            'extension' => ['nullable', 'string', 'max:16'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'skills' => ['nullable', 'array'],
            'is_panama_based' => ['nullable', 'boolean'],

            // Compensation package
            'pay_type' => ['nullable', Rule::in(['hourly', 'salary', 'commission_only', 'hybrid'])],
            // Dollars (decimal); converted to cents for storage. Form sends e.g. 18.50 or 65000.
            'base_rate' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'pay_currency' => ['nullable', 'string', 'size:3'],
            'pay_notes' => ['nullable', 'string', 'max:500'],

            // Commission assignment (optional — plan may be set later)
            'commission_plan_id' => ['nullable', 'uuid', 'exists:commission_plans,id'],
            // Per-user rate override, percent (e.g. 12 means 12%). Stored in
            // CommissionAssignment.overrides as {"rate_pct": <n>} — the engine
            // already honours this shape.
            'commission_override_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $payType = $validated['pay_type'] ?? 'commission_only';
        $baseRateCents = isset($validated['base_rate'])
            ? (int) round(((float) $validated['base_rate']) * 100)
            : null;
        // Commission-only never carries a base rate; clear it to avoid stale numbers.
        if ($payType === 'commission_only') {
            $baseRateCents = null;
        }

        $user = DB::transaction(function () use ($validated, $request, $payType, $baseRateCents): User {
            $u = User::query()->create([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'email' => mb_strtolower($validated['email']),
                'password' => Hash::make($validated['password']),
                'role' => $validated['role'],
                'status' => 'active',
                'phone' => $validated['phone'] ?? null,
                'extension' => $validated['extension'] ?? null,
                'timezone' => $validated['timezone'] ?? 'America/New_York',
                'skills' => $validated['skills'] ?? [],
                'is_panama_based' => (bool) ($validated['is_panama_based'] ?? false),
                'pay_type' => $payType,
                'base_rate_cents' => $baseRateCents,
                'pay_currency' => $validated['pay_currency'] ?? 'USD',
                'pay_notes' => $validated['pay_notes'] ?? null,
            ]);

            if (! empty($validated['commission_plan_id'])) {
                $overrides = [];
                if (isset($validated['commission_override_rate'])) {
                    $overrides['rate_pct'] = (float) $validated['commission_override_rate'];
                }

                CommissionAssignment::query()->create([
                    'tenant_id' => $u->tenant_id,
                    'user_id' => $u->id,
                    'commission_plan_id' => $validated['commission_plan_id'],
                    'effective_from' => now()->toDateString(),
                    'effective_to' => null,
                    'overrides' => $overrides ?: null,
                ]);
            }

            return $u;
        });

        $this->audit->record(
            action: 'agent.created',
            entityType: 'user',
            entityId: $user->id,
            context: [
                'role' => $user->role->value,
                'pay_type' => $user->pay_type,
                'base_rate_cents' => $user->base_rate_cents,
                'commission_plan_id' => $validated['commission_plan_id'] ?? null,
                'commission_override_rate' => $validated['commission_override_rate'] ?? null,
                'created_by' => $request->user()->id,
            ],
        );

        return response()->json($this->serialize($user->fresh()), 201);
    }

    public function show(string $id): JsonResponse
    {
        $user = User::query()->findOrFail($id);

        return response()->json($this->serialize($user));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        if (! $request->user()?->role->canSupervise()) {
            return response()->json(['error' => 'Supervisor role required.'], 403);
        }

        $user = User::query()->findOrFail($id);

        $validated = $request->validate([
            'first_name' => ['sometimes', 'string', 'max:120'],
            'last_name' => ['sometimes', 'string', 'max:120'],
            'role' => ['sometimes', Rule::in(array_map(fn (UserRole $r) => $r->value, UserRole::cases()))],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'extension' => ['sometimes', 'nullable', 'string', 'max:16'],
            'skills' => ['sometimes', 'array'],
            'is_panama_based' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(['active', 'suspended', 'terminated'])],
        ]);

        $user->update($validated);

        $this->audit->record(
            action: 'agent.updated',
            entityType: 'user',
            entityId: $user->id,
            changes: $validated,
        );

        return response()->json($this->serialize($user->fresh()));
    }

    private function serialize(User $u): array
    {
        $today = now()->toDateString();
        $assignment = CommissionAssignment::query()
            ->forUser($u->id)
            ->activeOn($today)
            ->with('plan:id,name')
            ->orderByDesc('effective_from')
            ->first();

        return [
            'id' => $u->id,
            'first_name' => $u->first_name,
            'last_name' => $u->last_name,
            'full_name' => $u->fullName(),
            'email' => $u->email,
            'role' => $u->role->value,
            'phone' => $u->phone,
            'extension' => $u->extension,
            'timezone' => $u->timezone,
            'skills' => $u->skills ?? [],
            'is_panama_based' => (bool) $u->is_panama_based,
            'pay_type' => $u->pay_type,
            'base_rate_cents' => $u->base_rate_cents,
            'pay_currency' => $u->pay_currency,
            'pay_notes' => $u->pay_notes,
            'commission' => $assignment ? [
                'plan_id' => $assignment->commission_plan_id,
                'plan_name' => $assignment->plan?->name,
                'effective_from' => $assignment->effective_from?->toDateString(),
                'override_rate_pct' => $assignment->overrides['rate_pct'] ?? null,
            ] : null,
            'created_at' => $u->created_at?->toIso8601String(),
        ];
    }
}
