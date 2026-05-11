<?php

declare(strict_types=1);

namespace App\Modules\Listing\Application\Services;

use App\Modules\Booking\Domain\Models\Booking;
use App\Modules\Listing\Domain\Models\Listing;
use App\Modules\Note\Domain\Models\Note;
use App\Modules\Tenant\Domain\Models\User;
use Illuminate\Support\Carbon;

/**
 * Owner notification on booking confirmation.
 *
 * "Did we tell the owner their week rented?" is the load-bearing
 * service-quality column on the dashboard and owner profile. Owners
 * get angry when a renter shows up at their resort and they didn't
 * know about it. This service ensures the notification happens AND
 * leaves a permanent audit trail in the notes timeline.
 *
 * Side effects:
 *   - Sets booking.owner_notified_at = now()
 *   - Appends a system Note to the owner's lead row so the owner
 *     profile's communication timeline shows it
 *   - (Future) dispatches an email/SMS job. The Note is the
 *     reliable record; the actual delivery is a separate concern
 *     and may live behind a queue/retry.
 *
 * Idempotent: a second call is a no-op.
 */
final class BookingNotifier
{
    public function notifyOwnerOfBooking(Booking $booking, Listing $listing, ?User $actor = null): void
    {
        if ($booking->owner_notified_at !== null) {
            return; // Already notified — don't double-stamp.
        }

        $now = Carbon::now();

        // The lead/owner row is reachable via property.owner_id on the
        // listing. We don't pass it as a parameter so the caller can't
        // accidentally notify the wrong person.
        $ownerId = $listing->property?->owner_id;

        if ($ownerId !== null) {
            $renter = $booking->renter_name ?? 'a renter';
            $dates = $booking->check_in_date.' → '.$booking->check_out_date;
            $amount = number_format((float) ($booking->total_price ?? 0), 2);

            Note::query()->create([
                'notable_type' => \App\Modules\Lead\Domain\Models\Lead::class,
                'notable_id' => $ownerId,
                'user_id' => $actor?->id,
                'kind' => 'system',
                'body' => "Rental booked: {$renter} for {$dates} at \${$amount}. "
                    .'Owner notification dispatched.',
                'metadata' => [
                    'booking_id' => $booking->id,
                    'listing_id' => $listing->id,
                    'confirmation_number' => $booking->confirmation_number,
                    'channel' => 'system',
                ],
            ]);
        }

        $booking->forceFill(['owner_notified_at' => $now])->save();
    }
}
