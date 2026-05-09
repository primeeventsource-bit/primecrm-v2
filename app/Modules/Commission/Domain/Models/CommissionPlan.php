<?php

declare(strict_types=1);

namespace App\Modules\Commission\Domain\Models;

use App\Support\Concerns\HasUuid;
use App\Support\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class CommissionPlan extends Model
{
    use HasUuid;
    use SoftDeletes;
    use TenantScoped;

    protected $table = 'commission_plans';

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'active',
        'effective_from',
        'effective_to',
        'default_rules',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'default_rules' => 'array',
        ];
    }

    public function rules(): HasMany
    {
        return $this->hasMany(CommissionPlanRule::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(CommissionAssignment::class);
    }

    public function scopeActiveOn(Builder $query, string $date): Builder
    {
        return $query
            ->where('active', true)
            ->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date));
    }
}
