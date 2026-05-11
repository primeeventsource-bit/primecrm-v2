<?php

declare(strict_types=1);

namespace App\Modules\Commission\Http\Controllers;

use App\Modules\Commission\Domain\Models\CommissionPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Read-only listing of commission plans for selectors (Add Agent form, etc).
 *
 * Plans themselves are managed elsewhere (planned: Commission > Plans admin).
 * This endpoint just feeds dropdowns and stays cheap.
 */
final class PlanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'active_only' => ['nullable', 'boolean'],
        ]);

        $query = CommissionPlan::query()->orderBy('name');

        if ($request->boolean('active_only', true)) {
            $today = now()->toDateString();
            $query->activeOn($today);
        }

        $plans = $query->get()->map(fn (CommissionPlan $p) => [
            'id' => $p->id,
            'name' => $p->name,
            'description' => $p->description,
            'effective_from' => $p->effective_from?->toDateString(),
            'effective_to' => $p->effective_to?->toDateString(),
        ]);

        return response()->json(['data' => $plans]);
    }
}
