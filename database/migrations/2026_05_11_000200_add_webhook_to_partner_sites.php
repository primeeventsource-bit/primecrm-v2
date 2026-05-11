<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-way partner integration plumbing.
 *
 * Until now partner sites were strictly outbound — the CRM pushed
 * listings to them. This migration lights up the inbound side so a
 * partner can post inquiries (and, eventually, booking confirmations)
 * back into the CRM, using the CRM as the operator's back office.
 *
 * partner_sites:
 *   webhook_secret           HMAC signing key. Generated at create time,
 *                            rotatable from the partner-site detail UI.
 *                            Plain TEXT — it's a shared secret with the
 *                            partner, NOT a credential we hold on their
 *                            behalf (those live in the encrypted `config`
 *                            column). The secret is presented to the
 *                            partner once and stored in the partner's
 *                            integration config.
 *   webhook_last_received_at Health-check signal. Surfaces a "stale"
 *                            badge in the UI when a site that normally
 *                            posts daily goes quiet.
 *
 * rental_inquiries:
 *   external_inquiry_id      The partner's own id for the inquiry.
 *                            Combined with partner_site_id, gives a
 *                            unique key the webhook handler uses to
 *                            deduplicate retries — partners typically
 *                            re-send the same event on a delivery
 *                            failure, and we don't want duplicate rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_sites', function (Blueprint $table): void {
            $table->string('webhook_secret', 80)->nullable()->after('config');
            $table->timestamp('webhook_last_received_at')->nullable()->after('webhook_secret');
        });

        Schema::table('rental_inquiries', function (Blueprint $table): void {
            // External (partner-supplied) inquiry id. Nullable for
            // direct-channel inquiries that never came through a partner.
            $table->string('external_inquiry_id')->nullable()->after('partner_site_id');

            // Idempotency key for the webhook handler. Unique within a
            // partner_site so two different partners can use the same
            // numeric id space without colliding.
            $table->unique(
                ['partner_site_id', 'external_inquiry_id'],
                'inquiries_partner_external_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('rental_inquiries', function (Blueprint $table): void {
            $table->dropUnique('inquiries_partner_external_unique');
            $table->dropColumn('external_inquiry_id');
        });

        Schema::table('partner_sites', function (Blueprint $table): void {
            $table->dropColumn(['webhook_secret', 'webhook_last_received_at']);
        });
    }
};
