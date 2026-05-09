<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Controllers;

use App\Modules\Payment\Application\Actions\ChargePaymentAction;
use App\Modules\Payment\Application\Actions\RefundPaymentAction;
use App\Modules\Payment\Domain\Models\Payment;
use App\Modules\Payment\Http\Requests\ChargePaymentRequest;
use App\Modules\Payment\Http\Requests\RefundPaymentRequest;
use App\Modules\Payment\Http\Resources\PaymentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class PaymentController extends Controller
{
    public function __construct(
        private readonly ChargePaymentAction $charge,
        private readonly RefundPaymentAction $refund,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'booking_id' => ['nullable', 'uuid'],
            'deal_id' => ['nullable', 'uuid'],
            'status' => ['nullable', 'string'],
        ]);

        $query = Payment::query()->orderByDesc('created_at');
        if ($request->filled('booking_id')) {
            $query->where('booking_id', $request->string('booking_id')->value());
        }
        if ($request->filled('deal_id')) {
            $query->where('deal_id', $request->string('deal_id')->value());
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->value());
        }

        $page = $query->paginate(min(200, (int) $request->integer('per_page', 25)));

        return PaymentResource::collection($page)->response();
    }

    public function show(string $id): PaymentResource
    {
        return new PaymentResource(Payment::query()->findOrFail($id));
    }

    public function charge(ChargePaymentRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $payment = $this->charge->execute(
            amount: (float) $validated['amount'],
            currency: $validated['currency'],
            sourceToken: $validated['source_token'],
            customerToken: $validated['customer_token'] ?? null,
            bookingId: $validated['booking_id'] ?? null,
            dealId: $validated['deal_id'] ?? null,
            leadId: $validated['lead_id'] ?? null,
            processedById: $request->user()?->id,
            type: $validated['type'] ?? Payment::TYPE_CHARGE,
        );

        $status = $payment->status === Payment::STATUS_FAILED ? 422 : 201;

        return (new PaymentResource($payment))->response()->setStatusCode($status);
    }

    public function refund(RefundPaymentRequest $request, string $id): JsonResponse
    {
        $payment = Payment::query()->findOrFail($id);
        $validated = $request->validated();

        try {
            $refund = $this->refund->execute(
                $payment,
                $validated['amount'] ?? null,
                $validated['reason'],
            );
        } catch (\DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return (new PaymentResource($refund))
            ->response()
            ->setStatusCode($refund->status === Payment::STATUS_FAILED ? 422 : 201);
    }
}
