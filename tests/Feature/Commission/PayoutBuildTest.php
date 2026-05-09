<?php

declare(strict_types=1);

use App\Modules\Commission\Application\Services\PayoutService;
use App\Modules\Commission\Domain\Models\CommissionAdjustment;
use App\Modules\Commission\Domain\Models\CommissionCalculation;
use App\Modules\Commission\Domain\Models\CommissionPayout;
use Database\Factories\CommissionPlanFactory;
use Database\Factories\CommissionPlanRuleFactory;
use Database\Factories\UserFactory;

beforeEach(function () {
    $this->actingAsTenant();
});

it('rolls up earnings, reversals, and adjustments into a payout', function () {
    $user = UserFactory::new()->closer()->create();
    $rule = CommissionPlanRuleFactory::new()->percentage(0.10)->create();

    $event = createEvent('evt-1');
    $reversalEvent = createEvent('evt-2');

    // 3 earnings ($300 + $200 + $500), 1 reversal (−$200), 1 adjustment (+$50)
    CommissionCalculation::query()->create(baseRow($user->id, $rule->id, $event->id, 300, false, '2026-05-01'));
    CommissionCalculation::query()->create(baseRow($user->id, $rule->id, $event->id, 200, false, '2026-05-01'));
    CommissionCalculation::query()->create(baseRow($user->id, $rule->id, $event->id, 500, false, '2026-05-01'));
    CommissionCalculation::query()->create(baseRow($user->id, $rule->id, $reversalEvent->id, -200, true, '2026-05-01'));

    CommissionAdjustment::query()->create([
        'user_id' => $user->id,
        'amount' => 50,
        'reason' => 'spiff',
        'created_by_id' => $user->id,
        'payable_period' => '2026-05-15',
    ]);

    $payout = app(PayoutService::class)->buildForPeriod(
        userId: $user->id,
        periodStart: '2026-05-01',
        periodEnd: '2026-05-31',
    );

    expect((float) $payout->total_earned)->toBe(1000.0);
    expect((float) $payout->total_reversed)->toBe(200.0);
    expect((float) $payout->total_adjustments)->toBe(50.0);
    expect((float) $payout->net_payable)->toBe(850.0);
    expect($payout->status)->toBe(CommissionPayout::STATUS_DRAFT);
});

it('approval moves payout to approved status', function () {
    $user = UserFactory::new()->closer()->create();
    $supervisor = UserFactory::new()->supervisor()->create();
    $payout = app(PayoutService::class)->buildForPeriod($user->id, '2026-05-01', '2026-05-31');

    $approved = app(PayoutService::class)->approve($payout, $supervisor->id);

    expect($approved->status)->toBe(CommissionPayout::STATUS_APPROVED);
    expect($approved->approved_by_id)->toBe($supervisor->id);
    expect($approved->approved_at)->not->toBeNull();
});

function createEvent(string $key): \App\Modules\Commission\Domain\Models\CommissionEvent
{
    return \App\Modules\Commission\Domain\Models\CommissionEvent::query()->create([
        'event_type' => 'payment.cleared',
        'source_entity_type' => 'Payment',
        'source_entity_id' => $key,
        'payload' => ['amount' => 1000],
        'idempotency_key' => $key,
        'occurred_at' => now(),
    ]);
}

function baseRow(string $userId, string $ruleId, string $eventId, float $amount, bool $isReversal, string $period): array
{
    return [
        'commission_event_id' => $eventId,
        'user_id' => $userId,
        'commission_plan_rule_id' => $ruleId,
        'role' => 'closer',
        'base_amount' => abs($amount) * 10,
        'rate' => 0.10,
        'amount' => $amount,
        'currency' => 'USD',
        'explanation' => [],
        'is_reversal' => $isReversal,
        'status' => 'payable',
        'payable_period' => $period,
    ];
}
