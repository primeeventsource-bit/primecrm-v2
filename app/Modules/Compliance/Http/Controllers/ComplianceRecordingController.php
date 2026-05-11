<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Controllers;

use App\Core\Shared\TenantContext;
use App\Modules\Compliance\Domain\Enums\ComplianceStatus;
use App\Modules\Compliance\Domain\Models\ComplianceRecording;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Per-call disclosure-capture review queue.
 *
 * Every sales call that closed a listing fee gets a row here. The
 * reviewer queue surfaces them ordered by urgency: failed > flagged
 * for audit > pending_review > passed. Each row carries the five
 * disclosure flags and the parent call.
 *
 *   GET   /api/compliance/recordings
 *   POST  /api/compliance/recordings/{id}/transition  pass/fail/flag
 *   POST  /api/compliance/recordings/{id}/toggle      flip a marker
 *
 * Toggle exists for the case where a reviewer corrects an automated
 * transcript miss. Transition advances the review status.
 */
final class ComplianceRecordingController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', 'in:pending_review,passed,failed,flagged_for_audit,all'],
            'agent_id' => ['nullable', 'uuid'],
            'q' => ['nullable', 'string', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $tenantId = $this->tenantContext->id();
        $statusFilter = $request->string('status', 'pending_review')->value();
        $agentId = $request->string('agent_id')->value();
        $q = $request->string('q')->value();
        $page = (int) $request->integer('page', 1);
        $perPage = (int) $request->integer('per_page', 25);

        $base = DB::table('compliance_recordings as cr')
            ->leftJoin('calls as c', 'c.id', '=', 'cr.call_id')
            ->leftJoin('leads as l', 'l.id', '=', 'c.lead_id')
            ->leftJoin('deals as d', 'd.id', '=', 'cr.deal_id')
            ->leftJoin('users as agent', 'agent.id', '=', 'cr.user_id')
            ->where('cr.tenant_id', $tenantId);

        if ($statusFilter !== 'all') {
            $base->where('cr.compliance_status', $statusFilter);
        }
        if ($agentId !== '') {
            $base->where('cr.user_id', $agentId);
        }
        if ($q !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';
            $base->where(function ($qq) use ($like): void {
                $qq->where('l.first_name', 'like', $like)
                    ->orWhere('l.last_name', 'like', $like)
                    ->orWhere('agent.first_name', 'like', $like)
                    ->orWhere('agent.last_name', 'like', $like);
            });
        }

        $total = (clone $base)->count();

        // Ordering — failed before flagged, then pending_review, then
        // passed last. We want reviewers focused on what's broken.
        $rows = $base
            ->orderByRaw("
                CASE cr.compliance_status
                    WHEN 'failed' THEN 0
                    WHEN 'flagged_for_audit' THEN 1
                    WHEN 'pending_review' THEN 2
                    WHEN 'passed' THEN 3
                    ELSE 4
                END
            ")
            ->orderByDesc('cr.created_at')
            ->limit($perPage)
            ->offset(($page - 1) * $perPage)
            ->get([
                'cr.id', 'cr.compliance_status', 'cr.created_at',
                'cr.tcpa_consent_captured', 'cr.recording_disclosure_made',
                'cr.no_guarantee_disclosure_made', 'cr.refund_policy_disclosure_made',
                'cr.total_fee_stated_clearly',
                'cr.review_notes', 'cr.reviewed_at',
                'cr.call_id', 'cr.deal_id', 'cr.user_id',
                'c.duration_seconds as call_duration',
                'c.recording_url',
                'l.first_name as owner_first', 'l.last_name as owner_last',
                'l.id as owner_id',
                'd.listing_fee', 'd.agreement_status',
                'agent.first_name as agent_first', 'agent.last_name as agent_last',
            ]);

        // Aggregate stats for the page header
        $stats = DB::table('compliance_recordings')
            ->where('tenant_id', $tenantId)
            ->selectRaw("
                COUNT(*) AS total,
                SUM(CASE WHEN compliance_status = 'pending_review' THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN compliance_status = 'passed' THEN 1 ELSE 0 END) AS passed,
                SUM(CASE WHEN compliance_status = 'failed' THEN 1 ELSE 0 END) AS failed,
                SUM(CASE WHEN compliance_status = 'flagged_for_audit' THEN 1 ELSE 0 END) AS flagged
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
                'total' => (int) ($stats->total ?? 0),
                'pending' => (int) ($stats->pending ?? 0),
                'passed' => (int) ($stats->passed ?? 0),
                'failed' => (int) ($stats->failed ?? 0),
                'flagged' => (int) ($stats->flagged ?? 0),
                'pass_rate' => ($stats->total ?? 0) > 0
                    ? round(((int) ($stats->passed ?? 0)) / ((int) $stats->total), 4)
                    : null,
            ],
        ]);
    }

    public function transition(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'to' => ['required', 'in:passed,failed,flagged_for_audit'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $recording = ComplianceRecording::query()->find($id);
        if ($recording === null) {
            return response()->json(['message' => 'Recording not found'], 404);
        }

        $recording->forceFill([
            'compliance_status' => $request->string('to')->value(),
            'reviewed_by' => $request->user()?->id,
            'reviewed_at' => Carbon::now(),
            'review_notes' => $request->string('notes', $recording->review_notes ?? '')->value(),
        ])->save();

        return response()->json([
            'message' => 'Compliance status updated.',
            'data' => $this->reshape((object) $recording->refresh()->toArray()),
        ]);
    }

    public function toggle(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'field' => ['required', 'in:tcpa_consent_captured,recording_disclosure_made,no_guarantee_disclosure_made,refund_policy_disclosure_made,total_fee_stated_clearly'],
            'value' => ['required', 'boolean'],
        ]);

        $recording = ComplianceRecording::query()->find($id);
        if ($recording === null) {
            return response()->json(['message' => 'Recording not found'], 404);
        }

        $field = (string) $request->string('field');
        $recording->forceFill([$field => $request->boolean('value')])->save();

        return response()->json([
            'message' => 'Disclosure marker updated.',
            'data' => $this->reshape((object) $recording->refresh()->toArray()),
        ]);
    }

    /**
     * @param  object|array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function reshape(object|array $r): array
    {
        $r = is_array($r) ? (object) $r : $r;

        $captures = [
            'tcpa_consent_captured' => (bool) ($r->tcpa_consent_captured ?? false),
            'recording_disclosure_made' => (bool) ($r->recording_disclosure_made ?? false),
            'no_guarantee_disclosure_made' => (bool) ($r->no_guarantee_disclosure_made ?? false),
            'refund_policy_disclosure_made' => (bool) ($r->refund_policy_disclosure_made ?? false),
            'total_fee_stated_clearly' => (bool) ($r->total_fee_stated_clearly ?? false),
        ];
        $missing = array_keys(array_filter($captures, fn ($v) => $v === false));

        return [
            'id' => $r->id,
            'compliance_status' => $r->compliance_status ?? null,
            'call_id' => $r->call_id ?? null,
            'deal_id' => $r->deal_id ?? null,
            'owner_id' => $r->owner_id ?? null,
            'agent_name' => isset($r->agent_first) || isset($r->agent_last)
                ? trim(($r->agent_first ?? '').' '.($r->agent_last ?? '')) ?: '(unknown)'
                : null,
            'owner_name' => isset($r->owner_first) || isset($r->owner_last)
                ? trim(($r->owner_first ?? '').' '.($r->owner_last ?? '')) ?: '(unknown)'
                : null,
            'listing_fee' => isset($r->listing_fee) ? (float) $r->listing_fee : null,
            'agreement_status' => $r->agreement_status ?? null,
            'call_duration' => isset($r->call_duration) ? (int) $r->call_duration : null,
            'recording_url' => $r->recording_url ?? null,
            'captures' => $captures,
            'missing' => $missing,
            'all_captured' => empty($missing),
            'review_notes' => $r->review_notes ?? null,
            'reviewed_at' => $r->reviewed_at ?? null,
            'created_at' => $r->created_at ?? null,
        ];
    }
}
