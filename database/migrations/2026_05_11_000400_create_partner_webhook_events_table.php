<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit log of every inbound partner webhook.
 *
 * Without this, debugging an integration is "log into the server and
 * grep nginx access logs". With it, the operator sees every attempt
 * the partner made — including signature failures (most likely cause
 * of a silent integration outage) and validation rejects — in the
 * partner-site card without leaving the CRM.
 *
 * Append-only by design: no updated_at, no soft-delete. An event is
 * the immutable record of a single HTTP attempt; corrections happen
 * by recording the NEXT attempt, not by editing this one.
 *
 * Retention: not enforced at the schema level. A cleanup job
 * (truncate_partner_webhook_events_older_than_90d) can land later if
 * the table grows unboundedly; for now the table is intentionally
 * small-cardinality (low traffic surface) and free to grow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_webhook_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');

            // The site the event arrived for. Resolved BEFORE signature
            // check, so an event row exists even for sig-failed posts
            // (which are the most useful kind to surface).
            $table->uuid('partner_site_id');

            // 'inquiry' or 'booking'. String, not enum, so adding a
            // future event type (cancellation, refund) doesn't need a
            // schema migration.
            $table->string('kind', 32);

            // HTTP status we returned. 201 = created, 200 = duplicate,
            // 401 = bad sig, 404 = unknown slug (rare — we wouldn't
            // get here without a known site), 422 = validation reject.
            $table->smallInteger('http_status');

            // Whether the HMAC verified. False means the partner used
            // the wrong secret or the body was tampered in transit.
            $table->boolean('signature_valid');

            // Partner-supplied ids — populated when parseable from
            // the payload, even on failures, so the operator can
            // correlate with their own side.
            $table->string('external_inquiry_id')->nullable();
            $table->string('external_booking_id')->nullable();

            // The row we created or matched. Null for failures.
            $table->uuid('related_id')->nullable();

            // Why it failed (validation messages, "unknown listing id",
            // etc.). Free-form text; not surfaced to the partner in
            // the HTTP response (the partner gets a curated message),
            // but visible to the operator here.
            $table->text('error_message')->nullable();

            // Forensic context. Partner IPs are stable per environment;
            // a sudden change is a leak signal.
            $table->string('request_ip', 64)->nullable();
            $table->string('user_agent', 500)->nullable();

            // Payload byte count — easy outlier detector for "their
            // payload doubled in size last Tuesday" type issues.
            $table->integer('payload_size_bytes')->default(0);

            $table->timestamp('created_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('partner_site_id')->references('id')->on('partner_sites')->cascadeOnDelete();

            // Feed query: latest events for this site.
            $table->index(
                ['partner_site_id', 'created_at'],
                'pwe_site_created_idx'
            );
            // Cross-site forensics: "all sig failures in the last hour."
            $table->index(
                ['tenant_id', 'signature_valid', 'created_at'],
                'pwe_tenant_sig_created_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_webhook_events');
    }
};
