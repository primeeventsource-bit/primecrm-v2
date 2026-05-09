<?php

declare(strict_types=1);

namespace App\Modules\Lead\Domain\Models;

use App\Modules\Tenant\Domain\Models\User;
use App\Support\Concerns\HasUuid;
use App\Support\Concerns\TenantScoped;
use App\Support\Enums\LeadPriority;
use App\Support\Enums\LeadStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The lead aggregate root.
 *
 * Phone is always stored in E.164 with the SHA-256 hash side-by-side. All
 * compliance checks key off `phone_hash` — never the raw phone column —
 * because the hash column has its own indexes and never leaks PII into
 * query logs. The DNC enforcement query in {@see \App\Modules\Compliance\Application\Services\DncCheckService}
 * joins on `phone_hash` for the same reason.
 */
final class Lead extends Model
{
    use HasUuid;
    use SoftDeletes;
    use TenantScoped;

    protected $table = 'leads';

    protected $fillable = [
        'tenant_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'phone_hash',
        'alternate_phone',
        'alternate_phone_hash',
        'country',
        'state',
        'city',
        'postal_code',
        'timezone',
        'status',
        'substatus',
        'score',
        'priority',
        'source',
        'source_campaign',
        'source_medium',
        'source_metadata',
        'imported_via_id',
        'resort_interest',
        'property_type',
        'estimated_value',
        'assigned_agent_id',
        'assigned_at',
        'last_contacted_at',
        'contact_attempts',
        'do_not_contact_until',
        'is_on_dnc',
        'has_express_consent',
        'consent_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => LeadStatus::class,
            'priority' => LeadPriority::class,
            'source_metadata' => 'array',
            'estimated_value' => 'decimal:2',
            'score' => 'integer',
            'contact_attempts' => 'integer',
            'assigned_at' => 'datetime',
            'last_contacted_at' => 'datetime',
            'do_not_contact_until' => 'datetime',
            'is_on_dnc' => 'boolean',
            'has_express_consent' => 'boolean',
            'consent_at' => 'datetime',
        ];
    }

    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_agent_id');
    }

    public function importedVia(): BelongsTo
    {
        return $this->belongsTo(LeadImport::class, 'imported_via_id');
    }

    /* ----------------------------------------------------------------------
     | Query scopes
     | ---------------------------------------------------------------------- */

    /** Leads eligible to be dialed: not terminal, not DNC, not soft-deleted. */
    public function scopeContactable(Builder $query): Builder
    {
        return $query
            ->where('is_on_dnc', false)
            ->whereNotIn('status', [
                LeadStatus::ClosedWon->value,
                LeadStatus::ClosedLost->value,
                LeadStatus::Dnc->value,
                LeadStatus::DoNotContact->value,
                LeadStatus::BadNumber->value,
            ])
            ->where(function (Builder $q): void {
                $q->whereNull('do_not_contact_until')
                    ->orWhere('do_not_contact_until', '<=', now());
            });
    }

    public function scopeUnassigned(Builder $query): Builder
    {
        return $query->whereNull('assigned_agent_id');
    }

    public function scopeAssignedTo(Builder $query, string $userId): Builder
    {
        return $query->where('assigned_agent_id', $userId);
    }

    /** Leads idle for at least N minutes since assignment without contact. */
    public function scopeStaleAssignments(Builder $query, int $minutes): Builder
    {
        $threshold = now()->subMinutes($minutes);

        return $query
            ->whereNotNull('assigned_agent_id')
            ->where('assigned_at', '<=', $threshold)
            ->where(function (Builder $q) use ($threshold): void {
                $q->whereNull('last_contacted_at')
                    ->orWhere('last_contacted_at', '<', $threshold);
            })
            ->whereIn('status', [LeadStatus::New->value, LeadStatus::Contacted->value]);
    }

    /* ----------------------------------------------------------------------
     | Convenience
     | ---------------------------------------------------------------------- */

    public function isContactable(): bool
    {
        if ($this->is_on_dnc) {
            return false;
        }

        if ($this->status instanceof LeadStatus && $this->status->isTerminal()) {
            return false;
        }

        if ($this->do_not_contact_until !== null && $this->do_not_contact_until->isFuture()) {
            return false;
        }

        return true;
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }
}
