<?php

declare(strict_types=1);

uses(Tests\TestCase::class);

use App\Modules\Commission\Application\Services\RuleEvaluator;
use App\Modules\Commission\Domain\Models\CommissionEvent;
use App\Modules\Commission\Domain\Models\CommissionPlanRule;
use App\Modules\Commission\Domain\ValueObjects\RoleRecipient;

beforeEach(function () {
    $this->actingAsTenant();
});

it('applies the matching tiered rate to the whole base in flat mode', function () {
    $rule = makeTieredRule([
        ['up_to' => 2000, 'rate' => 0.05],
        ['up_to' => 5000, 'rate' => 0.08],
        ['up_to' => null, 'rate' => 0.12],
    ], marginal: false);

    $event = makeEvent(['amount' => 4500, 'currency' => 'USD']);
    $recipient = new RoleRecipient(CommissionPlanRule::ROLE_CLOSER, 'user-1');

    $result = (new RuleEvaluator)->evaluate($event, $rule, $recipient);

    expect($result)->not->toBeNull();
    expect($result['amount'])->toBe(round(4500 * 0.08, 2));
    expect($result['rate'])->toBe(0.08);
});

it('splits base across brackets in marginal mode', function () {
    $rule = makeTieredRule([
        ['up_to' => 2000, 'rate' => 0.05],
        ['up_to' => 5000, 'rate' => 0.08],
        ['up_to' => null, 'rate' => 0.12],
    ], marginal: true);

    $event = makeEvent(['amount' => 7000, 'currency' => 'USD']);
    $recipient = new RoleRecipient(CommissionPlanRule::ROLE_CLOSER, 'user-1');

    $result = (new RuleEvaluator)->evaluate($event, $rule, $recipient);

    // 2000 × 0.05 + 3000 × 0.08 + 2000 × 0.12 = 100 + 240 + 240 = 580
    expect($result['amount'])->toBe(580.0);
});

it('returns null when base is zero or negative', function () {
    $rule = makeTieredRule([['up_to' => null, 'rate' => 0.10]]);
    $event = makeEvent(['amount' => 0]);
    $recipient = new RoleRecipient(CommissionPlanRule::ROLE_CLOSER, 'u');

    $result = (new RuleEvaluator)->evaluate($event, $rule, $recipient);

    expect($result)->toBeNull();
});

function makeTieredRule(array $brackets, bool $marginal = false): CommissionPlanRule
{
    return CommissionPlanRule::query()->make([
        'tenant_id' => app(\App\Core\Shared\TenantContext::class)->id(),
        'commission_plan_id' => 'plan-x',
        'role' => CommissionPlanRule::ROLE_CLOSER,
        'rule_type' => CommissionPlanRule::TYPE_TIERED,
        'trigger_event' => 'payment.cleared',
        'config' => ['brackets' => $brackets, 'marginal' => $marginal, 'base_field' => 'amount'],
        'priority' => 0,
        'active' => true,
    ])->setAttribute('id', 'rule-x');
}

function makeEvent(array $payload): CommissionEvent
{
    $event = CommissionEvent::query()->make([
        'tenant_id' => app(\App\Core\Shared\TenantContext::class)->id(),
        'event_type' => 'payment.cleared',
        'source_entity_type' => 'Payment',
        'source_entity_id' => 'pay-x',
        'payload' => $payload,
        'idempotency_key' => 'k',
        'occurred_at' => now(),
    ]);
    $event->setAttribute('id', 'evt-x');

    return $event;
}
