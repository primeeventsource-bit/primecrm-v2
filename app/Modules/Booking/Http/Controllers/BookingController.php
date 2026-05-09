<?php

declare(strict_types=1);

namespace App\Modules\Booking\Http\Controllers;

use App\Modules\Booking\Application\Services\BookingService;
use App\Modules\Booking\Domain\Exceptions\InventoryUnavailableException;
use App\Modules\Booking\Domain\Models\Booking;
use App\Modules\Booking\Domain\Models\InventoryHold;
use App\Modules\Booking\Http\Requests\ConfirmBookingRequest;
use App\Modules\Booking\Http\Resources\BookingResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class BookingController extends Controller
{
    public function __construct(private readonly BookingService $service) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'lead_id' => ['nullable', 'uuid'],
            'agent_id' => ['nullable', 'uuid'],
            'status' => ['nullable', 'string'],
            'upcoming' => ['nullable', 'boolean'],
        ]);

        $query = Booking::query()->orderByDesc('created_at');

        if ($request->boolean('upcoming')) {
            $query->upcoming();
        }
        if ($request->filled('lead_id')) {
            $query->where('lead_id', $request->string('lead_id')->value());
        }
        if ($request->filled('agent_id')) {
            $query->where('agent_id', $request->string('agent_id')->value());
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->value());
        }

        $page = $query->paginate(min(200, (int) $request->integer('per_page', 25)));

        return BookingResource::collection($page)->response();
    }

    public function show(string $id): BookingResource
    {
        return new BookingResource(Booking::query()->findOrFail($id));
    }

    public function confirmFromHold(ConfirmBookingRequest $request, string $holdId): JsonResponse
    {
        $hold = InventoryHold::query()->findOrFail($holdId);

        try {
            $booking = $this->service->confirm($hold, $request->validated());
        } catch (InventoryUnavailableException $e) {
            return response()->json([
                'error' => 'hold_not_active',
                'message' => $e->getMessage(),
            ], 409);
        }

        return (new BookingResource($booking))
            ->response()
            ->setStatusCode(201);
    }

    public function cancel(Request $request, string $id): BookingResource
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $booking = Booking::query()->findOrFail($id);
        $cancelled = $this->service->cancel($booking, $validated['reason']);

        return new BookingResource($cancelled);
    }
}
