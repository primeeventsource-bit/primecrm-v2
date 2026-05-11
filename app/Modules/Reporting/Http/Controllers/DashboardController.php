<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers;

use App\Core\Shared\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * Aggregated metrics for the main Dashboard.
 *
 *   GET /api/dashboard/summary?period=weekly|monthly|daily&offset=0
 *
 * One round-trip; one transaction read; everything the dashboard needs:
 *   - pipeline summary (transfers, deals closed, sent to verification, charged)
 *   - performance by location (US closers, Panama closers, US fronters, Panama fronters)
 *   - tasks (placeholder until the Task module ships)
 *
 * Numbers are derived from existing tables — no denormalized "metrics"
 * cache yet. Acceptable up to a few thousand deals/week per tenant; if
 * the dashboard ever feels slow, the right move is a snapshot table
 * populated by the same listeners that update Customer.lifetime_value.
 */
final class DashboardController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function summary(Request $request): JsonResponse
    {
        $request->validate([
            'period' => ['nullable', 'in:daily,weekly,monthly'],
            'offset' => ['nullable', 'integer'],
        ]);

        $period = $request->string('period', 'weekly')->value();
        $offset = (int) $request->integer('offset', 0);

        [$start, $end, $label] = $this->resolveWindow($period, $offset);

        $tenantId = $this->tenantContext->id();

        // MySQL-compatible: SUM(CASE WHEN ... THEN 1 ELSE 0 END) instead
        // of the cleaner Postgres COUNT(*) FILTER (WHERE ...).
        $deals = DB::table('deals')
            ->where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('
                COUNT(*) AS total_deals,
                SUM(CASE WHEN stage = ? THEN 1 ELSE 0 END) AS won,
                SUM(CASE WHEN stage = ? THEN 1 ELSE 0 END) AS lost,
                SUM(CASE WHEN stage IN (?, ?) THEN 1 ELSE 0 END) AS in_progress,
                COALESCE(SUM(CASE WHEN stage = ? THEN payable_amount ELSE 0 END), 0) AS won_revenue
            ', ['closed_won', 'closed_lost', 'qualified', 'pitch_presented', 'closed_won'])
            ->first();

        // "Sent to verification" maps to deals in negotiating stage in our schema.
        $sentToVerification = DB::table('deals')
            ->where('tenant_id', $tenantId)
            ->whereBetween('updated_at', [$start, $end])
            ->where('stage', 'negotiating')
            ->count();

        // Transfers — outbound calls that resulted in a connect, broken
        // out by lead status changing from new/contacted to qualified+.
        // Approximation: count of distinct leads the agent moved past
        // qualified in the window.
        $transfers = DB::table('deal_stage_transitions')
            ->where('tenant_id', $tenantId)
            ->whereBetween('occurred_at', [$start, $end])
            ->where('to_stage', 'qualified')
            ->count();

        // Charged / Green — payments that actually cleared.
        $charged = DB::table('payments')
            ->where('tenant_id', $tenantId)
            ->whereBetween('cleared_at', [$start, $end])
            ->where('status', 'succeeded')
            ->where('type', 'charge')
            ->selectRaw('COUNT(*) AS cnt, COALESCE(SUM(amount), 0) AS amt')
            ->first();

        $totalDeals = (int) ($deals->total_deals ?? 0);
        $won = (int) ($deals->won ?? 0);
        $conversionRate = $totalDeals > 0 ? $won / $totalDeals : 0.0;
        $chargedRate = $won > 0 ? ((int) $charged->cnt) / $won : 0.0;

        $totalLeads = DB::table('leads')
            ->where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$start, $end])
            ->count();

        // Per-group breakdown (US closer, US fronter, Panama closer, Panama fronter)
        $groups = DB::table('users')
            ->leftJoin('deals', function ($j) use ($start, $end): void {
                $j->on('users.id', '=', 'deals.agent_id')
                    ->whereBetween('deals.created_at', [$start, $end])
                    ->where('deals.stage', '=', 'closed_won');
            })
            ->leftJoin('leads', function ($j) use ($start, $end): void {
                $j->on('users.id', '=', 'leads.assigned_agent_id')
                    ->whereBetween('leads.created_at', [$start, $end]);
            })
            ->whereIn('users.role', ['closer', 'fronter'])
            ->selectRaw("
                CASE WHEN users.is_panama_based THEN 'Panama' ELSE 'US' END AS location,
                users.role AS role,
                COUNT(DISTINCT users.id) AS agents,
                COUNT(DISTINCT leads.id) AS leads,
                COUNT(DISTINCT deals.id) AS deals,
                COALESCE(SUM(DISTINCT deals.payable_amount), 0) AS revenue
            ")
            ->groupBy('location', 'users.role')
            ->orderByRaw("CASE users.role WHEN 'fronter' THEN 0 ELSE 1 END, location")
            ->get();

        return response()->json([
            'period' => [
                'kind' => $period,
                'label' => $label,
                'start' => $start->toIso8601String(),
                'end' => $end->toIso8601String(),
                'offset' => $offset,
            ],
            'pipeline' => [
                'total_transfers' => $transfers,
                'deals_closed' => $won,
                'deals_lost' => (int) ($deals->lost ?? 0),
                'deals_in_progress' => (int) ($deals->in_progress ?? 0),
                'sent_to_verification' => $sentToVerification,
                'charged' => (int) ($charged->cnt ?? 0),
                'won_revenue' => (float) ($deals->won_revenue ?? 0),
                'charged_amount' => (float) ($charged->amt ?? 0),
                'conversion_rate' => round($conversionRate, 4),
                'charged_rate' => round($chargedRate, 4),
            ],
            'performance' => [
                'total_leads' => $totalLeads,
                'transfer_rate' => $totalLeads > 0 ? round($transfers / $totalLeads, 4) : 0,
                'deals_closed' => $won,
                'close_rate' => $totalLeads > 0 ? round($won / $totalLeads, 4) : 0,
            ],
            'groups' => $groups->map(fn ($g) => [
                'group' => trim(($g->role === 'fronter' ? 'Fronter' : 'Closer').' ('.$g->location.')'),
                'role' => $g->role,
                'location' => $g->location,
                'agents' => (int) $g->agents,
                'leads' => (int) $g->leads,
                'deals' => (int) $g->deals,
                'revenue' => (float) $g->revenue,
                'rate' => $g->leads > 0 ? round($g->deals / $g->leads, 4) : 0,
            ]),
        ]);
    }

    /**
     * Recent floor activity — drives the dashboard's FloorTicker.
     *
     * Pulls the last N events from a union of `deal_stage_transitions`
     * (transferred / closed) and cleared `payments` (charged), with
     * agent attribution. Returns events sorted newest-first.
     *
     *   GET /api/dashboard/activity?limit=30&since_minutes=60
     *
     * The frontend re-fetches every ~30s while the dashboard is live;
     * the `id` field is stable so it can dedupe and animate-in new rows.
     */
    public function activity(Request $request): JsonResponse
    {
        $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'since_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
        ]);

        $tenantId = $this->tenantContext->id();
        $limit = (int) $request->integer('limit', 30);
        $since = CarbonImmutable::now()->subMinutes((int) $request->integer('since_minutes', 60));

        // Stage-transition events (transferred / closed / lost). Pull
        // the agent off the deal so the verb attribution lines up with
        // the leaderboard.
        $transitions = DB::table('deal_stage_transitions as dst')
            ->join('deals as d', 'd.id', '=', 'dst.deal_id')
            ->leftJoin('users as u', 'u.id', '=', 'd.agent_id')
            ->where('dst.tenant_id', $tenantId)
            ->where('dst.occurred_at', '>=', $since)
            ->whereIn('dst.to_stage', ['qualified', 'closed_won', 'closed_lost'])
            ->orderByDesc('dst.occurred_at')
            ->limit($limit)
            ->get([
                'dst.id', 'dst.to_stage', 'dst.occurred_at',
                'd.payable_amount',
                'u.first_name', 'u.last_name',
            ]);

        // Cleared / refunded payment events.
        $payments = DB::table('payments as p')
            ->leftJoin('users as u', 'u.id', '=', 'p.processed_by_id')
            ->where('p.tenant_id', $tenantId)
            ->where(function ($q) use ($since): void {
                $q->where(function ($q2) use ($since): void {
                    $q2->where('p.status', 'succeeded')
                        ->where('p.cleared_at', '>=', $since);
                })->orWhere(function ($q2) use ($since): void {
                    $q2->where('p.status', 'refunded')
                        ->where('p.refunded_at', '>=', $since);
                });
            })
            ->orderByDesc(DB::raw('COALESCE(p.cleared_at, p.refunded_at)'))
            ->limit($limit)
            ->get([
                'p.id', 'p.status', 'p.amount',
                'p.cleared_at', 'p.refunded_at',
                'u.first_name', 'u.last_name',
            ]);

        $events = collect();

        foreach ($transitions as $row) {
            $verb = match ($row->to_stage) {
                'qualified' => 'transferred',
                'closed_won' => 'closed',
                'closed_lost' => 'lost',
                default => 'updated',
            };
            $events->push([
                'id' => 't-'.$row->id,
                'actor' => trim(($row->first_name ?? '').' '.($row->last_name ?? '')) ?: 'System',
                'verb' => $verb,
                'amount' => $verb === 'closed' ? (float) $row->payable_amount : null,
                'at' => CarbonImmutable::parse($row->occurred_at)->toIso8601String(),
            ]);
        }

        foreach ($payments as $row) {
            $events->push([
                'id' => 'p-'.$row->id,
                'actor' => trim(($row->first_name ?? '').' '.($row->last_name ?? '')) ?: 'System',
                'verb' => $row->status === 'refunded' ? 'refunded' : 'charged',
                'amount' => (float) $row->amount,
                'at' => CarbonImmutable::parse($row->cleared_at ?? $row->refunded_at)->toIso8601String(),
            ]);
        }

        return response()->json([
            'data' => $events->sortByDesc('at')->values()->take($limit)->all(),
        ]);
    }

    /**
     * Bucketed series for the hero KPI sparkline.
     *
     *   GET /api/dashboard/sparkline?metric=transfers&period=daily&buckets=24
     *
     * Returns one count per bucket over the requested window. Default is
     * 24 hourly buckets ending now — matches the visual the dashboard
     * draws above the Total Transfers number.
     *
     * Metrics:
     *   - transfers — `to_stage='qualified'` transitions per bucket
     *   - closes    — `to_stage='closed_won'` per bucket
     *   - charged   — cleared `payments` per bucket
     */
    public function sparkline(Request $request): JsonResponse
    {
        $request->validate([
            'metric' => ['nullable', 'in:transfers,closes,charged'],
            'period' => ['nullable', 'in:daily,weekly,monthly'],
            'buckets' => ['nullable', 'integer', 'min:6', 'max:96'],
        ]);

        $tenantId = $this->tenantContext->id();
        $metric = $request->string('metric', 'transfers')->value();
        $period = $request->string('period', 'daily')->value();
        $buckets = (int) $request->integer('buckets', 24);

        $now = CarbonImmutable::now();

        // Bucket size in minutes — we keep all three periods on the
        // same timescale so the dashboard can switch without rejiggering
        // the chart axis.
        $bucketMinutes = match ($period) {
            'monthly' => (int) round((30 * 24 * 60) / $buckets),
            'weekly' => (int) round((7 * 24 * 60) / $buckets),
            default => (int) round((24 * 60) / $buckets),
        };

        // Align $start to the bucket boundary so pre-fill keys match
        // what the SQL aggregation emits. Without alignment, the SQL
        // floors to epoch-aligned bucket starts (e.g., :00 for hourly
        // buckets) while a "now - N minutes" $start lands at e.g. :48,
        // and key lookups silently miss every row.
        $bucketSecs = $bucketMinutes * 60;
        $rawStartEpoch = $now->subMinutes($bucketMinutes * $buckets)->getTimestamp();
        $startEpoch = (int) floor($rawStartEpoch / $bucketSecs) * $bucketSecs;
        $start = CarbonImmutable::createFromTimestamp($startEpoch);

        // Pre-fill the buckets so empty windows still render the right
        // number of bars (zero-height) — beats a sparkline that visually
        // skips quiet hours.
        $series = [];
        for ($i = 0; $i < $buckets; $i++) {
            $bucketStart = $start->addMinutes($bucketMinutes * $i);
            $series[(string) $bucketStart->getTimestamp()] = [
                't' => $bucketStart->toIso8601String(),
                'v' => 0,
            ];
        }

        // Run the metric query and bucket the results.
        $rows = $this->sparklineQuery($metric, $tenantId, $start, $bucketMinutes);

        foreach ($rows as $row) {
            // The SQL aggregation already floored each row to the bucket
            // boundary; convert that to its UNIX epoch and look it up.
            $ts = CarbonImmutable::parse($row->ts);
            $key = (string) ((int) floor($ts->getTimestamp() / $bucketSecs) * $bucketSecs);
            if (isset($series[$key])) {
                $series[$key]['v'] = (int) $row->c;
            }
        }

        return response()->json([
            'metric' => $metric,
            'period' => $period,
            'buckets' => $buckets,
            'bucket_minutes' => $bucketMinutes,
            'series' => array_values($series),
        ]);
    }

    /**
     * Per-agent live status + day's deal stats — drives the Floor
     * Leaderboard panel on the dashboard.
     *
     *   GET /api/dashboard/floor-status
     *
     * Sorted by today's revenue desc; only agents whose role can
     * appear on the floor (closer / fronter) are included.
     */
    /**
     * Row-2 hero KPIs — listing service health.
     *
     *   GET /api/dashboard/listing-health
     *
     * Per §3.1 of the prompt — replaces the legacy call-floor-only
     * hero strip. Four numbers the operator needs to see at a glance:
     *
     *   listings_live           count + total inventory value
     *   bookings_this_week      count + total commission earned
     *   time_to_live_seconds    median + p90 over the last 30 days
     *                           (fee paid → live on at least one site)
     *   refund_chargeback_rate  rolling 30d (refunds + chargebacks)
     *                           / cleared payments. > 2% = red
     *                           regulatory signal.
     *
     * Plus a small per-state distribution + "going dark soon" list
     * (listings within 14 days of check-in with no booking yet).
     */
    public function listingHealth(): JsonResponse
    {
        $tenantId = $this->tenantContext->id();
        $now = CarbonImmutable::now();
        $weekStart = $now->startOfWeek();
        $weekEnd = $now->endOfWeek();
        $thirtyDaysAgo = $now->subDays(30);

        // Listings live now — count + sum(asking_price) as inventory value.
        $liveStatuses = ['live', 'inquiry_received', 'pending_booking'];
        $live = DB::table('listings')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', $liveStatuses)
            ->whereNull('deleted_at')
            ->selectRaw('COUNT(*) AS cnt, COALESCE(SUM(asking_price), 0) AS inv_value')
            ->first();

        // Bookings this week — count + sum(our_commission).
        $bookings = DB::table('bookings')
            ->where('tenant_id', $tenantId)
            ->whereNotNull('listing_id')
            ->whereNull('deleted_at')
            ->whereBetween('confirmed_at', [$weekStart, $weekEnd])
            ->selectRaw('
                COUNT(*) AS cnt,
                COALESCE(SUM(total_price), 0) AS rental_value,
                COALESCE(SUM(our_commission), 0) AS commission,
                SUM(CASE WHEN owner_notified_at IS NOT NULL THEN 1 ELSE 0 END) AS notified
            ')
            ->first();

        // Time-to-live — fee paid at deal.agreement_signed_at to first
        // partner site going live for any listing on that deal. We
        // calculate per-deal using the earliest partner_site_listing
        // .went_live_at. 30-day window.
        $ttlRows = DB::table('partner_site_listings as psl')
            ->join('listings as l', 'l.id', '=', 'psl.listing_id')
            ->join('deals as d', 'd.id', '=', 'l.deal_id')
            ->where('psl.tenant_id', $tenantId)
            ->whereNotNull('psl.went_live_at')
            ->whereNotNull('d.agreement_signed_at')
            ->where('psl.went_live_at', '>=', $thirtyDaysAgo)
            ->groupBy('d.id')
            ->selectRaw('
                d.id AS deal_id,
                d.agreement_signed_at,
                MIN(psl.went_live_at) AS first_live_at
            ')
            ->get();

        $ttlSecondsList = [];
        foreach ($ttlRows as $r) {
            $signedAt = strtotime((string) $r->agreement_signed_at);
            $liveAt = strtotime((string) $r->first_live_at);
            if ($signedAt && $liveAt && $liveAt >= $signedAt) {
                $ttlSecondsList[] = $liveAt - $signedAt;
            }
        }
        sort($ttlSecondsList);
        $ttlMedian = ! empty($ttlSecondsList)
            ? $ttlSecondsList[(int) floor((count($ttlSecondsList) - 1) / 2)]
            : null;
        $ttlP90 = ! empty($ttlSecondsList)
            ? $ttlSecondsList[(int) floor(0.9 * (count($ttlSecondsList) - 1))]
            : null;

        // Refund + chargeback rate over the last 30 days. Denominator
        // is cleared payments in the same window — net of refunds is
        // what regulators look at.
        $clearedPayments = (int) DB::table('payments')
            ->where('tenant_id', $tenantId)
            ->where('status', 'succeeded')
            ->where('type', 'charge')
            ->where('cleared_at', '>=', $thirtyDaysAgo)
            ->count();

        $openRefundCount = (int) DB::table('refund_cases')
            ->where('tenant_id', $tenantId)
            ->where('opened_at', '>=', $thirtyDaysAgo)
            ->count();

        $chargebackCount = (int) DB::table('chargeback_cases')
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->count();

        $reversalRate = $clearedPayments > 0
            ? round(($openRefundCount + $chargebackCount) / $clearedPayments, 4)
            : null;

        // Going dark soon — live listings whose check-in is within 14
        // days but have no booking yet. Operators want to surface
        // these to push price drops or extra marketing.
        $darkSoon = DB::table('listings as l')
            ->join('properties as p', 'p.id', '=', 'l.property_id')
            ->join('leads as o', 'o.id', '=', 'p.owner_id')
            ->where('l.tenant_id', $tenantId)
            ->whereIn('l.status', ['live', 'inquiry_received'])
            ->whereNull('l.deleted_at')
            ->where('l.check_in_date', '>', $now->toDateString())
            ->where('l.check_in_date', '<=', $now->addDays(14)->toDateString())
            ->orderBy('l.check_in_date')
            ->limit(10)
            ->get([
                'l.id', 'l.check_in_date', 'l.asking_price',
                'p.resort_name', 'p.location_state',
                'o.id as owner_id', 'o.first_name', 'o.last_name',
            ]);

        // Per-state live distribution (top 5).
        $byState = DB::table('listings as l')
            ->join('properties as p', 'p.id', '=', 'l.property_id')
            ->where('l.tenant_id', $tenantId)
            ->whereIn('l.status', $liveStatuses)
            ->whereNull('l.deleted_at')
            ->groupBy('p.location_state')
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->limit(5)
            ->get([
                'p.location_state as state',
                DB::raw('COUNT(*) AS cnt'),
                DB::raw('COALESCE(SUM(l.asking_price), 0) AS value'),
            ]);

        return response()->json([
            'listings_live' => [
                'count' => (int) ($live->cnt ?? 0),
                'inventory_value' => round((float) ($live->inv_value ?? 0), 2),
            ],
            'bookings_this_week' => [
                'count' => (int) ($bookings->cnt ?? 0),
                'rental_value' => round((float) ($bookings->rental_value ?? 0), 2),
                'commission' => round((float) ($bookings->commission ?? 0), 2),
                'owners_notified' => (int) ($bookings->notified ?? 0),
            ],
            'time_to_live' => [
                'median_seconds' => $ttlMedian,
                'p90_seconds' => $ttlP90,
                'sample_size' => count($ttlSecondsList),
            ],
            'reversal_rate' => [
                'rate' => $reversalRate,
                'refund_cases' => $openRefundCount,
                'chargeback_cases' => $chargebackCount,
                'cleared_payments' => $clearedPayments,
            ],
            'going_dark_soon' => $darkSoon->map(fn ($r) => [
                'id' => $r->id,
                'resort_name' => $r->resort_name,
                'state' => $r->location_state,
                'check_in_date' => $r->check_in_date,
                'asking_price' => (float) $r->asking_price,
                'days_until' => max(0, (int) floor(
                    (strtotime((string) $r->check_in_date) - $now->getTimestamp()) / 86400
                )),
                'owner_id' => $r->owner_id,
                'owner_name' => trim(($r->first_name ?? '').' '.($r->last_name ?? ''))
                    ?: '(unnamed owner)',
            ])->values(),
            'by_state' => $byState->map(fn ($r) => [
                'state' => $r->state,
                'count' => (int) $r->cnt,
                'value' => (float) $r->value,
            ])->values(),
        ]);
    }

    /**
     * Partner-site health rollup — one row per active partner.
     *
     *   GET /api/dashboard/partner-health
     *
     * Columns the dashboard renders: listings pushed, currently live,
     * avg time-to-live, views, inquiries, bookings (where the
     * underlying listing booked), conversion rate.
     */
    public function partnerHealth(): JsonResponse
    {
        $tenantId = $this->tenantContext->id();

        // Per-site rollup over partner_site_listings + a sub-join to
        // bookings keyed on listing_id (counted once per partner whose
        // listing booked — co-credit each partner the listing was on).
        $rows = DB::table('partner_sites as ps')
            ->leftJoin('partner_site_listings as psl', 'psl.partner_site_id', '=', 'ps.id')
            ->where('ps.tenant_id', $tenantId)
            ->whereNull('ps.deleted_at')
            ->groupBy('ps.id', 'ps.name', 'ps.slug', 'ps.is_active')
            ->selectRaw("
                ps.id,
                ps.name,
                ps.slug,
                ps.is_active,
                COUNT(psl.id) AS pushes_total,
                SUM(CASE WHEN psl.status = 'live' THEN 1 ELSE 0 END) AS pushes_live,
                SUM(CASE WHEN psl.status = 'rejected' THEN 1 ELSE 0 END) AS pushes_rejected,
                COALESCE(SUM(psl.view_count), 0) AS views,
                COALESCE(SUM(psl.inquiry_count), 0) AS inquiries
            ")
            ->get();

        // Per-site bookings: a listing books once, but it might be on
        // 3 partner sites. Each site gets co-credit. Pull listing_ids
        // by partner, then count bookings on those listings.
        $listingsByPartner = DB::table('partner_site_listings')
            ->where('tenant_id', $tenantId)
            ->select(['partner_site_id', 'listing_id'])
            ->get()
            ->groupBy('partner_site_id');

        // Aggregated TTL per site — avg seconds from pushed_at to went_live_at.
        $ttls = DB::table('partner_site_listings')
            ->where('tenant_id', $tenantId)
            ->whereNotNull('pushed_at')
            ->whereNotNull('went_live_at')
            ->groupBy('partner_site_id')
            ->selectRaw('
                partner_site_id,
                AVG(TIMESTAMPDIFF(SECOND, pushed_at, went_live_at)) AS avg_ttl_seconds
            ')
            ->get()
            ->keyBy('partner_site_id');

        $data = $rows->map(function ($r) use ($listingsByPartner, $tenantId, $ttls) {
            $listingIds = $listingsByPartner->get($r->id, collect())->pluck('listing_id')->all();
            $bookingsCount = empty($listingIds)
                ? 0
                : DB::table('bookings')
                    ->where('tenant_id', $tenantId)
                    ->whereIn('listing_id', $listingIds)
                    ->whereNull('deleted_at')
                    ->count();

            $inquiriesNum = (int) $r->inquiries;
            $conversionRate = $inquiriesNum > 0
                ? round($bookingsCount / $inquiriesNum, 4)
                : null;

            return [
                'id' => $r->id,
                'name' => $r->name,
                'slug' => $r->slug,
                'is_active' => (bool) $r->is_active,
                'pushes_total' => (int) $r->pushes_total,
                'pushes_live' => (int) $r->pushes_live,
                'pushes_rejected' => (int) $r->pushes_rejected,
                'avg_ttl_seconds' => isset($ttls[$r->id])
                    ? (int) round((float) $ttls[$r->id]->avg_ttl_seconds)
                    : null,
                'views' => (int) $r->views,
                'inquiries' => $inquiriesNum,
                'bookings' => $bookingsCount,
                'conversion_rate' => $conversionRate,
            ];
        });

        return response()->json(['data' => $data->values()]);
    }

    /**
     * Booking pipeline funnel + week-over-week comparison.
     *
     *   GET /api/dashboard/booking-pipeline
     *
     * Stages: inquiries → negotiating → booked → completed.
     * Returns current-week counts, last-week counts, and the
     * stale-inquiry warning (open > 4h with no response).
     */
    public function bookingPipeline(): JsonResponse
    {
        $tenantId = $this->tenantContext->id();
        $now = CarbonImmutable::now();
        $thisWeekStart = $now->startOfWeek();
        $lastWeekStart = $now->subWeek()->startOfWeek();
        $lastWeekEnd = $now->subWeek()->endOfWeek();

        // Funnel counts — by inquiry status created in the window.
        $thisWeekFunnel = $this->funnelCounts($tenantId, $thisWeekStart, $now);
        $lastWeekFunnel = $this->funnelCounts($tenantId, $lastWeekStart, $lastWeekEnd);

        // Stale inquiries — new, > 4h old, no response.
        $staleCount = (int) DB::table('rental_inquiries')
            ->where('tenant_id', $tenantId)
            ->where('status', 'new')
            ->whereNull('responded_at')
            ->where('created_at', '<', $now->subHours(4))
            ->count();

        // Avg days from inquiry to booking — bookings made in the
        // last 30 days whose inquiry has a created_at timestamp.
        $avgConversionRows = DB::table('bookings as b')
            ->join('rental_inquiries as i', 'i.id', '=', 'b.inquiry_id')
            ->where('b.tenant_id', $tenantId)
            ->whereNotNull('b.confirmed_at')
            ->where('b.confirmed_at', '>=', $now->subDays(30))
            ->selectRaw('
                AVG(TIMESTAMPDIFF(SECOND, i.created_at, b.confirmed_at)) AS avg_seconds,
                COUNT(*) AS sample
            ')
            ->first();

        $avgDays = $avgConversionRows && $avgConversionRows->avg_seconds !== null
            ? round((float) $avgConversionRows->avg_seconds / 86400, 1)
            : null;

        return response()->json([
            'this_week' => $thisWeekFunnel,
            'last_week' => $lastWeekFunnel,
            'stale_inquiries' => $staleCount,
            'avg_days_to_book' => $avgDays,
            'avg_days_sample' => (int) ($avgConversionRows->sample ?? 0),
        ]);
    }

    /**
     * Compliance posture — disclosure pass rate, open cases, and the
     * inverted closer leaderboard (worst refund rate at the BOTTOM).
     *
     *   GET /api/dashboard/compliance-posture
     */
    public function compliancePosture(): JsonResponse
    {
        $tenantId = $this->tenantContext->id();
        $now = CarbonImmutable::now();
        $thirtyDaysAgo = $now->subDays(30);

        // Disclosure pass rate over the last 30 days.
        $disclosureRows = DB::table('compliance_recordings')
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->selectRaw("
                COUNT(*) AS total,
                SUM(CASE WHEN compliance_status = 'passed' THEN 1 ELSE 0 END) AS passed,
                SUM(CASE WHEN compliance_status = 'failed' THEN 1 ELSE 0 END) AS failed,
                SUM(CASE WHEN compliance_status = 'flagged_for_audit' THEN 1 ELSE 0 END) AS flagged,
                SUM(CASE WHEN compliance_status = 'pending_review' THEN 1 ELSE 0 END) AS pending
            ")
            ->first();

        $disclosureTotal = (int) ($disclosureRows->total ?? 0);
        $passRate = $disclosureTotal > 0
            ? round(((int) $disclosureRows->passed) / $disclosureTotal, 4)
            : null;

        // Open refund cases by reason.
        $refundsByReason = DB::table('refund_cases')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['opened', 'investigating', 'approved'])
            ->groupBy('reason')
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->get([
                'reason',
                DB::raw('COUNT(*) AS cnt'),
                DB::raw('COALESCE(SUM(refund_amount), 0) AS amt'),
            ]);

        // Open chargebacks with respond-by dates.
        $openChargebacks = DB::table('chargeback_cases')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['received', 'evidence_gathering', 'evidence_submitted'])
            ->orderBy('respond_by_date')
            ->limit(20)
            ->get([
                'id', 'processor_case_id', 'disputed_amount',
                'respond_by_date', 'status',
            ]);

        // Inverted closer leaderboard — by refund rate over 90 days.
        // Closers with the worst rate are surfaced for coaching.
        $closerData = DB::table('users as u')
            ->leftJoin('deals as d', function ($j) use ($now) {
                $j->on('u.id', '=', 'd.agent_id')
                    ->where('d.stage', '=', 'closed_won')
                    ->where('d.closed_at', '>=', $now->subDays(90));
            })
            ->leftJoin('refund_cases as rc', function ($j) use ($now) {
                $j->on('rc.deal_id', '=', 'd.id')
                    ->where('rc.opened_at', '>=', $now->subDays(90));
            })
            ->where('u.role', 'closer')
            ->groupBy('u.id', 'u.first_name', 'u.last_name')
            ->selectRaw('
                u.id,
                u.first_name,
                u.last_name,
                COUNT(DISTINCT d.id) AS closes,
                COUNT(DISTINCT rc.id) AS refund_cases
            ')
            ->get();

        // Sort worst-first (high refund rate). Tie-break: closers with
        // > 0 cases sort above closers with 0 cases AT 0 rate so
        // someone with 1 case on 1 deal doesn't outrank someone with
        // 3 cases on 30 deals.
        $closers = $closerData->map(function ($r) {
            $closes = (int) $r->closes;
            $refunds = (int) $r->refund_cases;
            $rate = $closes > 0 ? $refunds / $closes : 0.0;

            return [
                'id' => $r->id,
                'name' => trim(($r->first_name ?? '').' '.($r->last_name ?? ''))
                    ?: '(unnamed)',
                'closes' => $closes,
                'refund_cases' => $refunds,
                'refund_rate' => round($rate, 4),
            ];
        })->sortBy([
            ['refund_rate', 'desc'],
            ['closes', 'desc'],
        ])->values();

        return response()->json([
            'disclosure_pass_rate' => $passRate,
            'disclosure_target' => 0.95,
            'disclosure_breakdown' => [
                'total' => $disclosureTotal,
                'passed' => (int) ($disclosureRows->passed ?? 0),
                'failed' => (int) ($disclosureRows->failed ?? 0),
                'flagged' => (int) ($disclosureRows->flagged ?? 0),
                'pending' => (int) ($disclosureRows->pending ?? 0),
            ],
            'open_refunds_by_reason' => $refundsByReason->map(fn ($r) => [
                'reason' => $r->reason,
                'count' => (int) $r->cnt,
                'amount' => (float) $r->amt,
            ])->values(),
            'open_chargebacks' => $openChargebacks->map(function ($r) use ($now) {
                $by = $r->respond_by_date
                    ? CarbonImmutable::parse($r->respond_by_date)->startOfDay()
                    : null;

                return [
                    'id' => $r->id,
                    'processor_case_id' => $r->processor_case_id,
                    'amount' => (float) $r->disputed_amount,
                    'respond_by_date' => $r->respond_by_date,
                    'status' => $r->status,
                    'days_until_due' => $by !== null
                        ? (int) round($now->startOfDay()->diffInDays($by, false))
                        : null,
                ];
            })->values(),
            'closer_refund_rates' => $closers->all(),
        ]);
    }

    /**
     * Owner success signals — re-list rate, avg LTV, multi-week
     * upsell opportunities.
     *
     *   GET /api/dashboard/owner-signals
     */
    public function ownerSignals(): JsonResponse
    {
        $tenantId = $this->tenantContext->id();

        // Re-list rate — owners with > 1 agreement.
        $perOwner = DB::table('deals')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->where('stage', 'closed_won')
            ->groupBy('lead_id')
            ->selectRaw('
                lead_id,
                COUNT(*) AS deals,
                COALESCE(SUM(listing_fee_collected), 0) AS lifetime_fees
            ')
            ->get();

        $totalOwnersWithDeals = $perOwner->count();
        $multiDealOwners = $perOwner->filter(fn ($r) => (int) $r->deals > 1)->count();
        $relistRate = $totalOwnersWithDeals > 0
            ? round($multiDealOwners / $totalOwnersWithDeals, 4)
            : null;
        $avgLtv = $totalOwnersWithDeals > 0
            ? round(((float) $perOwner->sum('lifetime_fees')) / $totalOwnersWithDeals, 2)
            : null;

        // Multi-week upsell — owners with > 1 property but only 1
        // active listing. They have inventory; we haven't listed it
        // all. Or with no live listing at all but they're a past
        // customer.
        $upsellRows = DB::table('properties as p')
            ->join('leads as o', 'o.id', '=', 'p.owner_id')
            ->leftJoin('listings as l', function ($j): void {
                $j->on('l.property_id', '=', 'p.id')
                    ->whereIn('l.status', ['live', 'inquiry_received', 'pending_booking'])
                    ->whereNull('l.deleted_at');
            })
            ->where('p.tenant_id', $tenantId)
            ->whereNull('p.deleted_at')
            ->groupBy('o.id', 'o.first_name', 'o.last_name')
            ->havingRaw('COUNT(DISTINCT p.id) > COUNT(DISTINCT l.id)')
            ->orderByDesc(DB::raw('COUNT(DISTINCT p.id) - COUNT(DISTINCT l.id)'))
            ->limit(10)
            ->get([
                'o.id', 'o.first_name', 'o.last_name',
                DB::raw('COUNT(DISTINCT p.id) AS properties_count'),
                DB::raw('COUNT(DISTINCT l.id) AS active_listings_count'),
            ]);

        return response()->json([
            'relist_rate' => $relistRate,
            'multi_deal_owners' => $multiDealOwners,
            'total_owners_with_deals' => $totalOwnersWithDeals,
            'avg_lifetime_fees' => $avgLtv,
            'upsell_opportunities' => $upsellRows->map(fn ($r) => [
                'owner_id' => $r->id,
                'owner_name' => trim(($r->first_name ?? '').' '.($r->last_name ?? ''))
                    ?: '(unnamed owner)',
                'properties' => (int) $r->properties_count,
                'active_listings' => (int) $r->active_listings_count,
                'untapped' => (int) $r->properties_count - (int) $r->active_listings_count,
            ])->values(),
        ]);
    }

    /**
     * Per-stage inquiry funnel counts within a window.
     *
     * @return array{inquiries: int, negotiating: int, booked: int, completed: int, lost: int}
     */
    private function funnelCounts(string $tenantId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $row = DB::table('rental_inquiries')
            ->where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw("
                COUNT(*) AS inquiries,
                SUM(CASE WHEN status = 'negotiating' THEN 1 ELSE 0 END) AS negotiating,
                SUM(CASE WHEN status = 'booked' THEN 1 ELSE 0 END) AS booked,
                SUM(CASE WHEN status = 'lost' THEN 1 ELSE 0 END) AS lost
            ")
            ->first();

        $completed = (int) DB::table('bookings')
            ->where('tenant_id', $tenantId)
            ->whereNotNull('listing_id')
            ->whereNull('deleted_at')
            ->where('status', 'completed')
            ->whereBetween('confirmed_at', [$start, $end])
            ->count();

        return [
            'inquiries' => (int) ($row->inquiries ?? 0),
            'negotiating' => (int) ($row->negotiating ?? 0),
            'booked' => (int) ($row->booked ?? 0),
            'lost' => (int) ($row->lost ?? 0),
            'completed' => $completed,
        ];
    }

    public function floorStatus(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->id();
        $startOfDay = CarbonImmutable::now()->startOfDay();

        // Per-agent rollup of today's deal/revenue. We left-join because
        // an idle closer who hasn't closed today still belongs on the
        // board (they'd just sit at rank N with $0).
        $rows = DB::table('users as u')
            ->leftJoin('agent_statuses as s', 's.agent_id', '=', 'u.id')
            ->leftJoin('deals as d', function ($j) use ($startOfDay): void {
                $j->on('d.agent_id', '=', 'u.id')
                    ->where('d.stage', '=', 'closed_won')
                    ->where('d.closed_at', '>=', $startOfDay);
            })
            ->where('u.tenant_id', $tenantId)
            ->whereIn('u.role', ['closer', 'fronter'])
            ->groupBy(
                'u.id', 'u.first_name', 'u.last_name', 'u.role',
                'u.is_panama_based', 's.status', 's.status_changed_at',
            )
            ->selectRaw('
                u.id,
                u.first_name,
                u.last_name,
                u.role,
                u.is_panama_based,
                s.status AS floor_status,
                s.status_changed_at AS since,
                COUNT(DISTINCT d.id) AS deals_today,
                COALESCE(SUM(DISTINCT d.payable_amount), 0) AS revenue_today
            ')
            ->orderByDesc('revenue_today')
            ->orderBy('u.last_name')
            ->get();

        $data = $rows->values()->map(fn ($r, $idx) => [
            'rank' => $idx + 1,
            'id' => $r->id,
            'name' => trim(($r->first_name ?? '').' '.($r->last_name ?? '')),
            'role' => $r->role,
            'location' => $r->is_panama_based ? 'Panama' : 'US',
            'status' => $r->floor_status ?: 'offline',
            'since' => $r->since ? CarbonImmutable::parse($r->since)->toIso8601String() : null,
            'deals_today' => (int) $r->deals_today,
            'revenue_today' => (float) $r->revenue_today,
        ]);

        return response()->json(['data' => $data->all()]);
    }

    /**
     * @return \Illuminate\Support\Collection<int, object{ts: string, c: int}>
     */
    private function sparklineQuery(string $metric, string $tenantId, CarbonImmutable $start, int $bucketMinutes): \Illuminate\Support\Collection
    {
        // MySQL bucketing: floor each row's timestamp into bucket-aligned
        // values via FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(col)/N)*N). Keeps
        // us off MySQL 8 window functions so the same SQL would work on
        // older MySQL too if we ever land there.
        $secs = $bucketMinutes * 60;

        return match ($metric) {
            'closes' => DB::table('deal_stage_transitions')
                ->where('tenant_id', $tenantId)
                ->where('to_stage', 'closed_won')
                ->where('occurred_at', '>=', $start)
                ->selectRaw("FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(occurred_at)/?)*?) AS ts, COUNT(*) AS c", [$secs, $secs])
                ->groupBy('ts')
                ->get(),
            'charged' => DB::table('payments')
                ->where('tenant_id', $tenantId)
                ->where('status', 'succeeded')
                ->where('cleared_at', '>=', $start)
                ->selectRaw("FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(cleared_at)/?)*?) AS ts, COUNT(*) AS c", [$secs, $secs])
                ->groupBy('ts')
                ->get(),
            default => DB::table('deal_stage_transitions') // 'transfers'
                ->where('tenant_id', $tenantId)
                ->where('to_stage', 'qualified')
                ->where('occurred_at', '>=', $start)
                ->selectRaw("FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(occurred_at)/?)*?) AS ts, COUNT(*) AS c", [$secs, $secs])
                ->groupBy('ts')
                ->get(),
        };
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string}
     */
    private function resolveWindow(string $period, int $offset): array
    {
        $now = CarbonImmutable::now();

        return match ($period) {
            'daily' => [
                $now->subDays(abs($offset))->startOfDay(),
                $now->subDays(abs($offset))->endOfDay(),
                $now->subDays(abs($offset))->toDateString(),
            ],
            'monthly' => [
                $now->subMonths(abs($offset))->startOfMonth(),
                $now->subMonths(abs($offset))->endOfMonth(),
                $now->subMonths(abs($offset))->format('Y-m'),
            ],
            default => [
                $now->subWeeks(abs($offset))->startOfWeek(),
                $now->subWeeks(abs($offset))->endOfWeek(),
                $now->subWeeks(abs($offset))->format('Y-\WW'),
            ],
        };
    }
}
