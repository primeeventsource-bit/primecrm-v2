<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Http\Controllers;

use App\Core\Shared\Services\AuditLogService;
use App\Modules\Tenant\Domain\Models\User;
use App\Support\Enums\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
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
        ]);

        $user = User::query()->create([
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
        ]);

        $this->audit->record(
            action: 'agent.created',
            entityType: 'user',
            entityId: $user->id,
            context: [
                'role' => $user->role->value,
                'created_by' => $request->user()->id,
            ],
        );

        return response()->json($this->serialize($user), 201);
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
            'created_at' => $u->created_at?->toIso8601String(),
        ];
    }
}
