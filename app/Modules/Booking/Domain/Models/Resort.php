<?php

declare(strict_types=1);

namespace App\Modules\Booking\Domain\Models;

use App\Support\Concerns\HasUuid;
use App\Support\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Resort extends Model
{
    use HasUuid;
    use SoftDeletes;
    use TenantScoped;

    protected $table = 'resorts';

    protected $fillable = [
        'tenant_id',
        'name',
        'brand',
        'slug',
        'country',
        'state',
        'city',
        'timezone',
        'address',
        'amenities',
        'media',
        'hold_ttl_minutes',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'address' => 'array',
            'amenities' => 'array',
            'media' => 'array',
            'hold_ttl_minutes' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function units(): HasMany
    {
        return $this->hasMany(InventoryUnit::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
