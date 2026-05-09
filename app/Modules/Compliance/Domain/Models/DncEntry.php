<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\Models;

use App\Modules\Compliance\Domain\Enums\DncSource;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * DNC list entry.
 *
 * NOTE: This model intentionally does NOT use TenantScoped — federal/state/
 * wireless DNC lists have tenant_id NULL and are shared across all tenants.
 * Callers (DncCheckService) explicitly query `WHERE tenant_id = ? OR
 * tenant_id IS NULL`. All write paths go through {@see \App\Modules\Compliance\Application\Actions\AddDncEntryAction}
 * which sets tenant_id correctly based on source.
 */
final class DncEntry extends Model
{
    use HasUuid;

    protected $table = 'dnc_entries';

    protected $fillable = [
        'tenant_id',
        'phone_hash',
        'phone',
        'source',
        'reason',
        'added_by',
        'effective_date',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'source' => DncSource::class,
            'effective_date' => 'date',
            'expires_at' => 'date',
        ];
    }

    /**
     * Active entries — effective today and not yet expired.
     */
    public function scopeActive(Builder $query): Builder
    {
        $today = now()->toDateString();

        return $query
            ->where(function (Builder $q) use ($today): void {
                $q->whereNull('effective_date')->orWhere('effective_date', '<=', $today);
            })
            ->where(function (Builder $q) use ($today): void {
                $q->whereNull('expires_at')->orWhere('expires_at', '>=', $today);
            });
    }
}
