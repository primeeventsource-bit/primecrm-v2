<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Repurpose `bookings` from "agent sold a vacation" to "renter rented
 * an owner's listing" — the success outcome of the listing service.
 *
 * The legacy bookings model was built for a different business
 * (we sell vacation weeks to a lead, the booking is the lead's stay).
 * In the listing-marketing model, a booking is a rental confirmation:
 * a third party renter pays to use an owner's timeshare week, and we
 * collect a commission on top of the listing fee.
 *
 * The legacy columns (`lead_id`, `inventory_availability_id`, `agent_id`,
 * `total_price`, `paid_amount`, `confirmation_number`) stay where they
 * are. We add the renter-side identity, the listing FK, the inquiry
 * FK, and the financial split that distinguishes commission from
 * owner payout. `inventory_availability_id` is made nullable because
 * new rentals don't draw from our inventory table.
 *
 * The status column already lives in this table; we keep it but the
 * value vocabulary expands at the app layer to include `checked_in`
 * and `no_show`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            // Renter identity — lives only in bookings, not as a full
            // entity, because we don't market to renters; they come
            // in through partner sites.
            $table->string('renter_name')->nullable()->after('lead_id');
            $table->string('renter_email')->nullable()->after('renter_name');
            $table->string('renter_phone', 30)->nullable()->after('renter_email');

            // Domain links — both nullable so legacy rows stay valid.
            // listing_id is the listing they booked. inquiry_id traces
            // back to the rental_inquiry that generated the booking.
            $table->uuid('listing_id')->nullable()->after('renter_phone');
            $table->uuid('inquiry_id')->nullable()->after('listing_id');

            // Financial split — owner_payout is what the timeshare
            // owner gets, our_commission is what we keep. They sum to
            // total_rental_amount which we keep as an alias of the
            // existing total_price column.
            $table->decimal('owner_payout', 10, 2)->nullable()->after('paid_amount');
            $table->decimal('our_commission', 10, 2)->nullable()->after('owner_payout');

            // Service-delivery markers — when did we tell the owner
            // their week rented? Owners get angry if we collect a fee
            // and then never confirm a renter; this column is the
            // measurable proof we delivered.
            $table->timestamp('owner_notified_at')->nullable()->after('confirmed_at');

            // Payment status alongside booking status. The existing
            // `status` column carries booking lifecycle (confirmed /
            // checked_in / completed / cancelled / no_show); this
            // carries the money state.
            $table->string('payment_status')->default('pending')->after('owner_notified_at');
            // pending, deposit_paid, paid_in_full, refunded
        });

        // Make inventory_availability_id nullable — new rentals don't
        // touch our inventory table. Legacy rows keep their FK.
        Schema::table('bookings', function (Blueprint $table): void {
            // FK must be dropped before nullable change, then re-added.
            $table->dropForeign(['inventory_availability_id']);
            $table->uuid('inventory_availability_id')->nullable()->change();
            $table->foreign('inventory_availability_id')
                ->references('id')->on('inventory_availability')
                ->nullOnDelete();
        });

        // Index the new lookup paths
        Schema::table('bookings', function (Blueprint $table): void {
            $table->foreign('listing_id')->references('id')->on('listings')->nullOnDelete();
            // FK to rental_inquiries comes in the next migration since
            // that table doesn't exist yet at this point.
            $table->index(['tenant_id', 'listing_id'], 'bookings_listing_idx');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropForeign(['listing_id']);
            $table->dropIndex('bookings_listing_idx');

            $table->dropForeign(['inventory_availability_id']);
            $table->uuid('inventory_availability_id')->nullable(false)->change();
            $table->foreign('inventory_availability_id')
                ->references('id')->on('inventory_availability');

            $table->dropColumn([
                'renter_name',
                'renter_email',
                'renter_phone',
                'listing_id',
                'inquiry_id',
                'owner_payout',
                'our_commission',
                'owner_notified_at',
                'payment_status',
            ]);
        });
    }
};
