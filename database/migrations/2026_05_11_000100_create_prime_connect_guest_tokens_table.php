<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer-facing guest invite tokens for Prime Connect.
 *
 * An agent inside the CRM mints a token for an existing room; the token
 * resolves to a public URL (/prime-connect/join/{token}) the customer
 * can open in any browser without an account. The token row carries the
 * tenant + room linkage so the public controller can resolve the room
 * without trusting any client-supplied id.
 *
 * Why a dedicated table (rather than stuffing tokens into lobby_metadata):
 *   - The token IS a credential — it deserves a row with FK-cascade
 *     deletion when the call is dropped + a UNIQUE index on the secret.
 *   - The access pattern (look-up-by-token from a public route) wants
 *     an index on the token column specifically.
 *   - One room can have multiple guest tokens (an agent might invite
 *     two people separately). A JSON array would make that awkward.
 *
 * Lifecycle:
 *   - Created by POST /api/prime-connect/rooms/{id}/guest-tokens.
 *   - Used by GET /prime-connect/join/{token} (public, Inertia).
 *   - Twilio JWT minted from POST /api/prime-connect/guest/{token}/access-token.
 *   - Marked used_at on first JWT mint; expired automatically by expires_at.
 *   - Revoked by DELETE /api/prime-connect/rooms/{id}/guest-tokens/{tokenId}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prime_connect_guest_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('call_id'); // the video room (calls.medium = 'video')

            // The shareable secret. UUID v4 hex (no hyphens) gives us 122
            // bits of entropy — comparable to a session id. Indexed unique
            // because public lookups are exact-match.
            $table->string('token', 64)->unique();

            // Optional friendly label that surfaces to the customer
            // ("Hi Maria — your closing call is ready"). Agent-supplied.
            $table->string('display_name', 128)->nullable();

            // The agent who minted the invite; null only if a service
            // job ever issues one (none does today).
            $table->uuid('created_by_user_id')->nullable();

            // Tokens expire — defaults to ~24h in the service layer.
            $table->timestamp('expires_at');

            // First-use timestamp. We don't gate re-use (the customer may
            // refresh the page); this is purely audit signal.
            $table->timestamp('used_at')->nullable();

            // Explicit revocation by the agent. Once set, the public
            // lookup refuses the token regardless of expires_at.
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('call_id')->references('id')->on('calls')->cascadeOnDelete();
            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();

            // Cleanup query: drop expired/revoked tokens via a daily job.
            $table->index(['tenant_id', 'expires_at'], 'pcgt_tenant_expires_idx');
            $table->index(['call_id'], 'pcgt_call_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prime_connect_guest_tokens');
    }
};
