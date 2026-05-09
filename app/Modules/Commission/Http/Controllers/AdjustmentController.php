<?php

declare(strict_types=1);

namespace App\Modules\Commission\Http\Controllers;

use App\Core\Shared\Services\AuditLogService;
use App\Modules\Commission\Domain\Models\CommissionAdjustment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class AdjustmentController extends Controller
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()?->role->canSupervise()) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'user_id' => ['required', 'uuid', 'exists:users,id'],
            'amount' => ['required', 'numeric'], // can be negative
            'reason' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'payable_period' => ['required', 'date'],
        ]);

        $adjustment = CommissionAdjustment::query()->create([
            'user_id' => $validated['user_id'],
            'amount' => $validated['amount'],
            'reason' => $validated['reason'],
            'description' => $validated['description'] ?? null,
            'created_by_id' => $request->user()->id,
            'payable_period' => $validated['payable_period'],
        ]);

        $this->audit->record(
            action: 'commission.adjustment_created',
            entityType: 'commission_adjustment',
            entityId: $adjustment->id,
            context: [
                'user_id' => $validated['user_id'],
                'amount' => (string) $validated['amount'],
                'reason' => $validated['reason'],
            ],
        );

        return response()->json($adjustment, 201);
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => ['nullable', 'uuid'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date'],
        ]);

        $query = CommissionAdjustment::query()->orderByDesc('payable_period');

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->string('user_id')->value());
        }
        if ($request->filled('period_start')) {
            $query->whereDate('payable_period', '>=', $request->string('period_start')->value());
        }
        if ($request->filled('period_end')) {
            $query->whereDate('payable_period', '<=', $request->string('period_end')->value());
        }

        return response()->json([
            'data' => $query->paginate(min(200, (int) $request->integer('per_page', 25))),
        ]);
    }
}
