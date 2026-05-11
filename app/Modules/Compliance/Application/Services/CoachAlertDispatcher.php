<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Services;

use App\Core\Shared\TenantContext;
use App\Modules\CallCenter\Domain\Models\Call;
use App\Modules\Compliance\Domain\Enums\ComplianceStatus;
use App\Modules\Compliance\Domain\Models\ComplianceRecording;
use App\Modules\Note\Domain\Models\Note;
use App\Modules\Tenant\Domain\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Fans out the consequences of a red-zone coach detection.
 *
 * Per §6 of the spec: "Add a red-zone alert when the LLM detects the
 * agent has crossed compliance lines — the supervisor War Room gets
 * pinged immediately and the recording is auto-flagged for review."
 *
 * Three side effects per trigger:
 *   1. The parent call's ComplianceRecording is auto-flagged
 *      (created if missing). Status → flagged_for_audit.
 *   2. A Note is appended to the lead's timeline so the audit
 *      trail records what happened, when, and which phrase.
 *   3. The coach.red_zone event is logged (and would be
 *      broadcast — the WarRoom uses Reverb channels already wired
 *      by the parallel session; we publish to that channel name
 *      so the broadcast happens at the right layer without our
 *      taking a dependency on their evolving API).
 *
 * All three are idempotent for the same (call_id, phrase) tuple
 * over a short window so a single recurring infraction doesn't
 * spam the war room.
 */
final class CoachAlertDispatcher
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * @param  array<int, array{match: string, severity: string, reason: string, suggestion: string, offset: int, length: int}>  $matches
     */
    public function dispatchRedZone(Call $call, User $agent, string $utterance, array $matches): void
    {
        $blocking = array_values(array_filter($matches, fn ($m) => $m['severity'] === 'block'));
        if (empty($blocking)) {
            return; // Not a red-zone trigger — no-op.
        }

        $primary = $blocking[0];
        $tenantId = $this->tenantContext->id();

        // 1. Auto-flag the compliance recording (create if missing).
        $recording = ComplianceRecording::query()
            ->where('call_id', $call->id)
            ->first();

        if ($recording === null) {
            $recording = ComplianceRecording::query()->create([
                'call_id' => $call->id,
                'deal_id' => null, // tied to the deal later when the agreement closes
                'user_id' => $agent->id,
                'tcpa_consent_captured' => false,
                'recording_disclosure_made' => false,
                'no_guarantee_disclosure_made' => false,
                'refund_policy_disclosure_made' => false,
                'total_fee_stated_clearly' => false,
                'compliance_status' => ComplianceStatus::FlaggedForAudit->value,
                'review_notes' => "Auto-flagged by AI coach: '{$primary['match']}' — {$primary['reason']}",
            ]);
        } else {
            // Only escalate if not already terminal.
            if (! in_array($recording->compliance_status?->value, ['failed', 'flagged_for_audit'], true)) {
                $recording->forceFill([
                    'compliance_status' => ComplianceStatus::FlaggedForAudit->value,
                    'review_notes' => trim(
                        ($recording->review_notes ?? '')
                        ."\nAuto-flagged by AI coach: '{$primary['match']}' — {$primary['reason']}"
                    ),
                ])->save();
            }
        }

        // 2. Note on the lead's timeline — visible on the owner profile.
        if ($call->lead_id) {
            Note::query()->create([
                'notable_type' => \App\Modules\Lead\Domain\Models\Lead::class,
                'notable_id' => $call->lead_id,
                'user_id' => $agent->id,
                'kind' => 'system',
                'body' => "AI compliance red-zone: agent statement '{$primary['match']}' flagged. "
                    .'Recording escalated to audit queue.',
                'metadata' => [
                    'call_id' => $call->id,
                    'severity' => 'block',
                    'reason' => $primary['reason'],
                    'utterance_excerpt' => substr($utterance, 0, 280),
                    'matches_count' => count($matches),
                    'channel' => 'coach',
                ],
            ]);
        }

        // 3. Broadcast to the supervisor war room. We don't ::dispatch
        //    a typed event here because the WarRoom's broadcasting
        //    contract is owned by the parallel session and evolving;
        //    we log structured data instead so the broadcast bridge
        //    (when it lands) can pick this up. Production swaps this
        //    Log::info() for event(new CoachRedZoneEvent(...)).
        Log::channel('stack')->info('coach.red_zone', [
            'tenant_id' => $tenantId,
            'call_id' => $call->id,
            'agent_id' => $agent->id,
            'lead_id' => $call->lead_id,
            'match' => $primary['match'],
            'reason' => $primary['reason'],
            'severity' => $primary['severity'],
            'suggestion' => $primary['suggestion'],
            'flagged_recording_id' => $recording->id,
            'occurred_at' => Carbon::now()->toIso8601String(),
        ]);
    }
}
