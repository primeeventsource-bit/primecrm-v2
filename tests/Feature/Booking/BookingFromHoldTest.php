<?php

declare(strict_types=1);

use App\Modules\Booking\Application\Services\BookingService;
use App\Modules\Booking\Application\Services\HoldService;
use App\Modules\Booking\Domain\Models\Booking;
use App\Modules\Booking\Domain\Models\InventoryAvailability;
use App\Modules\Booking\Domain\Models\InventoryHold;
use Database\Factories\InventoryAvailabilityFactory;
use Database\Factories\LeadFactory;
use Database\Factories\UserFactory;

beforeEach(function () {
    $this->actingAsTenant();
});

it('confirms a booking from an active hold', function () {
    $availability = InventoryAvailabilityFactory::new()->create(['current_price' => 1850.00]);
    $agent = UserFactory::new()->closer()->create();
    $lead = LeadFactory::new()->create();

    $hold = app(HoldService::class)->hold(
        $availability,
        heldByUserId: $agent->id,
        leadId: $lead->id,
    );

    $booking = app(BookingService::class)->confirm($hold->fresh(), [
        'guest_details' => ['primary_name' => 'Jane Guest', 'guests' => 4],
    ]);

    expect($booking)->toBeInstanceOf(Booking::class);
    expect($booking->status)->toBe(Booking::STATUS_CONFIRMED);
    expect($booking->confirmation_number)->toStartWith('BK-');
    expect((float) $booking->total_price)->toBe(1850.00);
    expect($booking->lead_id)->toBe($lead->id);

    expect($availability->fresh()->status)->toBe(InventoryAvailability::STATUS_BOOKED);
    expect($availability->fresh()->booking_id)->toBe($booking->id);
    expect($hold->fresh()->release_reason)->toBe(InventoryHold::REASON_CONVERTED);
});

it('cancellation frees the unit and creates a fresh available row', function () {
    $availability = InventoryAvailabilityFactory::new()->create();
    $agent = UserFactory::new()->closer()->create();
    $lead = LeadFactory::new()->create();

    $hold = app(HoldService::class)->hold($availability, heldByUserId: $agent->id, leadId: $lead->id);
    $booking = app(BookingService::class)->confirm($hold->fresh());

    $cancelled = app(BookingService::class)->cancel($booking, 'customer_request');

    expect($cancelled->status)->toBe(Booking::STATUS_CANCELLED);
    expect($cancelled->cancellation_reason)->toBe('customer_request');

    // The original row was marked cancelled; a fresh "available" row exists
    // with the same (unit, dates).
    $rows = InventoryAvailability::query()
        ->where('inventory_unit_id', $availability->inventory_unit_id)
        ->where('check_in_date', $availability->check_in_date->toDateString())
        ->orderBy('created_at')
        ->get();

    expect($rows)->toHaveCount(2);
    expect($rows[0]->status)->toBe(InventoryAvailability::STATUS_CANCELLED);
    expect($rows[1]->status)->toBe(InventoryAvailability::STATUS_AVAILABLE);
});
