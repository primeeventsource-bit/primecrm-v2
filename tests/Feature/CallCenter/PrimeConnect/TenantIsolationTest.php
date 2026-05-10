<?php

declare(strict_types=1);

use App\Modules\CallCenter\Application\Services\TwilioRoomService;
use App\Modules\Tenant\Domain\Models\Tenant;
use App\Support\Enums\UserRole;
use Database\Factories\TenantFactory;
use Database\Factories\UserFactory;
use Mockery as m;
use Twilio\Rest\Video\V1\RoomInstance;

/**
 * The cardinal tenancy invariant: User A in Tenant 1 can never see,
 * end, or otherwise touch a room belonging to Tenant 2 — no matter how
 * they encode the room ID. The TenantScoped trait's global scope on
 * Call enforces this at the query layer; this test proves the
 * controllers respect that scope all the way to 404.
 */
beforeEach(function () {
    config([
        'services.twilio.account_sid' => 'AC'.str_repeat('a', 32),
        'services.twilio.api_key_sid' => 'SK'.str_repeat('b', 32),
        'services.twilio.api_key_secret' => 'fake',
    ]);

    $fakeRoom = m::mock(RoomInstance::class);
    $fakeRoom->sid = 'RM'.str_repeat('d', 32);
    $fakeRoom->uniqueName = 'whatever';

    $mock = m::mock(TwilioRoomService::class);
    $mock->shouldReceive('createRoom')->andReturn($fakeRoom);
    $mock->shouldReceive('endRoom')->andReturn($fakeRoom);
    $this->app->instance(TwilioRoomService::class, $mock);
});

afterEach(function () { m::close(); });

it('does not leak rooms from another tenant in the index', function () {
    // Tenant A creates a room.
    $this->actingAsUser(role: UserRole::Closer);
    $this->postJson('/api/prime-connect/rooms', [])->assertCreated();

    // Tenant B logs in. Their lobby sees zero rooms.
    $tenantB = TenantFactory::new()->create();
    $this->actingAsUser(tenant: $tenantB, role: UserRole::Closer);

    $this->getJson('/api/prime-connect/rooms')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('returns 404 (not 403) when fetching a cross-tenant room SID', function () {
    // Tenant A creates a room.
    $this->actingAsUser(role: UserRole::Closer);
    $tenantARoomId = $this->postJson('/api/prime-connect/rooms', [])->json('data.id');

    // Tenant B tries to fetch it. The TenantScoped global scope makes
    // it look like the row simply doesn't exist — we don't leak the
    // existence of cross-tenant resources by returning 403.
    $tenantB = TenantFactory::new()->create();
    $this->actingAsUser(tenant: $tenantB, role: UserRole::Supervisor);

    $this->getJson("/api/prime-connect/rooms/{$tenantARoomId}")
        ->assertNotFound();
});

it('refuses to end a cross-tenant room', function () {
    $this->actingAsUser(role: UserRole::Closer);
    $tenantARoomId = $this->postJson('/api/prime-connect/rooms', [])->json('data.id');

    $tenantB = TenantFactory::new()->create();
    $this->actingAsUser(tenant: $tenantB, role: UserRole::Supervisor);

    $this->deleteJson("/api/prime-connect/rooms/{$tenantARoomId}")
        ->assertNotFound();
});
