<?php

declare(strict_types=1);

namespace App\Modules\Commission\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Modules\Commission\Domain\Models\CommissionCalculation
 */
final class CalculationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'commission_event_id' => $this->commission_event_id,
            'user_id' => $this->user_id,
            'commission_plan_rule_id' => $this->commission_plan_rule_id,
            'role' => $this->role,
            'base_amount' => $this->base_amount,
            'rate' => $this->rate,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'explanation' => $this->explanation,
            'is_reversal' => $this->is_reversal,
            'reverses_calculation_id' => $this->reverses_calculation_id,
            'status' => $this->status,
            'payable_period' => $this->payable_period?->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
