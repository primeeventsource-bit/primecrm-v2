<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Services;

use App\Core\Shared\Services\AuditLogService;
use App\Modules\Compliance\Domain\Enums\GuardrailRejectionCode;
use App\Modules\Compliance\Domain\ValueObjects\GuardrailDecision;
use App\Modules\Lead\Domain\Events\LeadRejectedByCompliance;
use App\Modules\Lead\Domain\Models\Lead;
use App\Support\Enums\LeadStatus;

/**
 * The pre-dial pipeline. Single chokepoint between "the system thinks
 * this lead is dialable" and "actually hand it to the dialer".
 *
 * Contract:
 *   $guardrail->mayDial($lead, $dialerMode = 'predictive')
 *     → GuardrailDecision (allow | reject)
 *
 * The dialer (DialLeadJob in Response 3) calls this and aborts on rejection.
 * The check pipeline runs in this order — each gate is independent and
 * failure is final:
 *
 *   1. Lead state guard (terminal, on-DNC flag)        — cheap, no I/O
 *   2. DNC check (federal/state/wireless/internal)    — DB hit
 *   3. Consent check (if dialer mode requires)        — DB hit
 *   4. Frequency cap (cooldown + daily + monthly)     — DB hit
 *   5. Calling window (timezone + jurisdiction)       — cached DB hit
 *
 * Total tail latency target: <50ms p99. Each individual check is bounded
 * (single indexed lookup or cached read).
 *
 * Rejections are written to the audit log and emit LeadRejectedByCompliance
 * so subscribers (the dialer's lead queue, the supervisor dashboard) can
 * react. Rejections do NOT mutate the lead's status — that would be a
 * separate operator decision (e.g. flipping a lead to LeadStatus::Dnc on
 * customer request).
 *
 * Idempotent: calling mayDial() multiple times for the same lead yields
 * the same decision until the underlying state changes (consent recorded,
 * DNC entry added, attempt logged, time of day passes a window edge).
 */
final class ComplianceGuardrailService
{
    public function __construct(
        private readonly DncCheckService $dncCheck,
        private readonly ConsentCheckService $consentCheck,
        private readonly FrequencyCapService $frequencyCheck,
        private readonly CallingWindowService $callingWindowCheck,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * @param  'manual'|'preview'|'progressive'|'predictive'  $dialerMode
     */
    public function mayDial(Lead $lead, string $dialerMode = 'predictive'): GuardrailDecision
    {
        // Gate 0: lead state. Cheapest reject — no I/O.
        if ($lead->is_on_dnc) {
            return $this->reject($lead, GuardrailDecision::reject(
                code: GuardrailRejectionCode::LeadOnDncFlag,
                reason: 'Lead is flagged as on DNC.',
            ));
        }

        if ($lead->status instanceof LeadStatus && $lead->status->isTerminal()) {
            return $this->reject($lead, GuardrailDecision::reject(
                code: GuardrailRejectionCode::LeadStatusTerminal,
                reason: "Lead status is terminal: {$lead->status->value}.",
                metadata: ['status' => $lead->status->value],
            ));
        }

        if (empty($lead->phone) || empty($lead->phone_hash)) {
            return $this->reject($lead, GuardrailDecision::reject(
                code: GuardrailRejectionCode::BadNumber,
                reason: 'Lead has no usable phone number.',
            ));
        }

        // Gate 1: DNC.
        $dnc = $this->dncCheck->check($lead->phone_hash);
        if ($dnc->isRejected()) {
            return $this->reject($lead, $dnc);
        }

        // Gate 2: consent (gated by dialer mode internally).
        $consent = $this->consentCheck->check($lead, $dialerMode);
        if ($consent->isRejected()) {
            return $this->reject($lead, $consent);
        }

        // Gate 3: frequency cap.
        $frequency = $this->frequencyCheck->check($lead->phone_hash, $lead->timezone);
        if ($frequency->isRejected()) {
            return $this->reject($lead, $frequency);
        }

        // Gate 4: calling window.
        $window = $this->callingWindowCheck->check($lead);
        if ($window->isRejected()) {
            return $this->reject($lead, $window);
        }

        return GuardrailDecision::allow([
            'dnc' => $dnc->metadata,
            'consent' => $consent->metadata,
            'frequency' => $frequency->metadata,
            'window' => $window->metadata,
            'dialer_mode' => $dialerMode,
        ]);
    }

    /**
     * Public read-only lookup for UI/diagnostics. Doesn't fire events or
     * write audit logs — purely informational so the agent screen can
     * surface "this lead is not dialable because…" without polluting logs.
     */
    public function explainFor(Lead $lead, string $dialerMode = 'predictive'): GuardrailDecision
    {
        if ($lead->is_on_dnc) {
            return GuardrailDecision::reject(
                code: GuardrailRejectionCode::LeadOnDncFlag,
                reason: 'Lead is flagged as on DNC.',
            );
        }

        if ($lead->status instanceof LeadStatus && $lead->status->isTerminal()) {
            return GuardrailDecision::reject(
                code: GuardrailRejectionCode::LeadStatusTerminal,
                reason: "Lead status is terminal: {$lead->status->value}.",
                metadata: ['status' => $lead->status->value],
            );
        }

        if (empty($lead->phone) || empty($lead->phone_hash)) {
            return GuardrailDecision::reject(
                code: GuardrailRejectionCode::BadNumber,
                reason: 'Lead has no usable phone number.',
            );
        }

        foreach ([
            $this->dncCheck->check($lead->phone_hash),
            $this->consentCheck->check($lead, $dialerMode),
            $this->frequencyCheck->check($lead->phone_hash, $lead->timezone),
            $this->callingWindowCheck->check($lead),
        ] as $decision) {
            if ($decision->isRejected()) {
                return $decision;
            }
        }

        return GuardrailDecision::allow();
    }

    private function reject(Lead $lead, GuardrailDecision $decision): GuardrailDecision
    {
        $this->audit->record(
            action: 'compliance.guardrail_rejected',
            entityType: 'lead',
            entityId: $lead->id,
            context: [
                'rejection_code' => $decision->rejectionCode?->value,
                'category' => $decision->category(),
                'reason' => $decision->reason,
                'metadata' => $decision->metadata,
            ],
        );

        LeadRejectedByCompliance::dispatch(
            $lead,
            $decision->rejectionCode?->value ?? 'unknown',
            $decision->reason ?? 'Compliance rejection',
        );

        return $decision;
    }
}
