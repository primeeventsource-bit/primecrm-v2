<?php

declare(strict_types=1);

namespace App\Modules\Booking\Http\Controllers;

use App\Modules\Booking\Application\Services\InventoryService;
use App\Modules\Booking\Http\Resources\InventoryAvailabilityResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class InventoryController extends Controller
{
    public function __construct(private readonly InventoryService $service) {}

    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'check_in_from' => ['required', 'date'],
            'check_in_to' => ['required', 'date', 'after_or_equal:check_in_from'],
            'resort_id' => ['nullable', 'uuid'],
            'brand' => ['nullable', 'string'],
            'unit_type' => ['nullable', 'string'],
            'sleeps_min' => ['nullable', 'integer', 'min:1'],
            'max_price' => ['nullable', 'numeric', 'min:0'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $page = $this->service
            ->search(
                $validated['check_in_from'],
                $validated['check_in_to'],
                array_diff_key($validated, ['check_in_from' => 1, 'check_in_to' => 1, 'per_page' => 1]),
            )
            ->paginate(min(200, (int) ($validated['per_page'] ?? 25)));

        return InventoryAvailabilityResource::collection($page)->response();
    }
}
