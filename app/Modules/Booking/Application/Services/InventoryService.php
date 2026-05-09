<?php

declare(strict_types=1);

namespace App\Modules\Booking\Application\Services;

use App\Modules\Booking\Domain\Models\InventoryAvailability;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-side inventory queries.
 *
 * Search by resort, date range, unit type, etc. The dialer's pitch
 * panel and the agent's booking flow both call into this. Pure reads;
 * no state mutation.
 */
final class InventoryService
{
    /**
     * @param  array{resort_id?: string, brand?: string, unit_type?: string, sleeps_min?: int, max_price?: float}  $filters
     */
    public function search(string $checkInFrom, string $checkInTo, array $filters = []): Builder
    {
        $query = InventoryAvailability::query()
            ->available()
            ->between($checkInFrom, $checkInTo)
            ->orderBy('check_in_date')
            ->with(['unit:id,resort_id,unit_type,sleeps,features', 'resort:id,name,brand,city,state,timezone']);

        if (! empty($filters['resort_id'])) {
            $query->where('resort_id', $filters['resort_id']);
        }
        if (! empty($filters['brand'])) {
            $query->whereHas('resort', fn ($q) => $q->where('brand', $filters['brand']));
        }
        if (! empty($filters['unit_type'])) {
            $query->whereHas('unit', fn ($q) => $q->where('unit_type', $filters['unit_type']));
        }
        if (! empty($filters['sleeps_min'])) {
            $query->whereHas('unit', fn ($q) => $q->where('sleeps', '>=', $filters['sleeps_min']));
        }
        if (! empty($filters['max_price'])) {
            $query->where('current_price', '<=', $filters['max_price']);
        }

        return $query;
    }
}
