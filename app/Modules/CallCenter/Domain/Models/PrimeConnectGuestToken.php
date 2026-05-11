<?php

declare(strict_types=1);

namespace App\Modules\CallCenter\Domain\Models;

use App\Modules\Tenant\Domain\Models\User;
use App\Support\Concerns\HasUuid;
use App\Support\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Public guest invite token for a Prime Connect (video) room.
 *
 * The token is the entire credential for the public guest flow — no
 * email, no password, no account. The agent mints it, copies the URL,
 * and shares it through any channel they already use with the customer
 * (SMS, email, etc.). The customer opens the URL on any device and
 * lands in the room. Treat token leaks like session leaks: rotate by
 * revoking + re-minting if anything looks off.
 *
 * TenantScoped — staff-side queries (list tokens for my rooms) are
 * tenant-scoped. The PUBLIC guest lookup deliberately bypasses this
 * scope inside GuestTokenService::validate to find the row first, then
 * sets TenantContext from the row before any subsequent query.
 */
final class PrimeConnectGuestToken extends Model
{
    use HasUuid;
    use TenantScoped;

    protected $table = 'prime_connect_guest_tokens';

    protected $fillable = [
        'tenant_id',
        'call_id',
        'token',
        'display_name',
        'created_by_user_id',
        'expires_at',
        'used_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** True if the token is still useable RIGHT NOW. */
    public function isUsable(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        return Carbon::parse($this->expires_at)->isFuture();
    }
}
