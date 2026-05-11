<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Controllers;

use App\Core\Shared\TenantContext;
use App\Modules\Compliance\Domain\Enums\RefundCaseStatus;
use App\Modules\Compliance\Domain\Enums\RefundReason;
use App\Modules\Compliance\Domain\Models\RefundCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Refund-case workflow.
 *
 *   GET   /api/compliance/refund-cases
 *   POST  /api/compliance/refund-cases             open a new case
 *   POST  /api/compliance/refund-cases/{id}/transition  status flip
 *
 * Distinct from the row-level Payment refund (which is the financial
 * event). This is the investigation + decision audit trail.
 */
final class RefundCaseController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', 'string'],
            'high_risk' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $tenantId = $this->tenantContext->id();
        $statusFilter = $request->string('status', 'open')->value();
        $highRisk = $request->boolean('high_risk');
        $q = $request->string('q')->value();
        $page = (int) $request->integer('page', 1);
        $perPage = (int) $request->integer('per_page', 25);

        $base = DB::table('refund_cases as rc')
            ->join('deals as d', 'd.id', '=', 'rc.deal_id')
            ->join('leads as l', 'l.id', '=', 'd.lead_id')
            ->leftJoin('users as opener', 'opener.id', '=', 'rc.opened_by')
            ->where('rc.tenant_id', $tenantId);

        // Status filter — 'open' is a convenience meta-status for
        // anything not resolved.
        if ($statusFilter === 'open') {
            $base->whereIn('rc.status', ['opened', 'investigating', 'approved']);
        } elseif ($statusFilter !== '' && $statusFilter !== 'all') {
            $base->where('rc.status', $statusFilter);
        }

        if ($highRisk) {
            $base->whereIn('rc.reason', [
                RefundReason::MisrepresentationClaim->value,
                RefundReason::Unauthorized->value,
                RefundReason::ServiceNotDelivered->value,
            ]);
        }
        if ($q !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';
            $base->where(function ($qq) use ($like): void {
                $qq->where('l.first_name', 'like', $like)
                    ->orWhere('l.last_name', 'like', $like)
                    ->orWhere('rc.owner_statement', 'like', $like);
            });
        }

        $total = (clone $base)->count();

        $rows = $base
            ->orderByRaw("
                CASE rc.status
                    WHEN 'opened' THEN 0
                    WHEN 'investigating' THEN 1
                    WHEN 'approved' THEN 2
                    WHEN 'denied' THEN 3
                    WHEN 'processed' THEN 4
                    WHEN 'escalated_to_chargeback' THEN 5
                    ELSE 6
                END
            ")
            ->orderByDesc('rc.opened_at')
            ->limit($perPage)
            ->offset(($page - 1) * $perPage)
            ->get([
                'rc.id', 'rc.deal_id', 'rc.refund_amount', 'rc.reason',
                'rc.owner_statement', 'rc.status', 'rc.opened_at',
                'rc.resolved_at', 'rc.created_at',
                'l.id as owner_id', 'l.first_name as owner_first',
                'l.last_name as owner_last',
                'd.listing_fee', 'd.agreement_status',
                'opener.first_name as opener_first', 'opener.last_name as opener_last',
            ]);

        $stats = DB::table('refund_cases')
            ->where('tenant_id', $tenantId)
            ->selectRaw("
                SUM(CASE WHEN status IN ('opened','investigating','approved') THEN 1 ELSE 0 END) AS open_count,
                SUM(CASE WHEN status IN ('processed','denied') THEN 1 ELSE 0 END) AS resolved_count,
                SUM(CASE WHEN status = 'escalated_to_chargeback' THEN 1 ELSE 0 END) AS escalated_count,
                COALESCE(SUM(CASE WHEN status IN ('opened','investigating','approved') THEN refund_amount ELSE 0 END), 0) AS open_amount,
                SUM(CASE WHEN reason IN ('misrepresentation_claim','unauthorized','service_not_delivered') THEN 1 ELSE 0 END) AS high_risk_count
            ")
            ->first();

        return response()->json([
            'data' => $rows->map(fn ($r) => $this->reshape($r))->values(),
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
            'stats' => [
                'open_count' => (int) ($stats->open_count ?? 0),
                'resolved_count' => (int) ($stats->resolved_count ?? 0),
                'escalated_count' => (int) ($stats->escalated_count ?? 0),
                'open_amount' => (float) ($stats->open_amount ?? 0),
                'high_risk_count' => (int) ($stats->high_risk_count ?? 0),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'deal_id' => ['required', 'uuid'],
            'refund_amount' => ['required', 'numeric', 'min:0.01', 'max:99999.99'],
            'reason' => ['required', 'in:no_renter_found,service_not_delivered,misrepresentation_claim,owner_changed_mind,duplicate_charge,unauthorized,other'],
            'owner_statement' => ['nullable', 'string', 'max:5000'],
        ]);

        // Confirm the deal belongs to this tenant before we open a
        // case against it. Direct DB::table query because the deal
        // is in a different module.
        $dealOwned = DB::table('deals')
            ->where('id', $request->string('deal_id'))
            ->where('tenant_id', $this->tenantContext->id())
            ->exists();
        if (! $dealOwned) {
            return response()->json(['message' => 'Deal not found'], 404);
        }

        $case = RefundCase::query()->create([
            'deal_id' => (string) $request->string('deal_id'),
            'opened_by' => $request->user()?->id,
            'refund_amount' => (float) $request->input('refund_amount'),
            'reason' => $request->string('reason')->value(),
            'owner_statement' => $request->string('owner_statement')->value() ?: null,
            'status' => RefundCaseStatus::Opened->value,
            'opened_at' => Carbon::now(),
        ]);

        return response()->json([
            'message' => 'Refund case opened.',
            'data' => ['id' => $case->id],
        ], 201);
    }

    public function transition(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'to' => ['required', 'in:investigating,approved,denied,processed,escalated_to_chargeback'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $case = RefundCase::query()->find($id);
        if ($case === null) {
            return response()->json(['message' => 'Refund case not found'], 404);
        }

        $to = $request->string('to')->value();
        $updates = ['status' => $to];

        // Resolved states stamp resolved_at; re-opening doesn't clear it.
        if (in_array($to, ['denied', 'processed', 'escalated_to_chargeback'], true)
            && $case->resolved_at === null) {
            $updates['resolved_at'] = Carbon::now();
        }

        // Notes append to the owner_statement field (cheap for now;
        // a dedicated case_notes table can come later).
        if ($request->filled('notes')) {
            $existing = (string) ($case->owner_statement ?? '');
            $stamp = Carbon::now()->toIso8601String();
            $by = $request->user()?->name ?? 'system';
            $appended = trim($existing
                ."\n\n[{$stamp} · {$by}] "
                .(string) $request->string('notes'));
            $updates['owner_statement'] = $appended;
        }

        $case->forceFill($updates)->save();

        return response()->json([
            'message' => 'Refund case status updated.',
        ]);
    }

    /**
     * @param  object  $r
     * @return array<string, mixed>
     */
    private function reshape(object $r): array
    {
        return [
            'id' => $r->id,
            'deal_id' => $r->deal_id,
            'refund_amount' => (float) $r->refund_amount,
            'reason' => $r->reason,
            'is_high_risk' => in_array($r->reason, [
                RefundReason::MisrepresentationClaim->value,
                RefundReason::Unauthorized->value,
                RefundReason::ServiceNotDelivered->value,
            ], true),
            'owner_statement' => $r->owner_statement,
            'status' => $r->status,
            'opened_at' => $r->opened_at,
            'resolved_at' => $r->resolved_at,
            'owner_id' => $r->owner_id,
            'owner_name' => trim(($r->owner_first ?? '').' '.($r->owner_last ?? ''))
                ?: '(unnamed owner)',
            'opener_name' => trim(($r->opener_first ?? '').' '.($r->opener_last ?? ''))
                ?: null,
            'listing_fee' => isset($r->listing_fee) ? (float) $r->listing_fee : null,
            'agreement_status' => $r->agreement_status ?? null,
        ];
    }
}
