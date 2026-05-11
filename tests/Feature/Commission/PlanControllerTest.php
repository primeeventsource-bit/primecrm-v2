<?php

declare(strict_types=1);

use App\Modules\Commission\Domain\Models\CommissionPlan;
use App\Modules\Commission\Domain\Models\CommissionPlanRule;
use App\Support\Enums\UserRole;
use Database\Factories\CommissionPlanFactory;
use Database\Factories\CommissionPlanRuleFactory;

beforeEach(function () {
    $this->actingAsUser(role: UserRole::Supervisor);
});

it('lists plans, optionally with their rules', function () {
    $plan = CommissionPlanFactory::new()->create(['name' => 'Test Plan']);
    CommissionPlanRuleFactory::new()->percentage(0.08)->create([
        'commission_plan_id' => $plan->id,
    ]);

    $bare = $this->getJson('/api/commission/plans')->json('data');
    expect($bare)->toHaveCount(1)
        ->and($bare[0])->not->toHaveKey('rules');

    $withRules = $this->getJson('/api/commission/plans?with_rules=1')->json('data');
    expect($withRules[0]['rules'])->toHaveCount(1)
        ->and($withRules[0]['rules'][0]['rule_type'])->toBe('percentage');
});

it('creates a plan with name, dates, and active flag', function () {
    $response = $this->postJson('/api/commission/plans', [
        'name' => 'New Plan',
        'description' => 'Created via API',
        'effective_from' => '2026-05-01',
        'effective_to' => '2026-12-31',
        'active' => true,
    ]);

    $response->assertCreated()
        ->assertJsonPath('name', 'New Plan')
        ->assertJsonPath('active', true);

    expect(CommissionPlan::query()->where('name', 'New Plan')->exists())->toBeTrue();
});

it('updates a plan in place', function () {
    $plan = CommissionPlanFactory::new()->create(['name' => 'Old', 'active' => true]);

    $this->patchJson("/api/commission/plans/{$plan->id}", [
        'name' => 'Renamed',
        'active' => false,
    ])->assertOk();

    $fresh = $plan->fresh();
    expect($fresh->name)->toBe('Renamed')
        ->and((bool) $fresh->active)->toBeFalse();
});

it('archives a plan and disables its rules without breaking past calc FKs', function () {
    $plan = CommissionPlanFactory::new()->create();
    $rule = CommissionPlanRuleFactory::new()->percentage(0.08)->create([
        'commission_plan_id' => $plan->id,
    ]);

    $this->deleteJson("/api/commission/plans/{$plan->id}")
        ->assertOk()
        ->assertJson(['archived' => true]);

    // Plan is soft-deleted (not visible to default scope) and the rule
    // is preserved but flipped inactive so commission_calculations'
    // non-cascading FK to it stays intact.
    expect(CommissionPlan::query()->find($plan->id))->toBeNull()
        ->and(CommissionPlan::query()->withTrashed()->find($plan->id))->not->toBeNull();

    $ruleAfter = CommissionPlanRule::query()->find($rule->id);
    expect($ruleAfter)->not->toBeNull()
        ->and((bool) $ruleAfter->active)->toBeFalse();
});

it('forbids non-supervisors from writing plans', function () {
    $this->actingAsUser(role: UserRole::Closer);

    $this->postJson('/api/commission/plans', [
        'name' => 'Forbidden',
        'effective_from' => '2026-05-01',
    ])->assertForbidden();
});
