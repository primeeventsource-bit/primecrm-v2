<?php

declare(strict_types=1);

namespace App\Modules\CallCenter\Application\Services;

use App\Core\Shared\TenantContext;
use App\Modules\CallCenter\Domain\Models\Call;
use App\Modules\CallCenter\Domain\Models\PrimeConnectGuestToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Mint + validate guest invite tokens for Prime Connect.
 *
 * Two operating modes:
 *
 *   • issue() runs in a STAFF request context — TenantContext is already
 *     set by the tenant middleware, the call_id was validated against
 *     the user's tenant, and the row is written with the current tenant.
 *
 *   • resolve() runs in a PUBLIC request context — no TenantContext yet.
 *     We bypass the tenant scope to find the row, then SET TenantContext
 *     from the row's tenant_id so every downstream query (the room
 *     lookup, the call participant insert, etc.) is properly tenanted.
 *     This is the same pattern AuthController uses during user login.
 *
 * The token secret is a fresh UUID v4 stripped of hyphens — 32 hex
 * characters, 122 bits of entropy. Plenty for a TTL'd credential.
 */
final class GuestTokenService
{
    /** Default TTL when caller doesn't specify. 24h covers most demos. */
    public const DEFAULT_TTL_MINUTES = 60 * 24;

    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * Mint a fresh guest token for an EXISTING video room.
     *
     * Caller must have already verified the user owns the room (or is a
     * supervisor) — this service trusts its callers. The room must
     * belong to the current tenant context; we re-check defensively.
     */
    public function issue(
        Call $room,
        ?string $createdByUserId = null,
        ?string $displayName = null,
        ?int $ttlMinutes = null,
    ): PrimeConnectGuestToken {
        $tenantId = $this->tenants->id();
        if ($tenantId === null || $room->tenant_id !== $tenantId) {
            throw new RuntimeException('Cannot mint guest token outside the room\'s tenant context.');
        }

        $ttlMinutes ??= self::DEFAULT_TTL_MINUTES;

        $token = new PrimeConnectGuestToken();
        $token->tenant_id = $tenantId;
        $token->call_id = $room->id;
        $token->token = self::generateSecret();
        $token->display_name = $displayName;
        $token->created_by_user_id = $createdByUserId;
        $token->expires_at = Carbon::now()->addMinutes($ttlMinutes);
        $token->save();

        return $token;
    }

    /**
     * Resolve a public token to its row. Bypasses the tenant scope (the
     * caller is unauthenticated, so TenantContext is empty); the row
     * itself carries tenant_id and we set it on the context here so the
     * rest of the request runs scoped to that tenant.
     *
     * Returns null when the token doesn't exist, has expired, or has
     * been revoked. We deliberately use the SAME null return for all
     * three cases so the public surface can't enumerate which is which.
     */
    public function resolve(string $secret): ?PrimeConnectGuestToken
    {
        $token = PrimeConnectGuestToken::query()
            ->withoutGlobalScopes()
            ->where('token', $secret)
            ->first();

        if ($token === null || ! $token->isUsable()) {
            return null;
        }

        // Establish tenant context from the token row so downstream
        // tenant-scoped queries (loading the room, the lead, etc.) work
        // without any client-supplied tenant id.
        $this->tenants->set($token->tenant_id);

        return $token;
    }

    /**
     * Mark a token as used on first JWT mint. Idempotent — re-fetches
     * the page after using shouldn't fail; this is audit signal only.
     */
    public function markUsed(PrimeConnectGuestToken $token): void
    {
        if ($token->used_at !== null) {
            return;
        }
        $token->used_at = Carbon::now();
        $token->save();
    }

    /**
     * Revoke a token explicitly. Used when the agent wants to kill an
     * outstanding invite ("oops, wrong customer") without waiting for
     * the TTL to lapse.
     */
    public function revoke(PrimeConnectGuestToken $token): void
    {
        if ($token->revoked_at !== null) {
            return;
        }
        $token->revoked_at = Carbon::now();
        $token->save();
    }

    private static function generateSecret(): string
    {
        // Strip hyphens — keeps the URL short without losing entropy.
        return str_replace('-', '', Str::uuid()->toString());
    }
}
