<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Document attachments for bookings.
 *
 * A rental booking accumulates paperwork: the signed rental
 * agreement, a payment receipt, a copy of the guest's ID. Operators
 * need somewhere to keep those alongside the booking record.
 *
 * Stored as a JSON column rather than a dedicated table — same
 * pattern as listings.photos. Each entry is a small object:
 *
 *   {
 *     "url":         "https://.../storage/bookings/{id}/{uuid}.pdf",
 *     "name":        "Rental Agreement - Smith.pdf",   // original filename
 *     "kind":        "agreement",                       // agreement|payment_proof|id|other
 *     "size":        184320,                            // bytes
 *     "uploaded_at": "2026-05-14T12:00:00+00:00"
 *   }
 *
 * The cardinality is low (a handful per booking) so a JSON column is
 * the right call — no join, no N+1, and the booking row carries its
 * own paperwork. If documents ever need their own lifecycle (per-doc
 * audit, signing status, expiry) this graduates to a table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->json('documents')->nullable()->after('partner_metadata');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn('documents');
        });
    }
};
