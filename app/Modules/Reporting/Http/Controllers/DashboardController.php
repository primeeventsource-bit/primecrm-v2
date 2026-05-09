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
