<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Modules\Payment\Domain\Models\Payment
 */
final class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'booking_id' => $this->booking_id,
            'deal_id' => $this->deal_id,
            'lead_id' => $this->lead_id,
            'processed_by_id' => $this->processed_by_id,
            'provider' => $this->provider,
            'provider_payment_id' => $this->provider_payment_id,
            'payment_method' => $this->payment_method,
            'card_last_four' => $this->card_last_four,
            'card_brand' => $this->card_brand,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'type' => $this->type,
            'status' => $this->status,
            'parent_payment_id' => $this->parent_payment_id,
            'failure_code' => $this->failure_code,
            'failure_reason' => $this->failure_reason,
            'authorized_at' => $this->authorized_at?->toIso8601String(),
            'captured_at' => $this->captured_at?->toIso8601String(),
            'cleared_at' => $this->cleared_at?->toIso8601String(),
            'refunded_at' => $this->refunded_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
