<?php

declare(strict_types=1);

namespace App\Modules\CallCenter\Domain\Models;

use App\Modules\Tenant\Domain\Models\User;
use App\Support\Concerns\HasUuid;
use App\Support\Concerns\TenantScoped;
use App\Support\Enums\RoomParticipantRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per Twilio Participant SID inside a video room (or, later, a
 * voice conference). The lifecycle is:
 *
 *   1. Twilio fires participant-connected → handler creates this row
 *      with joined_at = now() and left_at = null.
 *   2. Twilio fires participant-disconnected → handler stamps left_at.
 *
 * The (call_id, role) index serves the in-call participant grid; the
 * (tenant_id, user_id, joined_at) index serves "supervised calls"
 * reports.
 */
final class CallParticipant extends Model
{
    use HasUuid;
    use TenantScoped;

    protected $table = 'call_participants';

    protected $fillable = [
        'tenant_id',
        'call_id',
        'twilio_participant_sid',
        'identity',
        'user_id',
        'role',
        'joined_at',
        'left_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'role' => RoomParticipantRole::class,
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isStillConnected(): bool
    {
        return $this->left_at === null;
    }
}
