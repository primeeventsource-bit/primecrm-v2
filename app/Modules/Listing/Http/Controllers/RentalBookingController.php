<?php

declare(strict_types=1);

namespace App\Modules\Listing\Http\Controllers;

use App\Core\Shared\TenantContext;
use App\Modules\Booking\Domain\Models\Booking;
use App\Modules\Listing\Application\Services\BookingNotifier;
use App\Modules\Listing\Application\Services\RentalBookingCsvParser;
use App\Modules\Listing\Application\Services\RentalBookingImporter;
use App\Modules\Listing\Domain\Enums\ListingStatus;
use App\Modules\Listing\Domain\Models\Listing;
use App\Modules\Listing\Http\Requests\RentalBookingBulkImportRequest;
use App\Modules\Listing\Http\Requests\RentalBookingBulkPreviewRequest;
use App\Modules\Listing\Http\Requests\StoreRentalBookingRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Renter-side bookings ledger.
 *
 *   GET /api/rental-bookings    paginated, filterable
 *
 * Per §4.6: "Confirmed rentals across all listings. The success
 * metric of the business." Distinct from the legacy /api/bookings
 * (which is the agent-sold-a-vacation-week model) — we filter to
 * rows with listing_id NOT NULL so only renter bookings appear.
 *
 * Filters: range (this_week / this_month / 90d / all), state,
 * resort brand, closer (the agent on the underlying deal).
 *
 * Each row joins the listing + property + owner so the table can
 * render rich rows without N+1 lookups.
 */
final class RentalBookingController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly BookingNotifier $notifier,
        private readonly RentalBookingCsvParser $parser,
        private readonly RentalBookingImporter $importer,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'range' => ['nullable', 'in:this_week,this_month,last_30,last_90,all'],
            'state' => ['nullable', 'string', 'size:2'],
            'brand' => ['nullable', 'string', 'max:100'],
            'closer_id' => ['nullable', 'uuid'],
            'status' => ['nullable', 'string'],
            'q' => ['nullable', 'string', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $tenantId = $this->tenantContext->id();
        $range = $request->string('range', 'this_month')->value();
        $state = $request->string('state')->value();
        $brand = $request->string('brand')->value();
        $closerId = $request->string('closer_id')->value();
        $statusFilter = $request->string('status')->value();
        $q = $request->string('q')->value();
        $page = (int) $request->integer('page', 1);
        $perPage = (int) $request->integer('per_page', 25);

        // Base query — only renter bookings (listing_id present),
        // joined to listing/property/owner/closer for the row shape.
        $base = DB::table('bookings as b')
            ->join('listings as l', 'l.id', '=', 'b.listing_id')
            ->join('properties as p', 'p.id', '=', 'l.property_id')
            ->join('leads as o', 'o.id', '=', 'p.owner_id')
            ->leftJoin('deals as d', 'd.id', '=', 'l.deal_id')
            ->leftJoin('users as c', 'c.id', '=', 'd.agent_id')
            ->where('b.tenant_id', $tenantId)
            ->whereNotNull('b.listing_id')
            ->whereNull('b.deleted_at');

        // Range — by booking confirmed_at when present, else
        // created_at. Filter to current/this-month/etc.
        [$rangeStart, $rangeEnd] = $this->rangeBounds($range);
        if ($rangeStart !== null) {
            $base->where(function ($qq) use ($rangeStart, $rangeEnd): void {
                $qq->whereBetween('b.confirmed_at', [$rangeStart, $rangeEnd])
                    ->orWhere(function ($q2) use ($rangeStart, $rangeEnd): void {
                        $q2->whereNull('b.confirmed_at')
                            ->whereBetween('b.created_at', [$rangeStart, $rangeEnd]);
                    });
            });
        }

        if ($state !== '') {
            $base->where('p.location_state', strtoupper($state));
        }
        if ($brand !== '') {
            $base->where('p.resort_brand', $brand);
        }
        if ($closerId !== '') {
            $base->where('d.agent_id', $closerId);
        }
        if ($statusFilter !== '') {
            $base->where('b.status', $statusFilter);
        }
        if ($q !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';
            $base->where(function ($qq) use ($like): void {
                $qq->where('b.renter_name', 'like', $like)
                    ->orWhere('b.confirmation_number', 'like', $like)
                    ->orWhere('o.first_name', 'like', $like)
                    ->orWhere('o.last_name', 'like', $like)
                    ->orWhere('p.resort_name', 'like', $like);
            });
        }

        $total = (clone $base)->count();

        $rows = $base
            ->orderByDesc('b.confirmed_at')
            ->orderByDesc('b.created_at')
            ->limit($perPage)
            ->offset(($page - 1) * $perPage)
            ->get([
                'b.id', 'b.confirmation_number', 'b.status', 'b.payment_status',
                'b.renter_name', 'b.renter_email',
                'b.check_in_date', 'b.check_out_date',
                'b.total_price', 'b.owner_payout', 'b.our_commission',
                'b.confirmed_at', 'b.owner_notified_at',
                'b.listing_id', 'b.created_at',
                'p.resort_name', 'p.resort_brand',
                'p.location_city', 'p.location_state',
                'o.id as owner_id', 'o.first_name as owner_first',
                'o.last_name as owner_last',
                'c.id as closer_id', 'c.first_name as closer_first',
                'c.last_name as closer_last',
            ]);

        // Aggregate strip for the current filter set — what's our
        // total revenue and commission across the visible window?
        $totals = (clone $base)
            ->selectRaw('
                COUNT(*) AS bookings_count,
                COALESCE(SUM(b.total_price), 0) AS total_rental_value,
                COALESCE(SUM(b.owner_payout), 0) AS total_owner_payout,
                COALESCE(SUM(b.our_commission), 0) AS total_commission,
                SUM(CASE WHEN b.owner_notified_at IS NOT NULL THEN 1 ELSE 0 END) AS owners_notified
            ')
            ->first();

        $data = $rows->map(fn ($r) => [
            'id' => $r->id,
            'confirmation_number' => $r->confirmation_number,
            'status' => $r->status,
            'payment_status' => $r->payment_status,
            'renter_name' => $r->renter_name,
            'renter_email' => $r->renter_email,
            'check_in_date' => $r->check_in_date,
            'check_out_date' => $r->check_out_date,
            'total_price' => (float) $r->total_price,
            'owner_payout' => $r->owner_payout !== null ? (float) $r->owner_payout : null,
            'our_commission' => $r->our_commission !== null ? (float) $r->our_commission : null,
            'confirmed_at' => $r->confirmed_at,
            'owner_notified_at' => $r->owner_notified_at,
            'listing' => [
                'id' => $r->listing_id,
                'resort_name' => $r->resort_name,
                'resort_brand' => $r->resort_brand,
                'location_city' => $r->location_city,
                'location_state' => $r->location_state,
            ],
            'owner' => [
                'id' => $r->owner_id,
                'name' => trim(($r->owner_first ?? '').' '.($r->owner_last ?? ''))
                    ?: '(unnamed owner)',
            ],
            'closer' => $r->closer_id ? [
                'id' => $r->closer_id,
                'name' => trim(($r->closer_first ?? '').' '.($r->closer_last ?? ''))
                    ?: '(unknown)',
            ] : null,
        ]);

        return response()->json([
            'data' => $data->values(),
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
            'totals' => [
                'bookings_count' => (int) ($totals->bookings_count ?? 0),
                'total_rental_value' => (float) ($totals->total_rental_value ?? 0),
                'total_owner_payout' => (float) ($totals->total_owner_payout ?? 0),
                'total_commission' => (float) ($totals->total_commission ?? 0),
                'owners_notified' => (int) ($totals->owners_notified ?? 0),
            ],
            'filters' => [
                'range' => $range,
                'state' => $state,
                'brand' => $brand,
                'closer_id' => $closerId,
                'status' => $statusFilter,
                'q' => $q,
            ],
        ]);
    }

    /**
     * Create a rental booking manually against an existing listing.
     *
     * Mirrors RentalInquiryController::book() minus the inquiry side.
     * Used for off-platform/phone bookings, back-fills, or any rental
     * where we never had an inquiry row to convert. Flips the listing
     * to Booked and (unless caller opts out) notifies the owner.
     *
     * Transactional: booking insert + listing status flip happen
     * atomically. Owner notification fires after commit so a notify
     * failure doesn't roll back the rental we just confirmed.
     */
    public function store(StoreRentalBookingRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $tenantId = $this->tenantContext->id();

        $listing = Listing::query()->find($validated['listing_id']);
        if ($listing === null || $listing->tenant_id !== $tenantId) {
            return response()->json([
                'message' => 'Listing not found in this workspace.',
                'errors' => ['listing_id' => ['Listing not found in this workspace.']],
            ], 422);
        }

        // Dates default to the listing window.
        $checkIn = isset($validated['check_in_date'])
            ? Carbon::parse($validated['check_in_date'])
            : $listing->check_in_date;
        $checkOut = isset($validated['check_out_date'])
            ? Carbon::parse($validated['check_out_date'])
            : $listing->check_out_date;

        $total = (float) $validated['total_price'];

        // Owner payout: explicit wins, else derive from commission %,
        // else fall back to the listing's stored commission % or 15%.
        $ownerPayout = $validated['owner_payout'] ?? null;
        $commissionPct = $validated['commission_pct']
            ?? ($listing->our_commission_pct !== null
                ? (float) $listing->our_commission_pct
                : null);

        if ($ownerPayout === null) {
            $pct = $commissionPct !== null ? (float) $commissionPct : 15.0;
            $ourCommission = round($total * ($pct / 100), 2);
            $ownerPayout = round($total - $ourCommission, 2);
        } else {
            $ownerPayout = (float) $ownerPayout;
            $ourCommission = round($total - $ownerPayout, 2);
        }

        $payload = [
            'tenant_id' => $tenantId,
            'lead_id' => $listing->property?->owner_id,
            'deal_id' => $listing->deal_id,
            'agent_id' => $request->user()?->id,
            'listing_id' => $listing->id,
            'inquiry_id' => null,
            'renter_name' => $validated['renter_name'],
            'renter_email' => $validated['renter_email'] ?? null,
            'renter_phone' => $validated['renter_phone'] ?? null,
            'check_in_date' => $checkIn,
            'check_out_date' => $checkOut,
            'total_price' => $total,
            'paid_amount' => 0,
            'currency' => 'USD',
            'owner_payout' => $ownerPayout,
            'our_commission' => $ourCommission,
            'status' => 'confirmed',
            'payment_status' => $validated['payment_status'] ?? 'pending',
            'confirmation_number' => 'PV-'.strtoupper(Str::random(8)),
            'confirmed_at' => Carbon::now(),
        ];

        $booking = DB::transaction(function () use ($payload, $listing): Booking {
            $b = Booking::query()->create($payload);
            $listing->forceFill([
                'status' => ListingStatus::Booked->value,
            ])->save();

            return $b->refresh();
        });

        // Owner notification — opt-out via notify_owner=false for the
        // rare case where the operator has already informed the owner
        // out-of-band and a duplicate note would be noisy.
        $shouldNotify = ! array_key_exists('notify_owner', $validated)
            || (bool) $validated['notify_owner'];
        if ($shouldNotify) {
            $this->notifier->notifyOwnerOfBooking($booking, $listing, $request->user());
            $booking = $booking->refresh();
        }

        return response()->json([
            'message' => 'Booking confirmed.',
            'data' => [
                'id' => $booking->id,
                'confirmation_number' => $booking->confirmation_number,
                'total_price' => (float) $booking->total_price,
                'owner_payout' => (float) $booking->owner_payout,
                'our_commission' => (float) $booking->our_commission,
                'payment_status' => $booking->payment_status,
                'check_in_date' => $booking->check_in_date?->toDateString(),
                'check_out_date' => $booking->check_out_date?->toDateString(),
                'owner_notified_at' => $booking->owner_notified_at?->toIso8601String(),
                'listing' => [
                    'id' => $listing->id,
                    'status' => $listing->refresh()->status?->value,
                ],
            ],
        ], 201);
    }

    /**
     * Lightweight listing list for the "Create booking" picker.
     *
     * Returns up to 50 live/inquiry/pending-booking listings, with
     * resort + owner names and the listing window pre-formatted so
     * the modal's defaults render without an extra round-trip.
     */
    public function listingsPicker(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'include_booked' => ['nullable', 'boolean'],
        ]);

        $tenantId = $this->tenantContext->id();
        $q = $request->string('q')->value();
        $includeBooked = $request->boolean('include_booked');

        $statuses = [
            ListingStatus::Live->value,
            ListingStatus::InquiryReceived->value,
            ListingStatus::PendingBooking->value,
        ];
        if ($includeBooked) {
            $statuses[] = ListingStatus::Booked->value;
        }

        $query = DB::table('listings as l')
            ->join('properties as p', 'p.id', '=', 'l.property_id')
            ->join('leads as o', 'o.id', '=', 'p.owner_id')
            ->where('l.tenant_id', $tenantId)
            ->whereNull('l.deleted_at')
            ->whereIn('l.status', $statuses);

        if ($q !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';
            $query->where(function ($qq) use ($like): void {
                $qq->where('o.first_name', 'like', $like)
                    ->orWhere('o.last_name', 'like', $like)
                    ->orWhere('p.resort_name', 'like', $like);
            });
        }

        $rows = $query
            ->orderBy('l.check_in_date')
            ->limit(50)
            ->get([
                'l.id', 'l.status',
                'l.check_in_date', 'l.check_out_date',
                'l.asking_price', 'l.owner_payout', 'l.our_commission_pct',
                'p.id as property_id', 'p.resort_name', 'p.resort_brand',
                'p.location_city', 'p.location_state',
                'o.id as owner_id', 'o.first_name', 'o.last_name',
            ]);

        return response()->json([
            'data' => $rows->map(fn ($r) => [
                'id' => $r->id,
                'status' => $r->status,
                'check_in_date' => $r->check_in_date,
                'check_out_date' => $r->check_out_date,
                'asking_price' => (float) $r->asking_price,
                'owner_payout' => $r->owner_payout !== null
                    ? (float) $r->owner_payout : null,
                'our_commission_pct' => $r->our_commission_pct !== null
                    ? (float) $r->our_commission_pct : null,
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
            ])->values(),
        ]);
    }

    /**
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null}
     */
    private function rangeBounds(string $range): array
    {
        $now = CarbonImmutable::now();

        return match ($range) {
            'this_week' => [$now->startOfWeek(), $now->endOfWeek()],
            'this_month' => [$now->startOfMonth(), $now->endOfMonth()],
            'last_30' => [$now->subDays(30), $now],
            'last_90' => [$now->subDays(90), $now],
            'all' => [null, null],
            default => [$now->startOfMonth(), $now->endOfMonth()],
        };
    }

    /* ----------------------------------------------------------------
     | Bulk import (CSV / Excel)
     | ----------------------------------------------------------------
     | Strict: rows must match an existing listing to import. There's
     | no auto-create — making a listing from a booking row would be
     | too lossy (no asking price, no commission %, no marketing
     | description). Operator can use the Listings bulk import to
     | create the listings first, then re-upload the bookings file.
     */

    public function template(): Response
    {
        $headers = [
            'listing_id',
            'owner_email', 'owner_phone',
            'resort_name', 'city', 'state',
            'listing_check_in_date',
            'renter_name', 'renter_email', 'renter_phone',
            'check_in_date', 'check_out_date',
            'total_price', 'commission_pct', 'payment_status',
        ];
        $examples = [
            // Direct listing_id path — fastest when you have it.
            ['00000000-0000-0000-0000-000000000000',
                '', '', '', '', '', '',
                'Alice Renter', 'alice@example.com', '+15555550199',
                '', '', '2450.00', '15', 'paid_in_full'],
            // Owner+resort+date path — typical for partner exports.
            ['',
                'jane.doe@example.com', '', 'Marriott Newport Coast Villas',
                'Newport Beach', 'CA', '2026-07-04',
                'Bob Renter', 'bob@example.com', '',
                '2026-07-04', '2026-07-11',
                '2950.00', '', 'deposit_paid'],
        ];

        $lines = [implode(',', $headers)];
        foreach ($examples as $row) {
            $lines[] = implode(',', array_map(fn ($v) => $this->csvField((string) $v), $row));
        }

        return response(implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="bookings-template.csv"',
        ]);
    }

    public function bulkPreview(RentalBookingBulkPreviewRequest $request): JsonResponse
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
        ]);
    }

    public function bulkImport(RentalBookingBulkImportRequest $request): JsonResponse
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

        // Default: don't notify on bulk import. Historical-back-fill
        // is the common case and the owners already know about those
        // rentals. Operator can opt in explicitly.
        $notify = $request->boolean('notify_owners', false);

        $result = $this->importer->commit(
            $tenantId,
            $payload,
            $notify,
            $request->user(),
        );

        Cache::forget($this->previewCacheKey($tenantId, $token));

        return response()->json([
            'message' => sprintf(
                'Imported %d booking%s (%d skipped, %d owners notified).',
                $result['bookings_created'],
                $result['bookings_created'] === 1 ? '' : 's',
                $result['rows_skipped'],
                $result['owners_notified'],
            ),
            'data' => $result,
        ], 201);
    }

    private function previewCacheKey(string $tenantId, string $token): string
    {
        return "rental-booking:bulk-preview:{$tenantId}:{$token}";
    }

    private function csvField(string $value): string
    {
        if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }
}
