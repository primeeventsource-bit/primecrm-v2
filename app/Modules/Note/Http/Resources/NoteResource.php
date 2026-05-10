<?php

declare(strict_types=1);

namespace App\Modules\Note\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Modules\Note\Domain\Models\Note
 */
final class NoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'notable_type' => $this->shortNotableType(),
            'notable_id' => $this->notable_id,
            'user_id' => $this->user_id,
            'author_name' => $this->author?->name,
            'kind' => $this->kind,
            'body' => $this->body,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Translate the FQCN persisted in `notable_type` back to the short
     * alias the API contract uses ('lead' / 'customer'). Keeps the wire
     * format stable even if we move model namespaces.
     */
    private function shortNotableType(): string
    {
        return match ($this->notable_type) {
            \App\Modules\Lead\Domain\Models\Lead::class => 'lead',
            \App\Modules\Customer\Domain\Models\Customer::class => 'customer',
            default => $this->notable_type,
        };
    }
}
