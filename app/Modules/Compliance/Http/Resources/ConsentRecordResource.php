<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Modules\Compliance\Domain\Models\ConsentRecord
 */
final class ConsentRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lead_id' => $this->lead_id,
            'phone' => $this->phone,
            'consent_type' => $this->consent_type?->value,
            'source' => $this->source,
            'source_url' => $this->source_url,
            'has_recording' => $this->recording_url !== null,
            'consented_at' => $this->consented_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'revocation_reason' => $this->revocation_reason,
            'is_active' => $this->revoked_at === null,
        ];
    }
}
