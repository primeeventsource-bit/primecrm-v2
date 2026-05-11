<?php

declare(strict_types=1);

namespace App\Modules\Listing\Http\Controllers;

use App\Core\Shared\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

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
    public function __construct(private readonly TenantContext $tenantContext) {}

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
}
