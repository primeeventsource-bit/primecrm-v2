<?php

declare(strict_types=1);

namespace App\Modules\Customer\Domain\Models;

use App\Modules\Lead\Domain\Models\Lead;
use App\Modules\Tenant\Domain\Models\User;
use App\Support\Concerns\HasUuid;
use App\Support\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Customer extends Model
{
    use HasUuid;
    use SoftDeletes;
    use TenantScoped;

    protected $table = 'customers';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_VIP = 'vip';
    public const STATUS_PROSPECT = 'prospect';
    public const STATUS_CHURNED = 'churned';
    public const STATUS_BLACKLISTED = 'blacklisted';

    protected $fillable = [
        'tenant_id',
        'lead_id',
        'user_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'phone_hash',
        'alternate_phone',
        'country',
        'state',
        'city',
        'postal_code',
        'timezone',
        'status',
        'source',
        'lifetime_value',
        'total_deals',
        'total_bookings',
        'first_purchase_at',
        'last_purchase_at',
        'notes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'lifetime_value' => 'decimal:2',
            'total_deals' => 'integer',
            'total_bookings' => 'integer',
            'first_purchase_at' => 'datetime',
            'last_purchase_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}") ?: '(unnamed)';
    }

    /* ----------------------------------------------------------------------
     | Scopes
     | ---------------------------------------------------------------------- */

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_ACTIVE);
    }

    public function scopeVip(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_VIP);
    }

    public function scopeForAgent(Builder $q, string $agentId): Builder
    {
        return $q->where('user_id', $agentId);
    }
}
