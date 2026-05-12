<?php

declare(strict_types=1);

namespace App\Modules\Listing\Http\Controllers;

use App\Core\Shared\TenantContext;
use App\Modules\Listing\Application\Services\ListingCsvParser;
use App\Modules\Listing\Application\Services\ListingImporter;
use App\Modules\Listing\Domain\Enums\ListingStatus;
use App\Modules\Listing\Domain\Models\Listing;
use App\Modules\Listing\Domain\Models\Property;
use App\Modules\Listing\Http\Requests\ListingBulkImportRequest;
use App\Modules\Listing\Http\Requests\ListingBulkPreviewRequest;
use App\Modules\Listing\Http\Requests\StoreListingRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ListingCsvParser $parser,
        private readonly ListingImporter $importer,
    ) {}

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
            // Model casts `photos` to array already; the previous
            // json_decode was double-decoding and returning null in
            // production where the cast had already run. Pass through.
            'photos' => is_array($listing->photos) ? array_values($listing->photos) : [],
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

    /**
     * Create a listing against an existing property.
     *
     * The property must belong to the tenant; if it isn't yet
     * verified or the resort doesn't allow rental we still let the
     * row through (operator override) but the response surfaces a
     * warning so the UI can flag it.
     *
     * Owner payout defaulting:
     *   - If owner_payout is provided, use it verbatim.
     *   - Else if our_commission_pct is provided, compute it from
     *     asking_price * (1 - pct/100).
     *   - Else leave both null and let downstream booking-time math
     *     figure the split.
     */
    public function store(StoreListingRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $tenantId = $this->tenantContext->id();

        $property = Property::query()
            ->where('id', $validated['property_id'])
            ->first();

        if ($property === null) {
            return response()->json([
                'message' => 'Property not found in this workspace.',
                'errors' => ['property_id' => ['Property not found in this workspace.']],
            ], 422);
        }

        // Compute owner payout if we got a commission % but no payout.
        $askingPrice = (float) $validated['asking_price'];
        $ownerPayout = $validated['owner_payout'] ?? null;
        $commissionPct = $validated['our_commission_pct'] ?? null;

        if ($ownerPayout === null && $commissionPct !== null) {
            $ownerPayout = round($askingPrice * (1 - ((float) $commissionPct) / 100), 2);
        }

        // Default to pending_distribution; `go_live=true` short-circuits
        // straight to live (use case: operator already pushed manually).
        $status = $validated['status']
            ?? (($validated['go_live'] ?? false)
                ? ListingStatus::Live->value
                : ListingStatus::PendingDistribution->value);

        $listing = Listing::query()->create([
            'tenant_id' => $tenantId,
            'property_id' => $property->id,
            'deal_id' => $validated['deal_id'] ?? null,
            'check_in_date' => $validated['check_in_date'],
            'check_out_date' => $validated['check_out_date'],
            'asking_price' => $askingPrice,
            'reserve_price' => $validated['reserve_price'] ?? null,
            'owner_payout' => $ownerPayout,
            'our_commission_pct' => $commissionPct,
            'status' => $status,
            'went_live_at' => $status === ListingStatus::Live->value
                ? Carbon::now() : null,
            'marketing_description' => $validated['marketing_description'] ?? null,
        ]);

        // Warnings the UI may want to surface — not validation errors,
        // just operator-facing context after the row already saved.
        $warnings = [];
        if (! $property->ownership_verified) {
            $warnings[] = 'Property ownership is not yet verified.';
        }
        if (! $property->rental_allowed_by_resort) {
            $warnings[] = 'Resort has not confirmed rental is allowed for this unit.';
        }

        return response()->json([
            'message' => 'Listing created.',
            'data' => [
                'id' => $listing->id,
                'status' => $listing->status?->value,
                'check_in_date' => $listing->check_in_date?->toDateString(),
                'check_out_date' => $listing->check_out_date?->toDateString(),
                'asking_price' => (float) $listing->asking_price,
                'owner_payout' => $listing->owner_payout !== null
                    ? (float) $listing->owner_payout : null,
                'property' => [
                    'id' => $property->id,
                    'resort_name' => $property->resort_name,
                    'location_label' => $property->locationLabel(),
                ],
            ],
            'warnings' => $warnings,
        ], 201);
    }

    /**
     * Lightweight property list for the "Create listing" picker.
     *
     * Returns up to 50 rows, optionally filtered by a free-text query
     * across owner name / resort name. Only tenant-scoped properties
     * appear. Used by the modal — not a general property index.
     */
    public function propertiesPicker(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'only_rentable' => ['nullable', 'boolean'],
        ]);

        $tenantId = $this->tenantContext->id();
        $q = $request->string('q')->value();
        $onlyRentable = $request->boolean('only_rentable');

        $query = DB::table('properties as p')
            ->join('leads as o', 'o.id', '=', 'p.owner_id')
            ->where('p.tenant_id', $tenantId)
            ->whereNull('p.deleted_at');

        if ($onlyRentable) {
            $query->where('p.ownership_verified', true)
                ->where('p.rental_allowed_by_resort', true);
        }

        if ($q !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';
            $query->where(function ($qq) use ($like): void {
                $qq->where('o.first_name', 'like', $like)
                    ->orWhere('o.last_name', 'like', $like)
                    ->orWhere('p.resort_name', 'like', $like)
                    ->orWhere('p.location_city', 'like', $like);
            });
        }

        $rows = $query
            ->orderBy('p.resort_name')
            ->limit(50)
            ->get([
                'p.id', 'p.resort_name', 'p.resort_brand',
                'p.location_city', 'p.location_state',
                'p.unit_number', 'p.bedrooms', 'p.sleeps',
                'p.ownership_verified', 'p.rental_allowed_by_resort',
                'o.id as owner_id', 'o.first_name', 'o.last_name',
            ]);

        return response()->json([
            'data' => $rows->map(fn ($r) => [
                'id' => $r->id,
                'resort_name' => $r->resort_name,
                'resort_brand' => $r->resort_brand,
                'location_city' => $r->location_city,
                'location_state' => $r->location_state,
                'unit_number' => $r->unit_number,
                'bedrooms' => $r->bedrooms !== null ? (int) $r->bedrooms : null,
                'sleeps' => $r->sleeps !== null ? (int) $r->sleeps : null,
                'ownership_verified' => (bool) $r->ownership_verified,
                'rental_allowed_by_resort' => (bool) $r->rental_allowed_by_resort,
                'owner' => [
                    'id' => $r->owner_id,
                    'name' => trim(($r->first_name ?? '').' '.($r->last_name ?? ''))
                        ?: '(unnamed owner)',
                ],
            ])->values(),
        ]);
    }

    /**
     * Upload a photo to a listing's gallery.
     *
     *   POST /api/listings/{id}/photos
     *   multipart/form-data with `photo` file part
     *
     * Stored on the disk named by `filesystems.listing_photos_disk`
     * (default 'public' for local dev; 'listing_photos' on Cloud,
     * which is the R2 bucket). The disk's `url()` method produces the
     * right URL shape for either backend — we never hardcode /storage.
     *
     * The `photos` JSON column on the listing holds an ordered list of
     * URLs; we append, never replace. Cap at MAX_PHOTOS so a runaway
     * upload script can't fill the bucket.
     */
    public function uploadPhoto(Request $request, string $id): JsonResponse
    {
        $request->validate([
            // image rule catches MIME tricks the extension check would miss.
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $listing = Listing::query()->find($id);
        if ($listing === null) {
            return response()->json(['message' => 'Listing not found'], 404);
        }

        $existing = is_array($listing->photos) ? $listing->photos : [];
        if (count($existing) >= self::MAX_PHOTOS) {
            return response()->json([
                'message' => 'Photo limit reached.',
                'errors' => ['photo' => ['This listing already has '.self::MAX_PHOTOS.' photos. Delete one first.']],
            ], 422);
        }

        // Random filename — prevents the partner-site renderer from
        // caching a previous owner's photo if the operator deletes +
        // re-uploads with the same source filename.
        $file = $request->file('photo');
        $ext = $file->getClientOriginalExtension() ?: $file->extension();
        $filename = Str::uuid()->toString().'.'.strtolower($ext);
        $path = "listings/{$listing->id}/{$filename}";

        $disk = Storage::disk(config('filesystems.listing_photos_disk', 'public'));
        $disk->putFileAs(
            "listings/{$listing->id}",
            $file,
            $filename,
            ['visibility' => 'public'],
        );

        $url = $disk->url($path);

        $existing[] = $url;
        $listing->photos = $existing;
        $listing->save();

        return response()->json([
            'url' => $url,
            'photos' => $existing,
        ], 201);
    }

    /**
     * Delete a photo from a listing's gallery.
     *
     *   DELETE /api/listings/{id}/photos
     *   { "url": "https://.../storage/listings/abc/def.jpg" }
     *
     * Removes the URL from the photos array and deletes the underlying
     * file. The file delete is best-effort — if the file is already
     * gone (cleaned by another process), we still want to scrub the
     * URL from the array.
     */
    public function deletePhoto(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'url' => ['required', 'string', 'max:2000'],
        ]);

        $listing = Listing::query()->find($id);
        if ($listing === null) {
            return response()->json(['message' => 'Listing not found'], 404);
        }

        $existing = is_array($listing->photos) ? $listing->photos : [];
        $target = $request->string('url')->value();

        $remaining = array_values(array_filter($existing, fn ($u) => $u !== $target));

        if (count($remaining) === count($existing)) {
            // URL not in the array — return the current state so the
            // client UI re-syncs but don't error (race-with-another-tab
            // delete shouldn't show a red banner).
            return response()->json(['photos' => $remaining]);
        }

        // Map URL back to disk path. URLs differ by backend (public
        // disk → APP_URL/storage/...; R2 → bucket-public-url/...) but
        // we always anchor on the "listings/{id}/" segment to extract
        // the disk-relative key.
        $relative = $this->urlToRelativePath($target, $listing->id);
        if ($relative !== null) {
            try {
                Storage::disk(config('filesystems.listing_photos_disk', 'public'))
                    ->delete($relative);
            } catch (\Throwable) {
                // File may already be gone; ignore.
            }
        }

        $listing->photos = $remaining;
        $listing->save();

        return response()->json(['photos' => $remaining]);
    }

    /**
     * Best-effort URL → disk-relative-path conversion. Accepts both
     * full URLs (https://app.example.com/storage/listings/{id}/x.jpg)
     * and bare relative paths (listings/{id}/x.jpg). We constrain the
     * deletable path to listings/{listingId}/ so a malformed URL can
     * never delete a file outside the listing's own folder.
     */
    private function urlToRelativePath(string $url, string $listingId): ?string
    {
        $needle = "listings/{$listingId}/";
        $idx = strpos($url, $needle);
        if ($idx === false) {
            return null;
        }
        $relative = substr($url, $idx);
        // No '..' or other traversal funny business — the prefix
        // anchor above already constrains us, this is paranoia.
        if (str_contains($relative, '..') || str_contains($relative, '//')) {
            return null;
        }
        return $relative;
    }

    /**
     * Hard cap on photos per listing. Partner sites typically reject
     * listings with > 12 photos anyway; this is a sanity ceiling, not
     * a brand-specific limit.
     */
    private const MAX_PHOTOS = 12;

    /* ----------------------------------------------------------------
     | Bulk import (CSV / Excel)
     | ----------------------------------------------------------------
     | Two-step flow: preview → import. The preview parses the upload,
     | flags new owners + new properties for operator approval, and
     | stashes the parsed payload in cache. The import endpoint
     | commits with the approval flags inside one DB transaction.
     */

    public function template(): Response
    {
        $headers = [
            'owner_email', 'owner_phone', 'owner_first_name', 'owner_last_name',
            'resort_name', 'resort_brand', 'city', 'state', 'country',
            'unit_number', 'bedrooms', 'sleeps', 'ownership_type',
            'check_in_date', 'check_out_date',
            'asking_price', 'reserve_price', 'our_commission_pct',
            'marketing_description', 'go_live',
        ];
        $examples = [
            ['jane.doe@example.com', '+15551234567', 'Jane', 'Doe',
                'Marriott Newport Coast Villas', 'Marriott', 'Newport Beach', 'CA', 'US',
                '4321', '2', '6', 'deeded',
                '2026-07-04', '2026-07-11',
                '2950.00', '2500.00', '15',
                'Ocean view, walk to beach.', 'no'],
            ['bob@example.com', '', 'Bob', 'Smith',
                'Westgate Park City Resort', 'Westgate', 'Park City', 'UT', 'US',
                '', '1', '4', 'points',
                '2026-12-26', '2027-01-02',
                '3800.00', '', '15',
                'Holiday week, ski-in/ski-out.', 'yes'],
        ];

        $lines = [implode(',', $headers)];
        foreach ($examples as $row) {
            $lines[] = implode(',', array_map(fn ($v) => $this->csvField((string) $v), $row));
        }

        return response(implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="listings-template.csv"',
        ]);
    }

    public function bulkPreview(ListingBulkPreviewRequest $request): JsonResponse
    {
        $tenantId = $this->tenantContext->id();
        $parsed = $this->parser->parse($request->file('file'));
        $resolved = $this->importer->resolveAgainstTenant($tenantId, $parsed);

        $token = (string) Str::uuid();
        Cache::put(
            $this->previewCacheKey($tenantId, $token),
            $resolved,
            now()->addMinutes(30),
        );

        return response()->json([
            'preview_token' => $token,
            'summary' => $resolved['summary'],
            'rows' => $resolved['rows'],
            'new_owners' => $resolved['new_owners'],
            'new_properties' => $resolved['new_properties'],
        ]);
    }

    public function bulkImport(ListingBulkImportRequest $request): JsonResponse
    {
        $tenantId = $this->tenantContext->id();
        $token = (string) $request->string('preview_token');

        $payload = Cache::get($this->previewCacheKey($tenantId, $token));
        if ($payload === null) {
            return response()->json([
                'message' => 'Preview has expired. Please re-upload the file.',
                'errors' => ['preview_token' => ['Preview expired.']],
            ], 410);
        }

        $approvedOwners = (array) $request->input('approved_owner_keys', []);
        $approvedProperties = (array) $request->input('approved_property_keys', []);

        $result = $this->importer->commit(
            $tenantId,
            $payload,
            $approvedOwners,
            $approvedProperties,
        );

        Cache::forget($this->previewCacheKey($tenantId, $token));

        return response()->json([
            'message' => sprintf(
                'Imported %d listing%s (%d owners created, %d properties created, %d skipped).',
                $result['listings_created'],
                $result['listings_created'] === 1 ? '' : 's',
                $result['owners_created'],
                $result['properties_created'],
                $result['rows_skipped'],
            ),
            'data' => $result,
        ], 201);
    }

    private function previewCacheKey(string $tenantId, string $token): string
    {
        return "listing:bulk-preview:{$tenantId}:{$token}";
    }

    private function csvField(string $value): string
    {
        if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }
}
