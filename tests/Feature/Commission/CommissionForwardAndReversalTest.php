<?php

declare(strict_types=1);

use App\Modules\Commission\Application\Services\CommissionEngine;
use App\Modules\Commission\Application\Services\CommissionEventLog;
use App\Modules\Commission\Domain\Models\CommissionCalculation;
use App\Modules\Commission\Domain\Models\CommissionPlanRule;
use Database\Factories\CommissionAssignmentFactory;
use Database\Factories\CommissionPlanFactory;
use Database\Factories\CommissionPlanRuleFactory;
use Database\Factories\DealFactory;
use Database\Factories\LeadFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\UserFactory;

beforeEach(function () {
    $this->actingAsTenant();
});

it('produces a percentage commission calc for the closer on payment.cleared', function () {
    $closer = UserFactory::new()->closer()->create();
    $lead = LeadFactory::new()->create();
    $deal = DealFactory::new()->create([
        'agent_id' => $closer->id,
        'lead_id' => $lead->id,
        'total_value' => 5000,
        'payable_amount' => 5000,
    ]);
    $payment = PaymentFactory::new()->cleared()->create([
        'amount' => 5000,
        'deal_id' => $deal->id,
        'lead_id' => $lead->id,
    ]);

    $plan = CommissionPlanFactory::new()->create();
    CommissionAssignmentFactory::new()->create([
        'user_id' => $closer->id,
        'commission_plan_id' => $plan->id,
    ]);
    $rule = CommissionPlanRuleFactory::new()
        ->percentage(0.10) // 10%
        ->create([
            'commission_plan_id' => $plan->id,
            'role' => CommissionPlanRule::ROLE_CLOSER,
            'trigger_event' => 'payment.cleared',
        ]);

    $event = app(CommissionEventLog::class)->append(
        eventType: 'payment.cleared',
        sourceEntityType: \App\Modules\Payment\Domain\Models\Payment::class,
        sourceEntityId: $payment->id,
        payload: [
            'payment_id' => $payment->id,
            'deal_id' => $deal->id,
            'amount' => 5000.0,
            'currency' => 'USD',
        ],
        idempotencyKey: "payment.cleared:{$payment->id}",
    );

    $calcs = app(CommissionEngine::class)->process($event);

    expect($calcs)->toHaveCount(1);
    expect((float) $calcs[0]->amount)->toBe(500.0);
    expect((float) $calcs[0]->base_amount)->toBe(5000.0);
    expect((float) $calcs[0]->rate)->toBe(0.1);
    expect($calcs[0]->user_id)->toBe($closer->id);
    expect($calcs[0]->status)->toBe(CommissionCalculation::STATUS_PAYABLE);
});

it('writes a NEGATIVE reversal calc on refund without mutating the original', function () {
    $closer = UserFactory::new()->closer()->create();
    $lead = LeadFactory::new()->create();
    $deal = DealFactory::new()->create([
        'agent_id' => $closer->id,
        'lead_id' => $lead->id,
        'total_value' => 5000,
        'payable_amount' => 5000,
    ]);
    $payment = PaymentFactory::new()->cleared()->create([
        'amount' => 5000,
        'deal_id' => $deal->id,
        'lead_id' => $lead->id,
    ]);

    $plan = CommissionPlanFactory::new()->create();
    CommissionAssignmentFactory::new()->create([
        'user_id' => $closer->id,
        'commission_plan_id' => $plan->id,
    ]);
    CommissionPlanRuleFactory::new()
        ->percentage(0.10)
        ->create([
            'commission_plan_id' => $plan->id,
            'role' => CommissionPlanRule::ROLE_CLOSER,
            'trigger_event' => 'payment.cleared',
        ]);

    $log = app(CommissionEventLog::class);
    $engine = app(CommissionEngine::class);

    // Forward
    $forwardEvent = $log->append(
        eventType: 'payment.cleared',
        sourceEntityType: \App\Modules\Payment\Domain\Models\Payment::class,
        sourceEntityId: $payment->id,
        payload: ['payment_id' => $payment->id, 'deal_id' => $deal->id, 'amount' => 5000.0, 'currency' => 'USD'],
        idempotencyKey: "payment.cleared:{$payment->id}",
    );
    $original = $engine->process($forwardEvent)[0];

    // Reversal
    $refundId = \Ramsey\Uuid\Uuid::uuid7()->toString();
    $reversalEvent = $log->append(
        eventType: 'payment.refunded',
        sourceEntityType: \App\Modules\Payment\Domain\Models\Payment::class,
        sourceEntityId: $refundId,
        payload: ['refund_payment_id' => $refundId, 'original_payment_id' => $payment->id, 'amount' => 5000.0, 'currency' => 'USD'],
        idempotencyKey: "payment.refunded:{$refundId}",
    );
    $reversals = $engine->reverseFromEvent($reversalEvent, "payment.cleared:{$payment->id}");

    expect($reversals)->toHaveCount(1);
    expect((float) $reversals[0]->amount)->toBe(-500.0);
    expect($reversals[0]->is_reversal)->toBeTrue();
    expect($reversals[0]->reverses_calculation_id)->toBe($original->id);
    expect($reversals[0]->user_id)->toBe($closer->id);

    // Original is preserved (not deleted, not mutated except status).
    $originalFresh = CommissionCalculation::query()->find($original->id);
    expect((float) $originalFresh->amount)->toBe(500.0); // amount untouched
    expect($originalFresh->is_reversal)->toBeFalse();
});

it('is idempotent on repeated reversal calls', function () {
    $closer = UserFactory::new()->closer()->create();
    $deal = DealFactory::new()->create(['agent_id' => $closer->id]);
    $payment = PaymentFactory::new()->cleared()->create(['amount' => 1000, 'deal_id' => $deal->id]);

    $plan = CommissionPlanFactory::new()->create();
    CommissionAssignmentFactory::new()->create(['user_id' => $closer->id, 'commission_plan_id' => $plan->id]);
    CommissionPlanRuleFactory::new()
        ->percentage(0.10)
        ->create([
            'commission_plan_id' => $plan->id,
            'role' => CommissionPlanRule::ROLE_CLOSER,
            'trigger_event' => 'payment.cleared',
        ]);

    $log = app(CommissionEventLog::class);
    $engine = app(CommissionEngine::class);

    $forward = $log->append(
        eventType: 'payment.cleared',
        sourceEntityType: \App\Modules\Payment\Domain\Models\Payment::class,
        sourceEntityId: $payment->id,
        payload: ['payment_id' => $payment->id, 'deal_id' => $deal->id, 'amount' => 1000.0, 'currency' => 'USD'],
        idempotencyKey: "payment.cleared:{$payment->id}",
    );
    $engine->process($forward);

    // Two reversal events — but with DIFFERENT idempotency keys, so both
    // get logged. The engine should still only produce ONE reversal calc
    // per original calc (the second time, the original is already
    // reversed).
    $r1 = $log->append(
        eventType: 'payment.refunded',
        sourceEntityType: \App\Modules\Payment\Domain\Models\Payment::class,
        sourceEntityId: 'ref-1',
        payload: [],
        idempotencyKey: "payment.refunded:ref-1",
    );
    $r2 = $log->append(
        eventType: 'payment.refunded',
        sourceEntityType: \App\Modules\Payment\Domain\Models\Payment::class,
        sourceEntityId: 'ref-2',
        payload: [],
        idempotencyKey: "payment.refunded:ref-2",
    );

    $engine->reverseFromEvent($r1, "payment.cleared:{$payment->id}");
    $engine->reverseFromEvent($r2, "payment.cleared:{$payment->id}");

    $reversals = CommissionCalculation::query()->where('is_reversal', true)->get();
    expect($reversals)->toHaveCount(1);
});

it('does not produce calculations for users without an active assignment', function () {
    $unassignedCloser = UserFactory::new()->closer()->create();
    $deal = DealFactory::new()->create(['agent_id' => $unassignedCloser->id, 'total_value' => 1000]);
    $payment = PaymentFactory::new()->cleared()->create(['amount' => 1000, 'deal_id' => $deal->id]);

    $plan = CommissionPlanFactory::new()->create();
    // No assignment for $unassignedCloser
    CommissionPlanRuleFactory::new()
        ->percentage(0.10)
        ->create([
            'commission_plan_id' => $plan->id,
            'role' => CommissionPlanRule::ROLE_CLOSER,
            'trigger_event' => 'payment.cleared',
        ]);

    $event = app(CommissionEventLog::class)->append(
        eventType: 'payment.cleared',
        sourceEntityType: \App\Modules\Payment\Domain\Models\Payment::class,
        sourceEntityId: $payment->id,
        payload: ['payment_id' => $payment->id, 'deal_id' => $deal->id, 'amount' => 1000.0, 'currency' => 'USD'],
        idempotencyKey: "payment.cleared:{$payment->id}",
    );
    $calcs = app(CommissionEngine::class)->process($event);

    expect($calcs)->toBeEmpty();
});
