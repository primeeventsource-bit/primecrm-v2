<?php

declare(strict_types=1);

namespace App\Modules\Commission\Application\Services;

use App\Core\Shared\Services\AuditLogService;
use App\Core\Shared\TenantContext;
use App\Modules\Commission\Domain\Events\CommissionEarned;
use App\Modules\Commission\Domain\Events\CommissionReversed;
use App\Modules\Commission\Domain\Models\CommissionAssignment;
use App\Modules\Commission\Domain\Models\CommissionCalculation;
use App\Modules\Commission\Domain\Models\CommissionEvent;
use App\Modules\Commission\Domain\Models\CommissionPlan;
use App\Modules\Commission\Domain\Models\CommissionPlanRule;
use Illuminate\Support\Facades\DB;

/**
 * Turns commission events into commission calculations.
 *
 * Forward path (CommissionEngine::process):
 *   CommissionEvent → for each affected user (via RoleResolver) → for
 *   each applicable rule on their active plan → produce a calculation
 *   row via RuleEvaluator → persist with status=pending or payable
 *   per the rule's config.
 *
 * Reversal path (CommissionEngine::reverseFromEvent):
 *   Find all calculations whose `commission_event_id` is the original
 *   triggering event (e.g. the payment.cleared event whose payment was
 *   later refunded). For each, write a NEW negative-amount calculation
 *   with `is_reversal=true` and `reverses_calculation_id` pointing back.
 *   The original is left untouched — the audit trail wins. Status
 *   transitions on the original (pending→voided) happen in a later
 *   step if the agent hadn't been paid yet; if they had, the negative
 *   row claws back from the next payout.
 */
final class CommissionEngine
{
    public function __construct(
        private readonly RoleResolver $roleResolver,
        private readonly RuleEvaluator $ruleEvaluator,
        private readonly TenantContext $tenantContext,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * Forward: produce calculations for one event.
     *
     * @return list<CommissionCalculation>
     */
    public function process(CommissionEvent $event): array
    {
        $rules = CommissionPlanRule::query()
            ->active()
            ->forEvent($event->event_type)
            ->whereHas('plan', fn ($q) => $q->activeOn(now()->toDateString()))
            ->orderByDesc('priority')
            ->get();

        if ($rules->isEmpty()) {
            return [];
        }

        $calculations = [];

        foreach ($rules as $rule) {
            $recipients = $this->roleResolver->recipientsFor($event, $rule);
            foreach ($recipients as $recipient) {
                if (! $this->isUserAssignedToPlan($recipient->userId, $rule->commission_plan_id)) {
                    continue;
                }

                $row = $this->ruleEvaluator->evaluate($event, $rule, $recipient);
                if ($row === null) {
                    continue;
                }

                $calc = DB::transaction(function () use ($event, $row) {
                    return CommissionCalculation::query()->create([
                        'commission_event_id' => $event->id,
                        'user_id' => $row['user_id'],
                        'commission_plan_rule_id' => $row['commission_plan_rule_id'],
                        'role' => $row['role'],
                        'base_amount' => $row['base_amount'],
                        'rate' => $row['rate'],
                        'amount' => $row['amount'],
                        'currency' => $row['currency'],
                        'explanation' => $row['explanation'],
                        'is_reversal' => false,
                        'status' => $this->initialStatusFor($event),
                        'payable_period' => $this->payablePeriodFor($event),
                    ]);
                });

                $calculations[] = $calc;
                CommissionEarned::dispatch($calc);
            }
        }

        if (! empty($calculations)) {
            $this->audit->record(
                action: 'commission.calculated',
                entityType: 'commission_event',
                entityId: $event->id,
                context: [
                    'calculation_ids' => array_map(fn ($c) => $c->id, $calculations),
                    'total' => array_sum(array_map(fn ($c) => (float) $c->amount, $calculations)),
                ],
            );
        }

        return $calculations;
    }

    /**
     * Reverse all forward calculations tied to a previous event.
     *
     * `$originalEventIdempotencyKey` identifies the upstream event whose
     * effects we're undoing — e.g. the original `payment.cleared` event
     * whose payment was later refunded. The reversal event itself has
     * its own idempotency key so re-running the reversal is a no-op.
     *
     * @return list<CommissionCalculation>
     */
    public function reverseFromEvent(
        CommissionEvent $reversalEvent,
        string $originalEventIdempotencyKey,
    ): array {
        $original = CommissionEvent::query()
            ->where('idempotency_key', $originalEventIdempotencyKey)
            ->first();

        if ($original === null) {
            return [];
        }

        $originalCalcs = CommissionCalculation::query()
            ->where('commission_event_id', $original->id)
            ->where('is_reversal', false)
            ->get();

        if ($originalCalcs->isEmpty()) {
            return [];
        }

        // Skip any that already have an active reversal — re-runs are no-op.
        $alreadyReversed = CommissionCalculation::query()
            ->whereIn('reverses_calculation_id', $originalCalcs->pluck('id')->all())
            ->pluck('reverses_calculation_id')
            ->all();

        $reversals = [];

        foreach ($originalCalcs as $orig) {
            if (in_array($orig->id, $alreadyReversed, true)) {
                continue;
            }

            $reversal = DB::transaction(function () use ($reversalEvent, $orig) {
                $r = CommissionCalculation::query()->create([
                    'commission_event_id' => $reversalEvent->id,
                    'user_id' => $orig->user_id,
                    'commission_plan_rule_id' => $orig->commission_plan_rule_id,
                    'role' => $orig->role,
                    'base_amount' => -1 * (float) $orig->base_amount,
                    'rate' => $orig->rate,
                    'amount' => -1 * (float) $orig->amount,
                    'currency' => $orig->currency,
                    'explanation' => array_merge((array) $orig->explanation, [
                        'reversal_reason' => $reversalEvent->event_type,
                        'reverses_calculation_id' => $orig->id,
                    ]),
                    'is_reversal' => true,
                    'reverses_calculation_id' => $orig->id,
                    'status' => CommissionCalculation::STATUS_PAYABLE,
                    'payable_period' => $this->payablePeriodFor($reversalEvent),
                ]);

                // If the original was still pending (not yet paid out),
                // void it too — the reversal nets but the original line
                // shouldn't sit on a future payout as "pending".
                if ($orig->status === CommissionCalculation::STATUS_PENDING) {
                    $orig->update(['status' => CommissionCalculation::STATUS_VOIDED]);
                }

                return $r;
            });

            $reversals[] = $reversal;
            CommissionReversed::dispatch($reversal, $orig);
        }

        if (! empty($reversals)) {
            $this->audit->record(
                action: 'commission.reversed',
                entityType: 'commission_event',
                entityId: $reversalEvent->id,
                context: [
                    'original_event_id' => $original->id,
                    'reversal_ids' => array_map(fn ($r) => $r->id, $reversals),
                    'total_reversed' => array_sum(array_map(fn ($r) => (float) $r->amount, $reversals)),
                ],
            );
        }

        return $reversals;
    }

    private function isUserAssignedToPlan(string $userId, string $planId): bool
    {
        return CommissionAssignment::query()
            ->forUser($userId)
            ->where('commission_plan_id', $planId)
            ->activeOn(now()->toDateString())
            ->exists();
    }

    /**
     * Some rules pay on event time (deal closed); some pay only on
     * payment clear. The default for `deal.closed_won` is `pending`
     * (waiting for payment); for `payment.cleared` it's `payable`.
     */
    private function initialStatusFor(CommissionEvent $event): string
    {
        return match ($event->event_type) {
            'payment.cleared' => CommissionCalculation::STATUS_PAYABLE,
            default => CommissionCalculation::STATUS_PENDING,
        };
    }

    /**
     * The payable period this calculation rolls up into. Defaults to the
     * first day of the calendar month of the event. Tenants with
     * fortnightly/weekly periods can override via plan config (later).
     */
    private function payablePeriodFor(CommissionEvent $event): string
    {
        return $event->occurred_at?->startOfMonth()->toDateString()
            ?? now()->startOfMonth()->toDateString();
    }

    /**
     * Resolves a user's currently-effective commission plan. Returns null
     * if no active assignment exists.
     */
    public function activePlanFor(string $userId): ?CommissionPlan
    {
        $assignment = CommissionAssignment::query()
            ->forUser($userId)
            ->activeOn(now()->toDateString())
            ->orderByDesc('effective_from')
            ->first();

        return $assignment?->plan;
    }
}
