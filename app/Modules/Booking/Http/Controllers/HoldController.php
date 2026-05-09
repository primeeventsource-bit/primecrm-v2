<?php

declare(strict_types=1);

namespace App\Modules\Booking\Http\Controllers;

use App\Modules\Booking\Application\Services\HoldService;
use App\Modules\Booking\Domain\Exceptions\InventoryUnavailableException;
use App\Modules\Booking\Domain\Models\InventoryAvailability;
use App\Modules\Booking\Domain\Models\InventoryHold;
use App\Modules\Booking\Http\Requests\HoldInventoryRequest;
use App\Modules\Booking\Http\Resources\InventoryHoldResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class HoldController extends Controller
{
    public function __construct(private readonly HoldService $service) {}

    public function store(HoldInventoryRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $availability = InventoryAvailability::query()
            ->findOrFail($validated['inventory_availability_id']);

        try {
            $hold = $this->service->hold(
                $availability,
                heldByUserId: $request->user()->id,
                leadId: $validated['lead_id'] ?? null,
                dealId: $validated['deal_id'] ?? null,
                ttlMinutesOverride: $validated['ttl_minutes'] ?? null,
            );
        } catch (InventoryUnavailableException $e) {
            return response()->json([
                'error' => 'unit_no_longer_available',
                'message' => $e->getMessage(),
            ], 409);
        }

        return (new InventoryHoldResource($hold))
            ->response()
            ->setStatusCode(201);
    }

    public function release(Request $request, string $id): InventoryHoldResource
    {
        $hold = InventoryHold::query()->findOrFail($id);
        $released = $this->service->release($hold, InventoryHold::REASON_AGENT_RELEASED);

        return new InventoryHoldResource($released);
    }
}
