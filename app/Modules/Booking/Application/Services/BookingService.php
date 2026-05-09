<?php

declare(strict_types=1);

namespace App\Modules\Booking\Application\Services;

use App\Core\Shared\Services\AuditLogService;
use App\Modules\Booking\Domain\Events\BookingCancelled;
use App\Modules\Booking\Domain\Events\BookingConfirmed;
use App\Modules\Booking\Domain\Exceptions\InventoryUnavailableException;
use App\Modules\Booking\Domain\Models\Booking;
use App\Modules\Booking\Domain\Models\InventoryAvailability;
use App\Modules\Booking\Domain\Models\InventoryHold;
use Illuminate\Support\Facades\DB;

/**
 * Promotes a hold into a booking, and handles cancellation/refund.
 *
 * Confirmation rules:
 *   - The hold must be active (released_at IS NULL, expires_at > now()).
 *   - The underlying availability row must be in 'held' status.
 *   - The booking is created with status 'confirmed'; the availability
 *     row flips to 'booked'; the hold is released with reason='converted'.
 *
 * The confirmation_number is generated as "BK-{shortened-uuid}" — unique
 * by virtue of its UUID component, short enough for a customer to read
 * over the phone, and the table's UNIQUE constraint catches collisions.
 */
final class BookingService
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function confirm(InventoryHold $hold, array $details = []): Booking
    {
        return DB::transaction(function () use ($hold, $details): Booking {
            $availability = InventoryAvailability::query()
                ->lockForUpdate()
                ->find($hold->inventory_availability_id);

            if ($availability === null
                || $availability->status !== InventoryAvailability::STATUS_HELD
                || $availability->current_hold_id !== $hold->id
            ) {
                throw InventoryUnavailableException::forUnit(
                    (string) $availability?->inventory_unit_id,
                    (string) $availability?->check_in_date?->toDateString(),
                );
            }

            if (! $hold->isActive()) {
                throw new InventoryUnavailableException('Hold is no longer active.');
            }

            $booking = Booking::query()->create([
                'lead_id' => $hold->lead_id,
                'deal_id' => $hold->deal_id,
                'inventory_availability_id' => $availability->id,
                'agent_id' => $hold->held_by_id,
                'status' => Booking::STATUS_CONFIRMED,
                'total_price' => $availability->current_price,
                'paid_amount' => 0,
                'currency' => $availability->currency,
                'check_in_date' => $availability->check_in_date,
                'check_out_date' => $availability->check_out_date,
                'guest_details' => $details['guest_details'] ?? null,
                'confirmation_number' => $this->generateConfirmationNumber(),
                'confirmed_at' => now(),
            ]);

            $availability->update([
                'status' => InventoryAvailability::STATUS_BOOKED,
                'booking_id' => $booking->id,
                'current_hold_id' => null,
            ]);

            $hold->update([
                'released_at' => now(),
                'release_reason' => InventoryHold::REASON_CONVERTED,
            ]);

            $this->audit->record(
                action: 'booking.confirmed',
                entityType: 'booking',
                entityId: $booking->id,
                context: [
                    'confirmation_number' => $booking->confirmation_number,
                    'lead_id' => $booking->lead_id,
                    'deal_id' => $booking->deal_id,
                    'total_price' => (string) $booking->total_price,
                ],
            );

            BookingConfirmed::dispatch($booking->fresh());

            return $booking->fresh();
        });
    }

    public function cancel(Booking $booking, string $reason): Booking
    {
        if ($booking->isCancelled()) {
            return $booking;
        }

        DB::transaction(function () use ($booking, $reason): void {
            $booking->update([
                'status' => Booking::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            // Free the inventory back to 'available' so it can be rebooked.
            // We mark the prior row as 'cancelled' (outside the partial
            // unique index) and insert a fresh 'available' row for the
            // same (unit, dates). This preserves the booking history while
            // letting the index continue to enforce one active row.
            $oldAvailability = InventoryAvailability::query()
                ->find($booking->inventory_availability_id);

            if ($oldAvailability !== null) {
                $oldAvailability->update([
                    'status' => InventoryAvailability::STATUS_CANCELLED,
                    'booking_id' => $booking->id,
                ]);

                InventoryAvailability::query()->create([
                    'resort_id' => $oldAvailability->resort_id,
                    'inventory_unit_id' => $oldAvailability->inventory_unit_id,
                    'check_in_date' => $oldAvailability->check_in_date,
                    'check_out_date' => $oldAvailability->check_out_date,
                    'nights' => $oldAvailability->nights,
                    'status' => InventoryAvailability::STATUS_AVAILABLE,
                    'base_price' => $oldAvailability->base_price,
                    'current_price' => $oldAvailability->current_price,
                    'currency' => $oldAvailability->currency,
                ]);
            }
        });

        $this->audit->record(
            action: 'booking.cancelled',
            entityType: 'booking',
            entityId: $booking->id,
            context: ['reason' => $reason],
        );

        BookingCancelled::dispatch($booking->fresh(), $reason);

        return $booking->fresh();
    }

    /**
     * BK-XXXXXXXX (8 alphanumeric) — short enough to be readable over
     * the phone, large enough that collisions are vanishingly rare.
     * If we hit one, the unique constraint will reject and the caller
     * retries.
     */
    private function generateConfirmationNumber(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // exclude ambiguous I/O/0/1
        $length = 8;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return "BK-{$out}";
    }
}
