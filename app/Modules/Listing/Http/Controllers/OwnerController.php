<?php

declare(strict_types=1);

namespace App\Modules\Listing\Http\Controllers;

use App\Core\Shared\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * Owner-centric aggregate endpoint for the customer-service screen.
 *
 *   GET /api/owners/{id}/dossier
 *
 * The owner is a Lead row in the database (the listing-fee customer);
 * this endpoint returns everything an agent needs when the owner calls
 * in upset, in one round-trip:
 *
 *   - profile  : identity + status + contact info
 *   - metrics  : lifetime fees, refunded, net, listings live, bookings
 *                produced, current standing (active / at_risk / churned)
 *   - properties        : timeshares the owner owns
 *   - listings          : active and historical marketed offerings
 *   - agreements        : every listing agreement (closed_won deals)
 *                         with their fulfillment status + financial state
 *   - rental_bookings   : every renter booking that came through their
 *                         listings (the success outcome)
 *   - financial_ledger  : payments, refunds, chargebacks chronologically
 *   - cases             : open refund + chargeback cases
 *
 * Pulled together via DB::table joins rather than Eloquent eager-loading
 * because we want one query plan per section, not N+1 lazy loads.
 */
final class OwnerController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function dossier(string $id): JsonResponse
    {
        $tenantId = $this->tenantContext->id();

        // Profile — the lead row IS the owner. We pull through DB::table
        // because Lead's TenantScoped global scope does the same filter
        // and we already have a tenant context resolved.
        $profile = DB::table('leads')
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->first([
                'id', 'first_name', 'last_name', 'email', 'phone',
                'alternate_phone', 'country', 'state', 'city', 'postal_code',
                'timezone', 'status', 'priority', 'score', 'source',
                'has_express_consent', 'is_on_dnc', 'created_at',
            ]);

        if ($profile === null) {
            return response()->json(['message' => 'Owner not found'], 404);
        }

        $profile->full_name = trim(($profile->first_name ?? '').' '.($profile->last_name ?? ''))
            ?: '(unnamed owner)';

        // Properties — timeshares this owner owns.
        $properties = DB::table('properties')
            ->where('tenant_id', $tenantId)
            ->where('owner_id', $id)
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->get([
                'id', 'resort_name', 'resort_brand', 'location_city',
                'location_state', 'unit_number', 'bedrooms', 'sleeps',
                'view_type', 'ownership_type', 'fixed_week_number', 'season',
                'ownership_verified', 'rental_allowed_by_resort',
                'created_at',
            ]);
        $propertyIds = $properties->pluck('id')->all();

        // Agreements — listing-fee deals. We pull the full set so the
        // financial ledger can read them; lifetime metrics aggregate
        // across them.
        $agreements = DB::table('deals')
            ->where('tenant_id', $tenantId)
            ->where('lead_id', $id)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->get([
                'id', 'agent_id', 'fronter_id', 'stage',
                'agreement_status', 'payment_status',
                'listing_fee', 'listing_fee_collected',
                'total_value', 'payable_amount',
                'tcpa_disclosure_completed', 'verification_call_completed',
                'agreement_signed_at', 'closed_at',
                'refund_window_expires_at', 'term_expires_at',
                'created_at',
            ]);
        $agreementIds = $agreements->pluck('id')->all();

        // Listings — the marketed offerings tied to this owner's
        // properties. Joining via property_id keeps the query simple
        // and the listing knows which property it represents.
        $listings = empty($propertyIds)
            ? collect()
            : DB::table('listings')
                ->where('tenant_id', $tenantId)
                ->whereIn('property_id', $propertyIds)
                ->whereNull('deleted_at')
                ->orderByDesc('created_at')
                ->get([
                    'id', 'property_id', 'deal_id',
                    'check_in_date', 'check_out_date',
                    'asking_price', 'owner_payout', 'status',
                    'went_live_at', 'expires_at', 'created_at',
                ]);
        $listingIds = $listings->pluck('id')->all();

        // Per-site distribution counts — drives the "partner sites"
        // section on each listing card.
        $partnerStatuses = empty($listingIds)
            ? collect()
            : DB::table('partner_site_listings as psl')
                ->join('partner_sites as ps', 'ps.id', '=', 'psl.partner_site_id')
                ->where('psl.tenant_id', $tenantId)
                ->whereIn('psl.listing_id', $listingIds)
                ->orderBy('psl.listing_id')
                ->orderBy('ps.name')
                ->get([
                    'psl.id', 'psl.listing_id', 'psl.status',
                    'psl.view_count', 'psl.inquiry_count',
                    'psl.external_url', 'psl.went_live_at',
                    'ps.name as partner_name', 'ps.slug as partner_slug',
                ]);

        $partnersByListing = $partnerStatuses->groupBy('listing_id');

        // Renter-side bookings produced by this owner's listings.
        $rentalBookings = empty($listingIds)
            ? collect()
            : DB::table('bookings')
                ->where('tenant_id', $tenantId)
                ->whereIn('listing_id', $listingIds)
                ->whereNull('deleted_at')
                ->orderByDesc('confirmed_at')
                ->get([
                    'id', 'listing_id', 'renter_name', 'renter_email',
                    'check_in_date', 'check_out_date',
                    'total_price', 'owner_payout', 'our_commission',
                    'status', 'payment_status',
                    'owner_notified_at', 'confirmed_at',
                ]);

        // Financial ledger — every payment associated with this owner
        // (via deal_id IN agreements). Chargebacks and refunds carry
        // parent_payment_id back to the original charge.
        $payments = empty($agreementIds)
            ? collect()
            : DB::table('payments')
                ->where('tenant_id', $tenantId)
                ->whereIn('deal_id', $agreementIds)
                ->orderByDesc('created_at')
                ->get([
                    'id', 'deal_id', 'amount', 'currency',
                    'type', 'status', 'card_brand', 'card_last_four',
                    'authorized_at', 'cleared_at', 'refunded_at',
                    'failure_reason', 'created_at',
                ]);

        // Open compliance cases — populates the status header banner.
        $refundCases = empty($agreementIds)
            ? collect()
            : DB::table('refund_cases')
                ->where('tenant_id', $tenantId)
                ->whereIn('deal_id', $agreementIds)
                ->orderByDesc('opened_at')
                ->get([
                    'id', 'deal_id', 'refund_amount', 'reason',
                    'status', 'opened_at', 'resolved_at',
                ]);

        $chargebackCases = empty($agreementIds)
            ? collect()
            : DB::table('chargeback_cases')
                ->where('tenant_id', $tenantId)
                ->whereIn('deal_id', $agreementIds)
                ->orderByDesc('respond_by_date')
                ->get([
                    'id', 'deal_id', 'disputed_amount', 'reason_code',
                    'status', 'respond_by_date', 'created_at',
                ]);

        // Aggregate metrics — done in PHP so the response carries one
        // numbers payload the frontend can render directly.
        $totalFeesPaid = (float) $payments
            ->where('type', 'charge')
            ->where('status', 'succeeded')
            ->sum('amount');
        $totalRefunded = (float) $payments
            ->where('type', 'refund')
            ->sum('amount');
        $totalChargedBack = (float) $payments
            ->where('type', 'chargeback')
            ->sum('amount');

        $liveListings = $listings
            ->whereIn('status', ['live', 'inquiry_received', 'pending_booking'])
            ->count();

        $rentedBookings = $rentalBookings->whereNotIn('status', ['cancelled'])->count();
        $totalCommissionEarned = (float) $rentalBookings->sum('our_commission');

        // Standing — derived from the worst signal we have.
        $hasOpenChargeback = $chargebackCases
            ->whereIn('status', ['received', 'evidence_gathering', 'evidence_submitted'])
            ->isNotEmpty();
        $hasOpenRefund = $refundCases
            ->whereIn('status', ['opened', 'investigating'])
            ->isNotEmpty();
        $hasRecentPayment = $payments
            ->where('type', 'charge')
            ->where('status', 'succeeded')
            ->isNotEmpty();

        $standing = match (true) {
            $hasOpenChargeback => 'at_risk',
            $hasOpenRefund => 'at_risk',
            $totalFeesPaid <= 0 && $agreements->isNotEmpty() => 'prospect',
            $totalFeesPaid <= 0 => 'unknown',
            ! $hasRecentPayment && $rentedBookings === 0 && $liveListings === 0 => 'churned',
            default => 'active',
        };

        return response()->json([
            'profile' => $profile,
            'metrics' => [
                'total_fees_paid' => round($totalFeesPaid, 2),
                'total_refunded' => round($totalRefunded, 2),
                'total_charged_back' => round($totalChargedBack, 2),
                'net_paid' => round($totalFeesPaid - $totalRefunded - $totalChargedBack, 2),
                'agreements_count' => $agreements->count(),
                'properties_count' => $properties->count(),
                'listings_total' => $listings->count(),
                'listings_live' => $liveListings,
                'bookings_rented' => $rentedBookings,
                'commission_earned' => round($totalCommissionEarned, 2),
                'standing' => $standing,
            ],
            'properties' => $properties->values(),
            'listings' => $listings->map(fn ($l) => array_merge((array) $l, [
                'partner_sites' => $partnersByListing->get($l->id, collect())->values(),
            ])),
            'agreements' => $agreements->values(),
            'rental_bookings' => $rentalBookings->values(),
            'financial_ledger' => $payments->values(),
            'cases' => [
                'refunds' => $refundCases->values(),
                'chargebacks' => $chargebackCases->values(),
            ],
        ]);
    }
}
