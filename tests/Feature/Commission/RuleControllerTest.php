<?php

declare(strict_types=1);

use App\Modules\Commission\Domain\Models\CommissionPlanRule;
use App\Support\Enums\UserRole;
use Database\Factories\CommissionPlanFactory;
use Database\Factories\CommissionPlanRuleFactory;

beforeEach(function () {
    $this->actingAsUser(role: UserRole::Supervisor);
});

it('adds a rule to a plan with role-typed config', function () {
    $plan = CommissionPlanFactory::new()->create();

    $response = $this->postJson("/api/commission/plans/{$plan->id}/rules", [
        'role' => 'closer',
        'rule_type' => 'percentage',
        'trigger_event' => 'payment.cleared',
        'config' => ['rate' => 0.08, 'base_field' => 'amount'],
        'priority' => 10,
        'active' => true,
    ]);

    $response->assertCreated()
        ->assertJsonPath('role', 'closer')
        ->assertJsonPath('config.rate', 0.08);

    expect($plan->rules()->count())->toBe(1);
});

it('updates a rule', function () {
    $plan = CommissionPlanFactory::new()->create();
    $rule = CommissionPlanRuleFactory::new()->percentage(0.05)->create([
        'commission_plan_id' => $plan->id,
    ]);

    $this->patchJson("/api/commission/plans/{$plan->id}/rules/{$rule->id}", [
        'config' => ['rate' => 0.10, 'base_field' => 'amount'],
        'priority' => 50,
    ])->assertOk()
        ->assertJsonPath('config.rate', 0.10)
        ->assertJsonPath('priority', 50);
});

it('disables a rule rather than deleting it (FK safety)', function () {
    $plan = CommissionPlanFactory::new()->create();
    $rule = CommissionPlanRuleFactory::new()->percentage(0.08)->create([
        'commission_plan_id' => $plan->id,
    ]);

    $this->deleteJson("/api/commission/plans/{$plan->id}/rules/{$rule->id}")
        ->assertOk()
        ->assertJson(['disabled' => true]);

    // Row preserved — commission_calculations.commission_plan_rule_id
    // is a non-cascading FK and past calcs must still resolve.
    $after = CommissionPlanRule::query()->find($rule->id);
    expect($after)->not->toBeNull()
        ->and((bool) $after->active)->toBeFalse();
});

it('rejects rule writes on a plan from a different tenant', function () {
    // First tenant owns the plan + supervisor that we acted as in beforeEach.
    $plan = CommissionPlanFactory::new()->create();

    // Bind a fresh tenant + its own supervisor; the route is tenant-scoped,
    // so the original plan's id must not resolve here.
    $this->actingAsUser(role: UserRole::Supervisor);

    $this->postJson("/api/commission/plans/{$plan->id}/rules", [
        'role' => 'closer',
        'rule_type' => 'percentage',
        'trigger_event' => 'payment.cleared',
        'config' => ['rate' => 0.10, 'base_field' => 'amount'],
    ])->assertNotFound();
});

it('forbids non-supervisors from writing rules', function () {
    $plan = CommissionPlanFactory::new()->create();
    $this->actingAsUser(role: UserRole::Closer);

    $this->postJson("/api/commission/plans/{$plan->id}/rules", [
        'role' => 'closer',
        'rule_type' => 'percentage',
        'trigger_event' => 'payment.cleared',
        'config' => ['rate' => 0.08],
    ])->assertForbidden();
});
