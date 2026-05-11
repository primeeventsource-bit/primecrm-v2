<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Controllers;

use App\Core\Shared\TenantContext;
use App\Modules\Compliance\Domain\Enums\ChargebackCaseStatus;
use App\Modules\Compliance\Domain\Models\ChargebackCase;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * Chargeback (processor dispute) workflow.
 *
 *   GET   /api/compliance/chargeback-cases
 *   POST  /api/compliance/chargeback-cases/{id}/transition
 *
 * Sorted by respond_by_date — overdue ones lead. The respond_by date
 * is the load-bearing field: miss it and we lose the dispute by
 * default. Anything under 3 days remaining renders red.
 */
final class ChargebackCaseController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', 'string'],
            'urgent_only' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $tenantId = $this->tenantContext->id();
        $statusFilter = $request->string('status', 'open')->value();
        $urgent = $request->boolean('urgent_only');
        $page = (int) $request->integer('page', 1);
        $perPage = (int) $request->integer('per_page', 25);

        $base = DB::table('chargeback_cases as cb')
            ->join('deals as d', 'd.id', '=', 'cb.deal_id')
            ->join('leads as l', 'l.id', '=', 'd.lead_id')
            ->where('cb.tenant_id', $tenantId);

        if ($statusFilter === 'open') {
            $base->whereIn('cb.status', ['received', 'evidence_gathering', 'evidence_submitted']);
        } elseif ($statusFilter !== '' && $statusFilter !== 'all') {
            $base->where('cb.status', $statusFilter);
        }

        if ($urgent) {
            $base->whereIn('cb.status', ['received', 'evidence_gathering'])
                ->where('cb.respond_by_date', '<=', now()->addDays(3)->toDateString());
        }

        $total = (clone $base)->count();

        $rows = $base
            ->orderByRaw("
                CASE cb.status
                    WHEN 'received' THEN 0
                    WHEN 'evidence_gathering' THEN 1
                    WHEN 'evidence_submitted' THEN 2
                    WHEN 'won' THEN 3
                    WHEN 'lost' THEN 4
                    ELSE 5
                END
            ")
            ->orderBy('cb.respond_by_date')
            ->limit($perPage)
            ->offset(($page - 1) * $perPage)
            ->get([
                'cb.id', 'cb.deal_id', 'cb.processor_case_id',
                'cb.disputed_amount', 'cb.reason_code',
                'cb.respond_by_date', 'cb.status', 'cb.evidence_attached',
                'cb.created_at',
                'l.id as owner_id', 'l.first_name as owner_first',
                'l.last_name as owner_last',
                'd.listing_fee', 'd.agreement_status',
            ]);

        $now = CarbonImmutable::now()->startOfDay();

        $stats = DB::table('chargeback_cases')
            ->where('tenant_id', $tenantId)
            ->selectRaw("
                SUM(CASE WHEN status IN ('received','evidence_gathering','evidence_submitted') THEN 1 ELSE 0 END) AS open_count,
                SUM(CASE WHEN status = 'won' THEN 1 ELSE 0 END) AS won_count,
                SUM(CASE WHEN status = 'lost' THEN 1 ELSE 0 END) AS lost_count,
                COALESCE(SUM(CASE WHEN status IN ('received','evidence_gathering','evidence_submitted') THEN disputed_amount ELSE 0 END), 0) AS open_amount
            ")
            ->first();

        $urgentCount = DB::table('chargeback_cases')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['received', 'evidence_gathering'])
            ->where('respond_by_date', '<=', now()->addDays(3)->toDateString())
            ->count();

        return response()->json([
            'data' => $rows->map(function ($r) use ($now) {
                $by = $r->respond_by_date !== null
                    ? CarbonImmutable::parse($r->respond_by_date)->startOfDay()
                    : null;
                $daysLeft = $by !== null
                    ? (int) round($now->diffInDays($by, false))
                    : null;

                return [
                    'id' => $r->id,
                    'deal_id' => $r->deal_id,
                    'processor_case_id' => $r->processor_case_id,
                    'disputed_amount' => (float) $r->disputed_amount,
                    'reason_code' => $r->reason_code,
                    'respond_by_date' => $r->respond_by_date,
                    'days_until_due' => $daysLeft,
                    'is_overdue' => $daysLeft !== null && $daysLeft < 0,
                    'is_urgent' => $daysLeft !== null && $daysLeft >= 0 && $daysLeft <= 3,
                    'status' => $r->status,
                    'evidence_attached' => $r->evidence_attached !== null
                        ? json_decode($r->evidence_attached, true)
                        : null,
                    'owner_id' => $r->owner_id,
                    'owner_name' => trim(($r->owner_first ?? '').' '.($r->owner_last ?? ''))
                        ?: '(unnamed owner)',
                    'listing_fee' => $r->listing_fee !== null ? (float) $r->listing_fee : null,
                    'agreement_status' => $r->agreement_status ?? null,
                ];
            })->values(),
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
            'stats' => [
                'open_count' => (int) ($stats->open_count ?? 0),
                'won_count' => (int) ($stats->won_count ?? 0),
                'lost_count' => (int) ($stats->lost_count ?? 0),
                'open_amount' => (float) ($stats->open_amount ?? 0),
                'urgent_count' => $urgentCount,
                'win_rate' => (($stats->won_count ?? 0) + ($stats->lost_count ?? 0)) > 0
                    ? round(((int) $stats->won_count) / (((int) $stats->won_count) + ((int) $stats->lost_count)), 4)
                    : null,
            ],
        ]);
    }

    public function transition(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'to' => ['required', 'in:evidence_gathering,evidence_submitted,won,lost'],
            'evidence' => ['nullable', 'array'],
        ]);

        $case = ChargebackCase::query()->find($id);
        if ($case === null) {
            return response()->json(['message' => 'Chargeback case not found'], 404);
        }

        $updates = ['status' => $request->string('to')->value()];

        // Merge any evidence additions into the existing JSON blob.
        if ($request->filled('evidence')) {
            $current = is_array($case->evidence_attached) ? $case->evidence_attached : [];
            $updates['evidence_attached'] = array_merge($current, $request->input('evidence', []));
        }

        $case->forceFill($updates)->save();

        return response()->json([
            'message' => 'Chargeback case status updated.',
        ]);
    }
}
