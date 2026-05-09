<?php

declare(strict_types=1);

namespace App\Modules\Sales\Domain\Models;

use App\Modules\Tenant\Domain\Models\User;
use App\Support\Concerns\HasUuid;
use App\Support\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only stage transition log for a Deal.
 *
 * Mirrors the call_events pattern. The current `deals.stage` is the
 * latest authoritative value; `deal_stage_transitions` is the history
 * we replay for reporting and compliance.
 */
final class DealStageTransition extends Model
{
    use HasUuid;
    use TenantScoped;

    protected $table = 'deal_stage_transitions';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'deal_id',
        'changed_by_id',
        'from_stage',
        'to_stage',
        'reason',
        'metadata',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_id');
    }
}
