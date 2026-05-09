<?php

declare(strict_types=1);

namespace App\Modules\Lead\Domain\Models;

use App\Modules\Tenant\Domain\Models\User;
use App\Support\Concerns\HasUuid;
use App\Support\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tracks a batch ingestion (CSV upload, API push, manual paste).
 *
 * The row counts are eventually consistent — they're written by the
 * ImportLeadsBatchJob as it processes chunks. The status field is the
 * authoritative state machine: pending → processing → completed |
 * completed_with_errors | failed.
 */
final class LeadImport extends Model
{
    use HasUuid;
    use TenantScoped;

    protected $table = 'lead_imports';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_COMPLETED_WITH_ERRORS = 'completed_with_errors';
    public const STATUS_FAILED = 'failed';

    public const SOURCE_CSV = 'csv';
    public const SOURCE_API = 'api';
    public const SOURCE_MANUAL_PASTE = 'manual_paste';

    protected $fillable = [
        'tenant_id',
        'imported_by_id',
        'source',
        'original_filename',
        's3_path',
        'status',
        'total_rows',
        'processed_rows',
        'imported_count',
        'duplicate_count',
        'error_count',
        'column_mapping',
        'errors',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'column_mapping' => 'array',
            'errors' => 'array',
            'total_rows' => 'integer',
            'processed_rows' => 'integer',
            'imported_count' => 'integer',
            'duplicate_count' => 'integer',
            'error_count' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by_id');
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'imported_via_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_COMPLETED_WITH_ERRORS,
            self::STATUS_FAILED,
        ], true);
    }
}
