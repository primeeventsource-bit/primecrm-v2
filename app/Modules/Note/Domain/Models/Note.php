<?php

declare(strict_types=1);

namespace App\Modules\Note\Domain\Models;

use App\Modules\Tenant\Domain\Models\User;
use App\Support\Concerns\HasUuid;
use App\Support\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A polymorphic communication-history entry attached to any entity
 * (Lead, Customer, …). The notable_type/notable_id pair drives every
 * timeline view; see the notes_entity_timeline_idx composite index.
 */
final class Note extends Model
{
    use HasUuid;
    use SoftDeletes;
    use TenantScoped;

    protected $table = 'notes';

    public const KIND_NOTE = 'note';
    public const KIND_CALL = 'call';
    public const KIND_EMAIL = 'email';
    public const KIND_SMS = 'sms';
    public const KIND_SYSTEM = 'system';

    protected $fillable = [
        'tenant_id',
        'notable_type',
        'notable_id',
        'user_id',
        'kind',
        'body',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function notable(): MorphTo
    {
        return $this->morphTo();
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
