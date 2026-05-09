<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Modules\Compliance\Domain\Models\DncEntry
 */
final class DncEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'is_global' => $this->tenant_id === null,
            'phone' => $this->phone,
            'source' => $this->source?->value,
            'reason' => $this->reason,
            'added_by' => $this->added_by,
            'effective_date' => $this->effective_date?->toDateString(),
            'expires_at' => $this->expires_at?->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
