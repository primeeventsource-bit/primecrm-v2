<?php

declare(strict_types=1);

namespace App\Modules\Listing\Http\Controllers;

use App\Core\Shared\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * Listing-management endpoints.
 *
 *   GET /api/listings          paginated index for the Listings hub
 *   GET /api/listings/{id}     single listing detail (multi-site grid +
 *                              inquiries + booking + activity log)
 *
 * Tabs (drives the index `tab` param):
 *   pending_distribution
 *   live          (live + inquiry_received + pending_booking)
 *   with_inquiries (status='inquiry_received' OR has inquiries)
 *   booked
 *   expired_unrented
 *   all
 *
 * The hub is the post-sale operational view — owners ask "where's my
 * listing?" and this is what answers the question.
 */
final class ListingController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'tab' => ['nullable', 'in:pending_distribution,live,with_inquiries,booked,expired_unrented,all'],
            'q' => ['nullable', 'string', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', 'in:check_in_date,asking_price,created_at,went_live_at'],
            'direction' => ['nullable', 'in:asc,desc'],
        ]);

        $tenantId = $this->tenantContext->id();
        $tab = $request->string('tab', 'all')->value();
        $q = $request->string('q')->value();
        $perPage = (int) $request->integer('per_page', 25);
        $page = (int) $request->integer('page', 1);
        $sort = $request->string('sort', 'check_in_date')->value();
        $dir = $request->string('direction', 'asc')->value();

        // Base query — listings + property + owner via the lead row.
        $base = DB::table('listings as l')
            ->join('properties as p', 'p.id', '=', 'l.property_id')
            ->join('leads as o', 'o.id', '=', 'p.owner_id')
            ->where('l.tenant_id', $tenantId)
            ->whereNull('l.deleted_at');

        // Tab filter — translates the visual tab into status SQL.
        match ($tab) {
            'pending_distribution' => $base->where('l.status', 'pending_distribution'),
            'live' => $base->whereIn('l.status', ['live', 'inquiry_received', 'pending_booking']),
            'with_inquiries' => $base->where('l.status', 'inquiry_received'),
            'booked' => $base->where('l.status', 'booked'),
            'expired_unrented' => $base->where('l.status', 'unrented_expired'),
            default => null,
        };

        // Search — owner name OR resort. Phone match left to /leads.
        if ($q !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';
            $base->where(function ($qq) use ($like): void {
                $qq->where('o.first_name', 'like', $like)
                    ->orWhere('o.last_name', 'like', $like)
                    ->orWhere('p.resort_name', 'like', $like);
            });
        }

        // Total count BEFORE applying limit/offset; clone the query to
        // avoid mutating $base for the data fetch.
        $total = (clone $base)->count();

        // Sort whitelist already validated; both columns we sort on
        // belong to `listings`, so prefix with `l.`.
        $rows = $base
            ->orderBy('l.'.$sort, $dir)
            ->limit($perPage)
            ->offset(($page - 1) * $perPage)
            ->get([
                'l.id', 'l.status', 'l.check_in_date', 'l.check_out_date',
                'l.asking_price', 'l.owner_payout', 'l.went_live_at',
                'l.expires_at', 'l.created_at',
                'l.property_id', 'l.deal_id',
                'p.resort_name', 'p.resort_brand',
                'p.location_city', 'p.location_state',
                'o.id as owner_id', 'o.first_name', 'o.last_name',
            ]);

        // Per-listing partner-site counters in one round-trip. Avoids
        // N+1 if we'd asked for partner_site rows per listing.
        $listingIds = $rows->pluck('id')->all();
        $partnerCounts = empty($listingIds)
            ? collect()
            : DB::table('partner_site_listings')
                ->where('tenant_id', $tenantId)
                ->whereIn('listing_id', $listingIds)
                ->groupBy('listing_id')
                ->selectRaw('
                    listing_id,
                    COUNT(*) AS sites_total,
                    SUM(CASE WHEN status = \'live\' THEN 1 ELSE 0 END) AS sites_live,
                    SUM(CASE WHEN status = \'rejected\' THEN 1 ELSE 0 END) AS sites_rejected,
                    SUM(CASE WHEN status = \'paused\' THEN 1 ELSE 0 END) AS sites_paused,
                    COALESCE(SUM(view_count), 0) AS total_views,
                    COALESCE(SUM(inquiry_count), 0) AS total_inquiries
                ')
                ->get()
                ->keyBy('listing_id');

        // Site status pills per listing (mini-row of which sites are up).
        $sitePills = empty($listingIds)
            ? collect()
            : DB::table('partner_site_listings as psl')
                ->join('partner_sites as ps', 'ps.id', '=', 'psl.partner_site_id')
                ->where('psl.tenant_id', $tenantId)
                ->whereIn('psl.listing_id', $listingIds)
                ->orderBy('psl.listing_id')
                ->orderBy('ps.name')
                ->get(['psl.listing_id', 'psl.status', 'ps.name', 'ps.slug']);

        $sitePillsByListing = $sitePills->groupBy('listing_id');

        $now = now();

        $data = $rows->map(function ($r) use ($partnerCounts, $sitePillsByListing, $now) {
            $counts = $partnerCounts->get($r->id);
            $daysLive = $r->went_live_at
                ? (int) round((strtotime((string) $now) - strtotime($r->went_live_at)) / 86400)
                : null;

            return [
                'id' => $r->id,
                'status' => $r->status,
                'check_in_date' => $r->check_in_date,
                'check_out_date' => $r->check_out_date,
                'asking_price' => (float) $r->asking_price,
                'owner_payout' => (float) $r->owner_payout,
                'went_live_at' => $r->went_live_at,
                'expires_at' => $r->expires_at,
                'days_live' => $daysLive,
                'created_at' => $r->created_at,
                'property' => [
                    'id' => $r->property_id,
                    'resort_name' => $r->resort_name,
                    'resort_brand' => $r->resort_brand,
                    'location_city' => $r->location_city,
                    'location_state' => $r->location_state,
                ],
                'owner' => [
                    'id' => $r->owner_id,
                    'name' => trim(($r->first_name ?? '').' '.($r->last_name ?? ''))
                        ?: '(unnamed owner)',
                ],
                'deal_id' => $r->deal_id,
                'partner_summary' => $counts ? [
                    'sites_total' => (int) $counts->sites_total,
                    'sites_live' => (int) $counts->sites_live,
                    'sites_rejected' => (int) $counts->sites_rejected,
                    'sites_paused' => (int) $counts->sites_paused,
                    'total_views' => (int) $counts->total_views,
                    'total_inquiries' => (int) $counts->total_inquiries,
                ] : [
                    'sites_total' => 0, 'sites_live' => 0,
                    'sites_rejected' => 0, 'sites_paused' => 0,
                    'total_views' => 0, 'total_inquiries' => 0,
                ],
                'partner_pills' => $sitePillsByListing->get($r->id, collect())
                    ->map(fn ($p) => [
                        'name' => $p->name,
                        'slug' => $p->slug,
                        'status' => $p->status,
                    ])->values(),
            ];
        });

        // Tab counts — small extra round-trip so the tabs show numbers
        // even on filtered/searched results. Uses the SAME search filter
        // so the badge matches the table.
        $countsBase = DB::table('listings as l')
            ->join('properties as p', 'p.id', '=', 'l.property_id')
            ->join('leads as o', 'o.id', '=', 'p.owner_id')
            ->where('l.tenant_id', $tenantId)
            ->whereNull('l.deleted_at');

        if ($q !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';
            $countsBase->where(function ($qq) use ($like): void {
                $qq->where('o.first_name', 'like', $like)
                    ->orWhere('o.last_name', 'like', $like)
                    ->orWhere('p.resort_name', 'like', $like);
            });
        }

        $tabCounts = $countsBase
            ->selectRaw('
                COUNT(*) AS total,
                SUM(CASE WHEN l.status = \'pending_distribution\' THEN 1 ELSE 0 END) AS pending_distribution,
                SUM(CASE WHEN l.status IN (\'live\', \'inquiry_received\', \'pending_booking\') THEN 1 ELSE 0 END) AS live,
                SUM(CASE WHEN l.status = \'inquiry_received\' THEN 1 ELSE 0 END) AS with_inquiries,
                SUM(CASE WHEN l.status = \'booked\' THEN 1 ELSE 0 END) AS booked,
                SUM(CASE WHEN l.status = \'unrented_expired\' THEN 1 ELSE 0 END) AS expired_unrented
            ')
            ->first();

        return response()->json([
            'data' => $data->values(),
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
            'tab_counts' => [
                'all' => (int) ($tabCounts->total ?? 0),
                'pending_distribution' => (int) ($tabCounts->pending_distribution ?? 0),
                'live' => (int) ($tabCounts->live ?? 0),
                'with_inquiries' => (int) ($tabCounts->with_inquiries ?? 0),
                'booked' => (int) ($tabCounts->booked ?? 0),
                'expired_unrented' => (int) ($tabCounts->expired_unrented ?? 0),
            ],
            'filters' => [
                'tab' => $tab,
                'q' => $q,
                'sort' => $sort,
                'direction' => $dir,
            ],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $tenantId = $this->tenantContext->id();

        $listing = DB::table('listings as l')
            ->join('properties as p', 'p.id', '=', 'l.property_id')
            ->join('leads as o', 'o.id', '=', 'p.owner_id')
            ->leftJoin('deals as d', 'd.id', '=', 'l.deal_id')
            ->where('l.tenant_id', $tenantId)
            ->where('l.id', $id)
            ->whereNull('l.deleted_at')
            ->first([
                'l.*',
                'p.resort_name', 'p.resort_brand', 'p.location_city',
                'p.location_state', 'p.unit_number', 'p.bedrooms',
                'p.sleeps', 'p.view_type', 'p.ownership_type',
                'p.fixed_week_number', 'p.season',
                'p.ownership_verified', 'p.rental_allowed_by_resort',
                'o.id as owner_id', 'o.first_name as owner_first',
                'o.last_name as owner_last', 'o.email as owner_email',
                'o.phone as owner_phone', 'o.city as owner_city',
                'o.state as owner_state',
                'd.listing_fee', 'd.payment_status as deal_payment_status',
                'd.agreement_status', 'd.agreement_signed_at',
            ]);

        if ($listing === null) {
            return response()->json(['message' => 'Listing not found'], 404);
        }

        // Partner-site grid — every site we've pushed to.
        $partnerSites = DB::table('partner_site_listings as psl')
            ->join('partner_sites as ps', 'ps.id', '=', 'psl.partner_site_id')
            ->where('psl.tenant_id', $tenantId)
            ->where('psl.listing_id', $id)
            ->orderBy('ps.name')
            ->get([
                'psl.id', 'psl.status', 'psl.external_listing_id',
                'psl.external_url', 'psl.view_count', 'psl.inquiry_count',
                'psl.rejection_reason',
                'psl.pushed_at', 'psl.went_live_at', 'psl.last_synced_at',
                'ps.id as partner_site_id', 'ps.name as partner_name',
                'ps.slug as partner_slug', 'ps.our_cost_per_listing',
            ]);

        // Inquiries inbox — newest first.
        $inquiries = DB::table('rental_inquiries as ri')
            ->leftJoin('partner_sites as ps', 'ps.id', '=', 'ri.partner_site_id')
            ->leftJoin('users as u', 'u.id', '=', 'ri.handled_by')
            ->where('ri.tenant_id', $tenantId)
            ->where('ri.listing_id', $id)
            ->orderByDesc('ri.created_at')
            ->get([
                'ri.id', 'ri.renter_name', 'ri.renter_email', 'ri.renter_phone',
                'ri.requested_check_in', 'ri.requested_check_out',
                'ri.offered_amount', 'ri.message', 'ri.status',
                'ri.responded_at', 'ri.created_at',
                'ps.name as partner_name',
                'u.first_name as handler_first', 'u.last_name as handler_last',
            ]);

        // Booking — if a renter actually booked, surface the success row.
        $booking = DB::table('bookings')
            ->where('tenant_id', $tenantId)
            ->where('listing_id', $id)
            ->whereNull('deleted_at')
            ->orderByDesc('confirmed_at')
            ->first([
                'id', 'renter_name', 'renter_email', 'renter_phone',
                'check_in_date', 'check_out_date',
                'total_price', 'owner_payout', 'our_commission',
                'status', 'payment_status',
                'confirmation_number', 'confirmed_at',
                'owner_notified_at',
            ]);

        // Activity log — synthesized from authoritative timestamps
        // across the listing's lifecycle. This is a derived view; if
        // we want richer events later, add an audit trail table.
        $activity = collect();
        $activity->push([
            'kind' => 'listing_created',
            'label' => 'Listing created',
            'occurred_at' => $listing->created_at,
        ]);
        if ($listing->went_live_at) {
            $activity->push([
                'kind' => 'listing_live',
                'label' => 'Listing went live',
                'occurred_at' => $listing->went_live_at,
            ]);
        }
        foreach ($partnerSites as $ps) {
            if ($ps->pushed_at) {
                $activity->push([
                    'kind' => 'partner_pushed',
                    'label' => "Pushed to {$ps->partner_name}",
                    'occurred_at' => $ps->pushed_at,
                ]);
            }
            if ($ps->went_live_at) {
                $activity->push([
                    'kind' => 'partner_live',
                    'label' => "{$ps->partner_name} went live",
                    'occurred_at' => $ps->went_live_at,
                ]);
            }
            if ($ps->status === 'rejected' && $ps->rejection_reason) {
                $activity->push([
                    'kind' => 'partner_rejected',
                    'label' => "{$ps->partner_name} rejected: {$ps->rejection_reason}",
                    'occurred_at' => $ps->last_synced_at ?? $ps->pushed_at,
                ]);
            }
        }
        foreach ($inquiries as $iq) {
            $activity->push([
                'kind' => 'inquiry',
                'label' => "Inquiry from {$iq->renter_name}",
                'occurred_at' => $iq->created_at,
            ]);
        }
        if ($booking) {
            $activity->push([
                'kind' => 'booked',
                'label' => "Booked by {$booking->renter_name}",
                'occurred_at' => $booking->confirmed_at,
            ]);
            if ($booking->owner_notified_at) {
                $activity->push([
                    'kind' => 'owner_notified',
                    'label' => 'Owner notified of booking',
                    'occurred_at' => $booking->owner_notified_at,
                ]);
            }
        }

        $activity = $activity
            ->filter(fn ($e) => $e['occurred_at'] !== null)
            ->sortByDesc('occurred_at')
            ->values();

        // Reshape the listing for the response. Property and owner go
        // into nested objects; the deal carries the agreement summary.
        $payload = [
            'id' => $listing->id,
            'status' => $listing->status,
            'check_in_date' => $listing->check_in_date,
            'check_out_date' => $listing->check_out_date,
            'asking_price' => (float) $listing->asking_price,
            'reserve_price' => $listing->reserve_price !== null
                ? (float) $listing->reserve_price : null,
            'owner_payout' => (float) $listing->owner_payout,
            'our_commission_pct' => $listing->our_commission_pct !== null
                ? (float) $listing->our_commission_pct : null,
            'went_live_at' => $listing->went_live_at,
            'expires_at' => $listing->expires_at,
            'created_at' => $listing->created_at,
            'marketing_description' => $listing->marketing_description,
            'photos' => $listing->photos !== null ? json_decode($listing->photos, true) : null,
            'property' => [
                'id' => $listing->property_id,
                'resort_name' => $listing->resort_name,
                'resort_brand' => $listing->resort_brand,
                'location_city' => $listing->location_city,
                'location_state' => $listing->location_state,
                'unit_number' => $listing->unit_number,
                'bedrooms' => $listing->bedrooms !== null ? (int) $listing->bedrooms : null,
                'sleeps' => $listing->sleeps !== null ? (int) $listing->sleeps : null,
                'view_type' => $listing->view_type,
                'ownership_type' => $listing->ownership_type,
                'fixed_week_number' => $listing->fixed_week_number !== null
                    ? (int) $listing->fixed_week_number : null,
                'season' => $listing->season,
                'ownership_verified' => (bool) $listing->ownership_verified,
                'rental_allowed_by_resort' => (bool) $listing->rental_allowed_by_resort,
            ],
            'owner' => [
                'id' => $listing->owner_id,
                'full_name' => trim(($listing->owner_first ?? '').' '.($listing->owner_last ?? ''))
                    ?: '(unnamed owner)',
                'email' => $listing->owner_email,
                'phone' => $listing->owner_phone,
                'location' => trim(implode(', ', array_filter([$listing->owner_city, $listing->owner_state])))
                    ?: null,
            ],
            'agreement' => [
                'id' => $listing->deal_id,
                'listing_fee' => $listing->listing_fee !== null ? (float) $listing->listing_fee : null,
                'payment_status' => $listing->deal_payment_status,
                'agreement_status' => $listing->agreement_status,
                'agreement_signed_at' => $listing->agreement_signed_at,
            ],
            'partner_sites' => $partnerSites->map(fn ($ps) => [
                'id' => $ps->id,
                'partner_site_id' => $ps->partner_site_id,
                'name' => $ps->partner_name,
                'slug' => $ps->partner_slug,
                'our_cost_per_listing' => $ps->our_cost_per_listing !== null
                    ? (float) $ps->our_cost_per_listing : null,
                'status' => $ps->status,
                'external_listing_id' => $ps->external_listing_id,
                'external_url' => $ps->external_url,
                'view_count' => (int) $ps->view_count,
                'inquiry_count' => (int) $ps->inquiry_count,
                'rejection_reason' => $ps->rejection_reason,
                'pushed_at' => $ps->pushed_at,
                'went_live_at' => $ps->went_live_at,
                'last_synced_at' => $ps->last_synced_at,
            ])->values(),
            'inquiries' => $inquiries->map(fn ($iq) => [
                'id' => $iq->id,
                'renter_name' => $iq->renter_name,
                'renter_email' => $iq->renter_email,
                'renter_phone' => $iq->renter_phone,
                'requested_check_in' => $iq->requested_check_in,
                'requested_check_out' => $iq->requested_check_out,
                'offered_amount' => $iq->offered_amount !== null
                    ? (float) $iq->offered_amount : null,
                'message' => $iq->message,
                'status' => $iq->status,
                'responded_at' => $iq->responded_at,
                'created_at' => $iq->created_at,
                'partner_name' => $iq->partner_name,
                'handler_name' => trim(($iq->handler_first ?? '').' '.($iq->handler_last ?? '')) ?: null,
            ])->values(),
            'booking' => $booking ? [
                'id' => $booking->id,
                'renter_name' => $booking->renter_name,
                'renter_email' => $booking->renter_email,
                'renter_phone' => $booking->renter_phone,
                'check_in_date' => $booking->check_in_date,
                'check_out_date' => $booking->check_out_date,
                'total_price' => (float) $booking->total_price,
                'owner_payout' => $booking->owner_payout !== null
                    ? (float) $booking->owner_payout : null,
                'our_commission' => $booking->our_commission !== null
                    ? (float) $booking->our_commission : null,
                'status' => $booking->status,
                'payment_status' => $booking->payment_status,
                'confirmation_number' => $booking->confirmation_number,
                'confirmed_at' => $booking->confirmed_at,
                'owner_notified_at' => $booking->owner_notified_at,
            ] : null,
            'activity' => $activity,
        ];

        return response()->json($payload);
    }
}
