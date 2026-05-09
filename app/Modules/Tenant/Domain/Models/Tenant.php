<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Domain\Models;

use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Tenant extends Model
{
    use HasUuid;
    use SoftDeletes;

    protected $table = 'tenants';

    protected $fillable = [
        'name',
        'slug',
        'status',
        'timezone',
        'settings',
        'feature_flags',
        'trial_ends_at',
    ];

    protected $casts = [
        'settings' => 'array',
        'feature_flags' => 'array',
        'trial_ends_at' => 'datetime',
    ];

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function hasFeature(string $feature): bool
    {
        return (bool) ($this->feature_flags[$feature] ?? false);
    }

    protected function setting(): Attribute
    {
        return Attribute::make(
            get: fn () => fn (string $key, mixed $default = null) => data_get($this->settings, $key, $default),
        );
    }
}
