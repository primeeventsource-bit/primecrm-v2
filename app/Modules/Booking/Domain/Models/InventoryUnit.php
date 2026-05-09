<?php

declare(strict_types=1);

namespace App\Modules\Booking\Domain\Models;

use App\Support\Concerns\HasUuid;
use App\Support\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class InventoryUnit extends Model
{
    use HasUuid;
    use TenantScoped;

    protected $table = 'inventory_units';

    protected $fillable = [
        'tenant_id',
        'resort_id',
        'unit_type',
        'sleeps',
        'features',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'sleeps' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function resort(): BelongsTo
    {
        return $this->belongsTo(Resort::class);
    }

    public function availability(): HasMany
    {
        return $this->hasMany(InventoryAvailability::class);
    }
}
