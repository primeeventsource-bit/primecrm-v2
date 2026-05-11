<?php

declare(strict_types=1);

namespace App\Modules\Listing\Http\Controllers;

use App\Core\Shared\TenantContext;
use App\Modules\Booking\Domain\Models\Booking;
use App\Modules\Listing\Application\Services\BookingNotifier;
use App\Modules\Listing\Domain\Enums\ListingStatus;
use App\Modules\Listing\Domain\Enums\RentalInquiryStatus;
use App\Modules\Listing\Domain\Models\Listing;
use App\Modules\Listing\Domain\Models\RentalInquiry;
use App\Modules\Note\Domain\Models\Note;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Renter-inquiry workflow.
 *
 *   POST /api/rental-inquiries/{id}/respond
 *        Mark the inquiry as responded; write a note. If offered
 *        amount is passed, the inquiry moves to negotiating.
 *
 *   POST /api/rental-inquiries/{id}/mark-lost
 *        Renter abandoned / mismatch / cancelled. Status → lost.
 *
 *   POST /api/rental-inquiries/{id}/book
 *        Convert the inquiry into a confirmed Booking. Transactional:
 *        creates the booking, links it back to the inquiry, sets
 *        the listing status to 'booked', dispatches owner
 *        notification. Returns the new booking.
 *
 * Tenant-scoped via the RentalInquiry model's TenantScoped trait;
 * we also re-verify by fetching the listing under the same scope.
 */
final class RentalInquiryController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly BookingNotifier $notifier,
    ) {}

    public function respond(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'offered_amount' => ['nullable', 'numeric', 'min:0', 'max:99999.99'],
        ]);

        $inquiry = RentalInquiry::query()->find($id);
        if ($inquiry === null) {
            return response()->json(['message' => 'Inquiry not found'], 404);
        }

        $message = (string) $request->string('message');
        $amount = $request->filled('offered_amount')
            ? (float) $request->input('offered_amount')
            : null;

        // If we sent a counter-offer, move into negotiating; otherwise
        // just responded. A subsequent respond() can re-flip back to
        // responded or stay in negotiating — UI controls intent.
        $nextStatus = $amount !== null && $amount > 0
            ? RentalInquiryStatus::Negotiating
            : RentalInquiryStatus::Responded;

        $inquiry->forceFill([
            'status' => $nextStatus->value,
            'responded_at' => Carbon::now(),
            'handled_by' => $request->user()?->id,
            'offered_amount' => $amount ?? $inquiry->offered_amount,
        ])->save();

        // Audit trail — append to the listing's notes timeline so the
        // operator can review the conversation later. notable is the
        // listing (using class string so the polymorphic timeline
        // works across listings AND owners).
        Note::query()->create([
            'notable_type' => Listing::class,
            'notable_id' => $inquiry->listing_id,
            'user_id' => $request->user()?->id,
            'kind' => 'note',
            'body' => "Responded to {$inquiry->renter_name}: {$message}"
                .($amount ? " (countered at \$".number_format($amount, 2).')' : ''),
            'metadata' => [
                'inquiry_id' => $inquiry->id,
                'direction' => 'outbound',
            ],
        ]);

        return response()->json([
            'message' => $amount !== null
                ? 'Counter-offer sent.'
                : 'Response sent.',
            'data' => $this->reshape($inquiry->refresh()),
        ]);
    }

    public function markLost(string $id): JsonResponse
    {
        $inquiry = RentalInquiry::query()->find($id);
        if ($inquiry === null) {
            return response()->json(['message' => 'Inquiry not found'], 404);
        }

        $inquiry->forceFill([
            'status' => RentalInquiryStatus::Lost->value,
        ])->save();

        return response()->json([
            'message' => 'Inquiry marked lost.',
            'data' => $this->reshape($inquiry->refresh()),
        ]);
    }

    /**
     * Convert an inquiry into a confirmed Booking.
     *
     * The body may override total amount, dates, and renter contact
     * info; defaults pull from the inquiry. Booking creation, listing
     * status flip, inquiry status flip, and owner notification all
     * happen inside one DB transaction.
     */
    public function book(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'check_in_date' => ['nullable', 'date'],
            'check_out_date' => ['nullable', 'date', 'after_or_equal:check_in_date'],
            'total_price' => ['nullable', 'numeric', 'min:0', 'max:99999.99'],
            'commission_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'renter_name' => ['nullable', 'string', 'max:200'],
            'renter_email' => ['nullable', 'email', 'max:200'],
            'renter_phone' => ['nullable', 'string', 'max:30'],
            'payment_status' => ['nullable', 'in:pending,deposit_paid,paid_in_full'],
        ]);

        $inquiry = RentalInquiry::query()->find($id);
        if ($inquiry === null) {
            return response()->json(['message' => 'Inquiry not found'], 404);
        }
        if ($inquiry->status === RentalInquiryStatus::Booked) {
            return response()->json([
                'message' => 'Inquiry has already converted to a booking.',
            ], 409);
        }

        $listing = Listing::query()->find($inquiry->listing_id);
        if ($listing === null) {
            return response()->json(['message' => 'Underlying listing not found'], 404);
        }

        // Pull defaults from inquiry/listing; let the body override.
        $checkIn = $request->date('check_in_date') ?? $inquiry->requested_check_in
            ?? $listing->check_in_date;
        $checkOut = $request->date('check_out_date') ?? $inquiry->requested_check_out
            ?? $listing->check_out_date;

        $total = $request->filled('total_price')
            ? (float) $request->input('total_price')
            : (float) ($inquiry->offered_amount ?? $listing->asking_price);

        $commissionPct = $request->filled('commission_pct')
            ? (float) $request->input('commission_pct')
            : (float) ($listing->our_commission_pct ?? 15.0);

        $ourCommission = round($total * ($commissionPct / 100), 2);
        $ownerPayout = round($total - $ourCommission, 2);

        $payload = [
            'lead_id' => $listing->property?->owner_id,
            'deal_id' => $listing->deal_id,
            'agent_id' => $request->user()?->id,
            'listing_id' => $listing->id,
            'inquiry_id' => $inquiry->id,
            'renter_name' => (string) ($request->string('renter_name') ?: $inquiry->renter_name),
            'renter_email' => (string) ($request->string('renter_email') ?: $inquiry->renter_email),
            'renter_phone' => (string) ($request->string('renter_phone') ?: $inquiry->renter_phone),
            'check_in_date' => $checkIn,
            'check_out_date' => $checkOut,
            'total_price' => $total,
            'paid_amount' => 0,
            'currency' => 'USD',
            'owner_payout' => $ownerPayout,
            'our_commission' => $ourCommission,
            'status' => 'confirmed',
            'payment_status' => $request->string('payment_status', 'pending')->value(),
            'confirmation_number' => 'PV-'.strtoupper(Str::random(8)),
            'confirmed_at' => Carbon::now(),
        ];

        // Transaction: booking + inquiry + listing all flip together,
        // notifier appends to it so a failure rolls back atomically.
        $booking = DB::transaction(function () use ($payload, $inquiry, $listing): Booking {
            $b = Booking::query()->create($payload);

            $inquiry->forceFill([
                'status' => RentalInquiryStatus::Booked->value,
            ])->save();

            // Listing status reflects the new reality.
            $listing->forceFill([
                'status' => ListingStatus::Booked->value,
            ])->save();

            return $b->refresh();
        });

        // Owner notification — outside the transaction so a notify
        // failure doesn't roll back the booking itself, but the
        // notifier itself is idempotent.
        $this->notifier->notifyOwnerOfBooking($booking, $listing, $request->user());

        return response()->json([
            'message' => 'Booking confirmed and owner notified.',
            'data' => [
                'booking_id' => $booking->id,
                'confirmation_number' => $booking->confirmation_number,
                'total_price' => (float) $booking->total_price,
                'owner_payout' => (float) $booking->owner_payout,
                'our_commission' => (float) $booking->our_commission,
                'inquiry_status' => $inquiry->refresh()->status->value,
                'listing_status' => $listing->refresh()->status->value,
                'owner_notified_at' => $booking->refresh()->owner_notified_at?->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function reshape(RentalInquiry $inquiry): array
    {
        return [
            'id' => $inquiry->id,
            'listing_id' => $inquiry->listing_id,
            'renter_name' => $inquiry->renter_name,
            'renter_email' => $inquiry->renter_email,
            'renter_phone' => $inquiry->renter_phone,
            'requested_check_in' => $inquiry->requested_check_in?->toDateString(),
            'requested_check_out' => $inquiry->requested_check_out?->toDateString(),
            'offered_amount' => $inquiry->offered_amount !== null
                ? (float) $inquiry->offered_amount : null,
            'status' => $inquiry->status?->value,
            'responded_at' => $inquiry->responded_at?->toIso8601String(),
            'handled_by' => $inquiry->handled_by,
        ];
    }
}
