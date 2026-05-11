<?php

declare(strict_types=1);

use App\Modules\Commission\Domain\Models\CommissionAssignment;
use App\Modules\Tenant\Domain\Models\User;
use App\Support\Enums\UserRole;
use Database\Factories\CommissionPlanFactory;

/*
 * Covers the compensation + commission additions on AgentController.
 * The commission engine itself is tested elsewhere; here we only care
 * that the agent endpoint persists pay fields and reconciles the
 * commission_assignments row correctly.
 */

beforeEach(function () {
    $this->actingAsUser(role: UserRole::Supervisor);
});

it('persists a full compensation package on store', function () {
    $plan = CommissionPlanFactory::new()->create();

    $response = $this->postJson('/api/agents', [
        'first_name' => 'Maya',
        'last_name' => 'Ortiz',
        'email' => 'maya@demo.test',
        'password' => 'password',
        'role' => UserRole::Closer->value,
        'pay_type' => 'hourly',
        'base_rate' => 18.50,
        'pay_currency' => 'USD',
        'pay_notes' => '$18.50/hr + 6% commission',
        'commission_plan_id' => $plan->id,
        'commission_override_rate' => 12,
    ]);

    $response->assertCreated();
    $user = User::query()->where('email', 'maya@demo.test')->firstOrFail();
    expect($user->pay_type)->toBe('hourly')
        ->and($user->base_rate_cents)->toBe(1850)
        ->and($user->pay_currency)->toBe('USD')
        ->and($user->pay_notes)->toBe('$18.50/hr + 6% commission');

    $assignment = CommissionAssignment::query()->forUser($user->id)->firstOrFail();
    expect($assignment->commission_plan_id)->toBe($plan->id)
        ->and($assignment->effective_to)->toBeNull()
        ->and($assignment->overrides['rate_pct'])->toBe(12.0);
});

it('clears base_rate when pay_type is commission_only', function () {
    $response = $this->postJson('/api/agents', [
        'first_name' => 'Pure',
        'last_name' => 'Commission',
        'email' => 'pure@demo.test',
        'password' => 'password',
        'role' => UserRole::Closer->value,
        'pay_type' => 'commission_only',
        'base_rate' => 25.00, // should be ignored / nulled
    ]);

    $response->assertCreated();
    $user = User::query()->where('email', 'pure@demo.test')->firstOrFail();
    expect($user->pay_type)->toBe('commission_only')
        ->and($user->base_rate_cents)->toBeNull();
});

it('omits the assignment when no plan is picked', function () {
    $response = $this->postJson('/api/agents', [
        'first_name' => 'No',
        'last_name' => 'Plan',
        'email' => 'noplan@demo.test',
        'password' => 'password',
        'role' => UserRole::Fronter->value,
    ]);

    $response->assertCreated();
    $user = User::query()->where('email', 'noplan@demo.test')->firstOrFail();
    expect(CommissionAssignment::query()->forUser($user->id)->count())->toBe(0);
});

it('rolls plan changes forward — ends the old assignment and starts a new one', function () {
    $oldPlan = CommissionPlanFactory::new()->create();
    $newPlan = CommissionPlanFactory::new()->create();

    $user = User::query()->create([
        'first_name' => 'Roll', 'last_name' => 'Forward',
        'email' => 'roll@demo.test',
        'password' => bcrypt('password'),
        'role' => UserRole::Closer->value,
        'status' => 'active',
        'timezone' => 'America/New_York',
        'is_panama_based' => false,
        'pay_type' => 'commission_only',
    ]);

    CommissionAssignment::query()->create([
        'user_id' => $user->id,
        'commission_plan_id' => $oldPlan->id,
        'effective_from' => now()->subMonth()->toDateString(),
        'effective_to' => null,
    ]);

    $this->patchJson("/api/agents/{$user->id}", [
        'commission_plan_id' => $newPlan->id,
        'commission_override_rate' => 9,
    ])->assertOk();

    $assignments = CommissionAssignment::query()->forUser($user->id)->orderBy('effective_from')->get();
    expect($assignments)->toHaveCount(2);

    // Old one is ended (effective_to is yesterday).
    expect($assignments[0]->commission_plan_id)->toBe($oldPlan->id)
        ->and($assignments[0]->effective_to?->toDateString())->toBe(now()->subDay()->toDateString());

    // New one is open-ended and carries the override.
    expect($assignments[1]->commission_plan_id)->toBe($newPlan->id)
        ->and($assignments[1]->effective_to)->toBeNull()
        ->and($assignments[1]->overrides['rate_pct'])->toBe(9.0);
});

it('patches the override on the same plan without creating a new row', function () {
    $plan = CommissionPlanFactory::new()->create();

    $user = User::query()->create([
        'first_name' => 'Same', 'last_name' => 'Plan',
        'email' => 'same@demo.test',
        'password' => bcrypt('password'),
        'role' => UserRole::Closer->value,
        'status' => 'active',
        'timezone' => 'America/New_York',
        'is_panama_based' => false,
        'pay_type' => 'commission_only',
    ]);

    CommissionAssignment::query()->create([
        'user_id' => $user->id,
        'commission_plan_id' => $plan->id,
        'effective_from' => now()->subMonth()->toDateString(),
        'effective_to' => null,
        'overrides' => ['rate_pct' => 8.0],
    ]);

    $this->patchJson("/api/agents/{$user->id}", [
        'commission_plan_id' => $plan->id,
        'commission_override_rate' => 11,
    ])->assertOk();

    $assignments = CommissionAssignment::query()->forUser($user->id)->get();
    expect($assignments)->toHaveCount(1)
        ->and($assignments[0]->overrides['rate_pct'])->toBe(11.0);
});

it('clears the assignment when commission_plan_id is set to null', function () {
    $plan = CommissionPlanFactory::new()->create();

    $user = User::query()->create([
        'first_name' => 'Clear', 'last_name' => 'Plan',
        'email' => 'clear@demo.test',
        'password' => bcrypt('password'),
        'role' => UserRole::Closer->value,
        'status' => 'active',
        'timezone' => 'America/New_York',
        'is_panama_based' => false,
        'pay_type' => 'commission_only',
    ]);

    CommissionAssignment::query()->create([
        'user_id' => $user->id,
        'commission_plan_id' => $plan->id,
        'effective_from' => now()->subMonth()->toDateString(),
        'effective_to' => null,
    ]);

    $this->patchJson("/api/agents/{$user->id}", [
        'commission_plan_id' => null,
    ])->assertOk();

    // The assignment row is preserved for history; just bounded out.
    $assignments = CommissionAssignment::query()->forUser($user->id)->get();
    expect($assignments)->toHaveCount(1)
        ->and($assignments[0]->effective_to?->toDateString())->toBe(now()->subDay()->toDateString());

    // And nothing active today.
    $active = CommissionAssignment::query()->forUser($user->id)->activeOn(now()->toDateString())->count();
    expect($active)->toBe(0);
});

it('forbids non-supervisors from creating agents', function () {
    $this->actingAsUser(role: UserRole::Closer);

    $this->postJson('/api/agents', [
        'first_name' => 'Naughty', 'last_name' => 'Closer',
        'email' => 'naughty@demo.test',
        'password' => 'password',
        'role' => UserRole::Closer->value,
    ])->assertForbidden();
});
