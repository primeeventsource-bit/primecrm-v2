<?php

declare(strict_types=1);

namespace App\Modules\Listing\Http\Controllers;

use App\Core\Shared\TenantContext;
use App\Modules\Booking\Domain\Models\Booking;
use App\Modules\Listing\Application\Services\BookingNotifier;
use App\Modules\Listing\Domain\Enums\ListingStatus;
use App\Modules\Listing\Domain\Enums\RentalInquiryStatus;
use App\Modules\Listing\Domain\Models\Listing;
use App\Modules\Listing\Domain\Models\PartnerSite;
use App\Modules\Listing\Domain\Models\PartnerSiteListing;
use App\Modules\Listing\Domain\Models\RentalInquiry;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PUBLIC partner webhook ingest. Partners (Airbnb, Vrbo, RedWeek, etc.)
 * post inquiries here when a renter expresses interest in one of our
 * pushed listings. The CRM becomes the operator's back office: every
 * partner-channel inquiry surfaces in /listings and routes to an agent
 * exactly like a direct inquiry would.
 *
 *   POST /api/partner-webhooks/{slug}/inquiries
 *
 * Authentication: shared HMAC. The partner signs the raw request body
 * with the secret we issued them, sends the signature in the
 * X-Partner-Signature header (hex sha256). We re-compute and
 * constant-time compare. There's no tenant header — the slug identifies
 * the partner_site row, the row carries tenant_id, and we set
 * TenantContext from there before any model query runs.
 *
 * Idempotency: keyed on (partner_site_id, external_inquiry_id). The
 * migration enforces a UNIQUE index so concurrent retries from the
 * partner's side can't double-insert. Duplicate posts return 200 with
 * `duplicate: true` rather than 4xx — partners reading the response
 * for "is this delivered?" should not see a failure for a successful
 * second delivery.
 *
 * Payload shape (JSON):
 *   {
 *     "external_inquiry_id": "ABV-12345",      required
 *     "external_listing_id": "...",            required, matches partner_site_listings.external_listing_id
 *     "renter_name": "Jane Renter",            required
 *     "renter_email": "jane@example.com",      optional
 *     "renter_phone": "+1...",                 optional
 *     "requested_check_in": "2026-07-04",      optional, ISO date
 *     "requested_check_out": "2026-07-11",     optional
 *     "offered_amount": 1850.00,               optional, decimal
 *     "message": "Free text..."                optional
 *   }
 */
final class PartnerWebhookController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly BookingNotifier $notifier,
    ) {}

    public function inquiry(Request $request, string $slug): JsonResponse
    {
        // 1. Locate the site by slug. We bypass the global tenant scope
        //    because no tenant is set yet (the request is unauthenticated);
        //    we'll set the scope from the row before anything else.
        $site = PartnerSite::query()
            ->withoutGlobalScopes()
            ->where('slug', $slug)
            ->first();

        if ($site === null) {
            return response()->json(['message' => 'Unknown partner site.'], 404);
        }

        // 2. Verify HMAC. Refuse if the partner hasn't been issued a
        //    secret yet, OR the signature header is missing, OR doesn't
        //    match. All three collapse to a single 401 so the partner
        //    can't probe which case they hit.
        if (! $this->verifySignature($request, $site)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        // 3. Set TenantContext from the row — every model query below
        //    runs tenant-scoped without any client-supplied tenant id.
        $this->tenants->set($site->tenant_id);

        $payload = $request->validate([
            'external_inquiry_id' => ['required', 'string', 'max:128'],
            'external_listing_id' => ['required', 'string', 'max:128'],
            'renter_name' => ['required', 'string', 'max:200'],
            'renter_email' => ['nullable', 'email', 'max:200'],
            'renter_phone' => ['nullable', 'string', 'max:30'],
            'requested_check_in' => ['nullable', 'date'],
            'requested_check_out' => ['nullable', 'date', 'after_or_equal:requested_check_in'],
            'offered_amount' => ['nullable', 'numeric', 'min:0'],
            'message' => ['nullable', 'string', 'max:5000'],
        ]);

        // 4. Resolve the listing via the partner_site_listings row that
        //    carries the partner's external_listing_id. If we don't
        //    have a match the partner is referencing a listing we never
        //    pushed (or one we've since archived) — reject 422.
        $psl = PartnerSiteListing::query()
            ->where('partner_site_id', $site->id)
            ->where('external_listing_id', $payload['external_listing_id'])
            ->first();

        if ($psl === null) {
            return response()->json([
                'message' => 'Unknown external_listing_id for this partner site.',
            ], 422);
        }

        // 5. Insert the inquiry. The unique index handles idempotency —
        //    a re-post of the same external_inquiry_id throws a
        //    QueryException with SQLSTATE 23000 which we catch + return
        //    a friendly 200 with duplicate: true.
        try {
            $inquiry = new RentalInquiry();
            $inquiry->tenant_id = $site->tenant_id;
            $inquiry->listing_id = $psl->listing_id;
            $inquiry->partner_site_id = $site->id;
            $inquiry->external_inquiry_id = $payload['external_inquiry_id'];
            $inquiry->renter_name = $payload['renter_name'];
            $inquiry->renter_email = $payload['renter_email'] ?? null;
            $inquiry->renter_phone = $payload['renter_phone'] ?? null;
            $inquiry->requested_check_in = $payload['requested_check_in'] ?? null;
            $inquiry->requested_check_out = $payload['requested_check_out'] ?? null;
            $inquiry->offered_amount = $payload['offered_amount'] ?? null;
            $inquiry->message = $payload['message'] ?? null;
            $inquiry->status = RentalInquiryStatus::New->value;
            $inquiry->save();
        } catch (QueryException $e) {
            // SQLSTATE 23000 = integrity constraint violation; here, our
            // unique index on (partner_site_id, external_inquiry_id).
            if ($e->getCode() === '23000') {
                $existing = RentalInquiry::query()
                    ->where('partner_site_id', $site->id)
                    ->where('external_inquiry_id', $payload['external_inquiry_id'])
                    ->first();

                return response()->json([
                    'ok' => true,
                    'duplicate' => true,
                    'inquiry_id' => $existing?->id,
                ]);
            }
            throw $e;
        }

        // 6. Bump the engagement counter on the junction row + stamp
        //    the site's "last activity" so the UI can flag stale sites.
        $psl->increment('inquiry_count');
        $site->forceFill(['webhook_last_received_at' => Carbon::now()])->save();

        return response()->json([
            'ok' => true,
            'duplicate' => false,
            'inquiry_id' => $inquiry->id,
        ], 201);
    }

    /**
     * Booking confirmation from a partner. Closes the loop on the
     * partner-site flow: an inquiry came in, the partner converted it
     * (or the renter booked directly without an inquiry), and now we
     * need to record the rental, mark the inquiry as booked, flip the
     * listing to Booked, and notify the owner.
     *
     *   POST /api/partner-webhooks/{slug}/bookings
     *
     * Two payload shapes are accepted:
     *
     *   1. Inquiry-first (recommended): include `external_inquiry_id`.
     *      We chain through the existing RentalInquiry to find the
     *      listing, attribute the agent (inquiry.handled_by), and link
     *      booking.inquiry_id back to the inquiry.
     *
     *   2. Direct booking: omit `external_inquiry_id`, include
     *      `external_listing_id`. We resolve the listing via the
     *      partner_site_listings row and create a booking without an
     *      inquiry chain. Used for partners that don't surface
     *      inquiries to us before a booking lands.
     *
     * Idempotent via UNIQUE (partner_site_id, external_booking_id).
     * Re-posts return 200 with the existing booking — partners retrying
     * on a delivery failure should never see a 4xx for a successful
     * second attempt.
     */
    public function booking(Request $request, string $slug): JsonResponse
    {
        $site = PartnerSite::query()
            ->withoutGlobalScopes()
            ->where('slug', $slug)
            ->first();

        if ($site === null) {
            return response()->json(['message' => 'Unknown partner site.'], 404);
        }

        if (! $this->verifySignature($request, $site)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $this->tenants->set($site->tenant_id);

        $payload = $request->validate([
            'external_booking_id' => ['required', 'string', 'max:128'],
            'external_inquiry_id' => ['nullable', 'string', 'max:128'],
            // Required only when we can't resolve via an inquiry.
            'external_listing_id' => ['nullable', 'string', 'max:128'],
            'renter_name' => ['required', 'string', 'max:200'],
            'renter_email' => ['nullable', 'email', 'max:200'],
            'renter_phone' => ['nullable', 'string', 'max:30'],
            'check_in_date' => ['required', 'date'],
            'check_out_date' => ['required', 'date', 'after_or_equal:check_in_date'],
            'total_price' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            // Owner payout is optional — if omitted we derive it from
            // the listing's stored commission percentage.
            'owner_payout' => ['nullable', 'numeric', 'min:0'],
            'commission_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'payment_status' => ['nullable', 'in:pending,deposit_paid,paid_in_full,refunded'],
            // Free-form audit trail — partner fees, request id, etc.
            'metadata' => ['nullable', 'array'],
        ]);

        // ───── Idempotency check ─────
        $existing = Booking::query()
            ->where('partner_site_id', $site->id)
            ->where('external_booking_id', $payload['external_booking_id'])
            ->first();
        if ($existing !== null) {
            return response()->json([
                'ok' => true,
                'duplicate' => true,
                'booking_id' => $existing->id,
                'confirmation_number' => $existing->confirmation_number,
            ]);
        }

        // ───── Resolve listing + inquiry context ─────
        $inquiry = null;
        $listing = null;
        $psl = null;

        if (! empty($payload['external_inquiry_id'])) {
            $inquiry = RentalInquiry::query()
                ->where('partner_site_id', $site->id)
                ->where('external_inquiry_id', $payload['external_inquiry_id'])
                ->first();
            if ($inquiry === null) {
                return response()->json([
                    'message' => 'Unknown external_inquiry_id for this partner site.',
                ], 422);
            }
            $listing = Listing::query()->find($inquiry->listing_id);
            $psl = PartnerSiteListing::query()
                ->where('partner_site_id', $site->id)
                ->where('listing_id', $inquiry->listing_id)
                ->first();
        } elseif (! empty($payload['external_listing_id'])) {
            $psl = PartnerSiteListing::query()
                ->where('partner_site_id', $site->id)
                ->where('external_listing_id', $payload['external_listing_id'])
                ->first();
            if ($psl === null) {
                return response()->json([
                    'message' => 'Unknown external_listing_id for this partner site.',
                ], 422);
            }
            $listing = Listing::query()->find($psl->listing_id);
        } else {
            return response()->json([
                'message' => 'Either external_inquiry_id or external_listing_id is required.',
            ], 422);
        }

        if ($listing === null) {
            return response()->json(['message' => 'Listing not found.'], 422);
        }

        // ───── Compute commission split ─────
        // Priority: explicit owner_payout wins, then explicit
        // commission_pct, then the listing's stored pct, then a 15%
        // floor that matches RentalBookingController::store().
        $total = (float) $payload['total_price'];
        $pct = $payload['commission_pct']
            ?? ($listing->our_commission_pct !== null
                ? (float) $listing->our_commission_pct
                : null);

        if (array_key_exists('owner_payout', $payload) && $payload['owner_payout'] !== null) {
            $ownerPayout = (float) $payload['owner_payout'];
            $ourCommission = round($total - $ownerPayout, 2);
        } else {
            $finalPct = $pct !== null ? (float) $pct : 15.0;
            $ourCommission = round($total * ($finalPct / 100), 2);
            $ownerPayout = round($total - $ourCommission, 2);
        }

        // ───── Agent attribution ─────
        // Best-effort: the inquiry handler if any, else the deal's
        // closer, else null. The column is nullable for exactly this
        // case — better to record an un-attributed booking than reject.
        $agentId = $inquiry?->handled_by;
        if ($agentId === null && $listing->deal_id !== null) {
            $dealAgent = DB::table('deals')
                ->where('id', $listing->deal_id)
                ->value('agent_id');
            if (is_string($dealAgent) && $dealAgent !== '') {
                $agentId = $dealAgent;
            }
        }

        // ───── Insert booking + flip listing + mark inquiry, atomically ─────
        try {
            $bookingPayload = [
                'tenant_id' => $site->tenant_id,
                'lead_id' => $listing->property?->owner_id,
                'deal_id' => $listing->deal_id,
                'agent_id' => $agentId,
                'listing_id' => $listing->id,
                'inquiry_id' => $inquiry?->id,
                'partner_site_id' => $site->id,
                'external_booking_id' => $payload['external_booking_id'],
                'renter_name' => $payload['renter_name'],
                'renter_email' => $payload['renter_email'] ?? null,
                'renter_phone' => $payload['renter_phone'] ?? null,
                'check_in_date' => $payload['check_in_date'],
                'check_out_date' => $payload['check_out_date'],
                'total_price' => $total,
                'paid_amount' => 0,
                'currency' => $payload['currency'] ?? 'USD',
                'owner_payout' => $ownerPayout,
                'our_commission' => $ourCommission,
                'status' => Booking::STATUS_CONFIRMED,
                'payment_status' => $payload['payment_status'] ?? 'pending',
                'confirmation_number' => 'PV-'.strtoupper(Str::random(8)),
                'confirmed_at' => Carbon::now(),
                'partner_metadata' => $payload['metadata'] ?? null,
            ];

            $booking = DB::transaction(function () use ($bookingPayload, $listing, $inquiry, $psl, $site): Booking {
                $b = Booking::query()->create($bookingPayload);

                $listing->forceFill([
                    'status' => ListingStatus::Booked->value,
                ])->save();

                if ($inquiry !== null && $inquiry->status !== RentalInquiryStatus::Booked) {
                    $inquiry->forceFill([
                        'status' => RentalInquiryStatus::Booked->value,
                        'responded_at' => $inquiry->responded_at ?? Carbon::now(),
                    ])->save();
                }

                if ($psl !== null) {
                    // Bump for "this listing went through this partner
                    // end-to-end" — useful for partner-quality scoring.
                    $psl->forceFill(['last_synced_at' => Carbon::now()])->save();
                }

                $site->forceFill(['webhook_last_received_at' => Carbon::now()])->save();

                return $b->refresh();
            });
        } catch (QueryException $e) {
            // Race-condition fallback for the unique index — two
            // concurrent posts of the same external_booking_id.
            if ($e->getCode() === '23000') {
                $existing = Booking::query()
                    ->where('partner_site_id', $site->id)
                    ->where('external_booking_id', $payload['external_booking_id'])
                    ->first();

                return response()->json([
                    'ok' => true,
                    'duplicate' => true,
                    'booking_id' => $existing?->id,
                    'confirmation_number' => $existing?->confirmation_number,
                ]);
            }
            throw $e;
        }

        // ───── Notify the owner (post-commit) ─────
        // Failure here doesn't roll back the booking — the rental is
        // confirmed regardless. The notifier itself is idempotent.
        try {
            $this->notifier->notifyOwnerOfBooking($booking, $listing, null);
            $booking = $booking->refresh();
        } catch (\Throwable) {
            // Swallow — surfaced via the owner's notes timeline being
            // empty if it failed; operator can re-trigger manually.
        }

        return response()->json([
            'ok' => true,
            'duplicate' => false,
            'booking_id' => $booking->id,
            'confirmation_number' => $booking->confirmation_number,
            'owner_payout' => (float) $booking->owner_payout,
            'our_commission' => (float) $booking->our_commission,
        ], 201);
    }

    /**
     * Constant-time HMAC verification. The signature is hex-encoded
     * sha256 of the raw request body keyed by the partner's secret.
     *
     * We deliberately accept both "sha256=..." and a bare hex string
     * — different partners format signatures differently and the
     * scheme prefix is informational, not a security boundary.
     */
    private function verifySignature(Request $request, PartnerSite $site): bool
    {
        if ($site->webhook_secret === null || $site->webhook_secret === '') {
            return false;
        }

        $header = $request->header('X-Partner-Signature');
        if (! is_string($header) || $header === '') {
            return false;
        }

        // Strip optional "sha256=" prefix.
        $signature = str_starts_with($header, 'sha256=')
            ? substr($header, 7)
            : $header;

        $expected = hash_hmac('sha256', $request->getContent(), $site->webhook_secret);

        return hash_equals($expected, $signature);
    }
}
