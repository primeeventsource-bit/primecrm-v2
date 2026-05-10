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
