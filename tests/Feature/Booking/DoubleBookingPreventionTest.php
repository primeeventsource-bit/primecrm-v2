<?php

declare(strict_types=1);

use App\Modules\Booking\Application\Services\HoldService;
use App\Modules\Booking\Domain\Exceptions\InventoryUnavailableException;
use App\Modules\Booking\Domain\Models\InventoryAvailability;
use App\Modules\Booking\Domain\Models\InventoryHold;
use Database\Factories\InventoryAvailabilityFactory;
use Database\Factories\UserFactory;

beforeEach(function () {
    $this->actingAsTenant();
});

it('successfully holds an available unit', function () {
    $availability = InventoryAvailabilityFactory::new()->create();
    $agent = UserFactory::new()->closer()->create();

    $hold = app(HoldService::class)->hold($availability, heldByUserId: $agent->id);

    expect($hold)->toBeInstanceOf(InventoryHold::class);
    expect($hold->released_at)->toBeNull();
    expect($availability->fresh()->status)->toBe(InventoryAvailability::STATUS_HELD);
    expect($availability->fresh()->current_hold_id)->toBe($hold->id);
});

it('refuses to double-hold the same week (the structural property)', function () {
    $availability = InventoryAvailabilityFactory::new()->create();
    $agentA = UserFactory::new()->closer()->create();
    $agentB = UserFactory::new()->closer()->create();

    app(HoldService::class)->hold($availability, heldByUserId: $agentA->id);

    expect(fn () => app(HoldService::class)->hold($availability, heldByUserId: $agentB->id))
        ->toThrow(InventoryUnavailableException::class);
});

it('lets the second agent hold after the first releases', function () {
    $availability = InventoryAvailabilityFactory::new()->create();
    $agentA = UserFactory::new()->closer()->create();
    $agentB = UserFactory::new()->closer()->create();

    $service = app(HoldService::class);
    $first = $service->hold($availability, heldByUserId: $agentA->id);
    $service->release($first, InventoryHold::REASON_AGENT_RELEASED);

    $second = $service->hold($availability->fresh(), heldByUserId: $agentB->id);

    expect($second->held_by_id)->toBe($agentB->id);
    expect($availability->fresh()->status)->toBe(InventoryAvailability::STATUS_HELD);
    expect($availability->fresh()->current_hold_id)->toBe($second->id);
});

it('expires stale holds and frees the unit', function () {
    $availability = InventoryAvailabilityFactory::new()->create();
    $agent = UserFactory::new()->closer()->create();

    $service = app(HoldService::class);
    $hold = $service->hold($availability, heldByUserId: $agent->id);

    // Backdate the expiry by writing directly — the trait's TenantScope
    // means we still scope to tenant.
    $hold->update(['expires_at' => now()->subMinute()]);

    $count = $service->expireStale();

    expect($count)->toBeGreaterThanOrEqual(1);
    expect($availability->fresh()->status)->toBe(InventoryAvailability::STATUS_AVAILABLE);
    expect($availability->fresh()->current_hold_id)->toBeNull();
    expect($hold->fresh()->release_reason)->toBe(InventoryHold::REASON_EXPIRED);
});

it('cannot hold a unit that is already booked', function () {
    $availability = InventoryAvailabilityFactory::new()->booked()->create();
    $agent = UserFactory::new()->closer()->create();

    expect(fn () => app(HoldService::class)->hold($availability, heldByUserId: $agent->id))
        ->toThrow(InventoryUnavailableException::class);
});
