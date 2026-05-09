<?php

declare(strict_types=1);

namespace App\Modules\Commission\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Modules\Commission\Domain\Models\CommissionPayout
 */
final class PayoutResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'period_start' => $this->period_start?->toDateString(),
            'period_end' => $this->period_end?->toDateString(),
            'total_earned' => $this->total_earned,
            'total_reversed' => $this->total_reversed,
            'total_adjustments' => $this->total_adjustments,
            'net_payable' => $this->net_payable,
            'currency' => $this->currency,
            'status' => $this->status,
            'approved_by_id' => $this->approved_by_id,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'payment_reference' => $this->payment_reference,
            'calculation_count' => is_array($this->calculation_ids) ? count($this->calculation_ids) : 0,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
