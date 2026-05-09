<?php

declare(strict_types=1);

namespace App\Modules\Commission\Http\Controllers;

use App\Modules\Commission\Application\Services\PayoutService;
use App\Modules\Commission\Domain\Models\CommissionCalculation;
use App\Modules\Commission\Domain\Models\CommissionPayout;
use App\Modules\Commission\Http\Resources\CalculationResource;
use App\Modules\Commission\Http\Resources\PayoutResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class PayoutController extends Controller
{
    public function __construct(private readonly PayoutService $service) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => ['nullable', 'uuid'],
            'status' => ['nullable', 'string'],
        ]);

        $query = CommissionPayout::query()->orderByDesc('period_end');
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->string('user_id')->value());
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->value());
        }

        $page = $query->paginate(min(200, (int) $request->integer('per_page', 25)));

        return PayoutResource::collection($page)->response();
    }

    public function show(string $id): PayoutResource
    {
        return new PayoutResource(CommissionPayout::query()->findOrFail($id));
    }

    public function build(Request $request): JsonResponse
    {
        $this->authorizeSupervisor($request);

        $validated = $request->validate([
            'user_id' => ['required', 'uuid', 'exists:users,id'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
        ]);

        $payout = $this->service->buildForPeriod(
            $validated['user_id'],
            $validated['period_start'],
            $validated['period_end'],
        );

        return (new PayoutResource($payout))->response();
    }

    public function approve(Request $request, string $id): PayoutResource
    {
        $this->authorizeSupervisor($request);
        $payout = CommissionPayout::query()->findOrFail($id);

        return new PayoutResource($this->service->approve($payout, $request->user()->id));
    }

    public function markPaid(Request $request, string $id): PayoutResource
    {
        $this->authorizeSupervisor($request);

        $validated = $request->validate([
            'reference' => ['required', 'string', 'max:200'],
        ]);

        $payout = CommissionPayout::query()->findOrFail($id);

        return new PayoutResource($this->service->markPaid($payout, $validated['reference']));
    }

    public function calculations(Request $request, string $id): JsonResponse
    {
        $payout = CommissionPayout::query()->findOrFail($id);
        $ids = (array) ($payout->calculation_ids ?? []);

        $calculations = CommissionCalculation::query()
            ->whereIn('id', $ids)
            ->orderBy('created_at')
            ->get();

        return CalculationResource::collection($calculations)->response();
    }

    private function authorizeSupervisor(Request $request): void
    {
        if (! $request->user()?->role->canSupervise()) {
            abort(403, 'Supervisor role required for payout operations.');
        }
    }
}
