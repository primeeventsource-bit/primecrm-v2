<?php

declare(strict_types=1);

use App\Modules\CallCenter\Application\Services\TwilioRoomService;
use App\Modules\CallCenter\Domain\Models\Call;
use App\Support\Enums\CallMedium;
use App\Support\Enums\RoomStatus;
use App\Support\Enums\UserRole;
use Mockery as m;
use Twilio\Rest\Video\V1\RoomInstance;

/**
 * The Twilio side is mocked at the TwilioRoomService level — we don't
 * exercise the real Twilio SDK from a feature test. The S2 unit tests
 * already prove the resilience wrapper; here we prove the
 * controller → action → DB → broadcast path.
 */
beforeEach(function () {
    config([
        'services.twilio.account_sid' => 'AC'.str_repeat('a', 32),
        'services.twilio.api_key_sid' => 'SK'.str_repeat('b', 32),
        'services.twilio.api_key_secret' => 'fake-secret-for-tests',
        'prime-connect.token.ttl_minutes' => 60,
    ]);

    // Replace the Twilio room service with a mock that returns a fake
    // RoomInstance on createRoom. The endpoint should still 201 and
    // persist the row with the SID we hand it.
    $fakeRoom = m::mock(RoomInstance::class);
    $fakeRoom->sid = 'RM'.str_repeat('c', 32);
    $fakeRoom->uniqueName = 'whatever';

    $mock = m::mock(TwilioRoomService::class);
    $mock->shouldReceive('createRoom')->andReturn($fakeRoom);
    $mock->shouldReceive('endRoom')->andReturn($fakeRoom);
    $mock->shouldReceive('findRoom')->andReturn($fakeRoom);
    $this->app->instance(TwilioRoomService::class, $mock);
});

afterEach(function () { m::close(); });

it('rejects unauthenticated callers on every endpoint', function () {
    $this->postJson('/api/prime-connect/rooms', [])->assertUnauthorized();
    $this->getJson('/api/prime-connect/rooms')->assertUnauthorized();
});

it('creates a video room row and returns 201 with the persisted shape', function () {
    $user = $this->actingAsUser(role: UserRole::Closer);

    $response = $this->postJson('/api/prime-connect/rooms', [
        'room_name' => 'Sofia · Christian Banks',
    ])->assertCreated();

    $body = $response->json('data');
    expect($body['twilio_room_sid'])->toBe('RM'.str_repeat('c', 32))
        ->and($body['room_name'])->toBe('Sofia · Christian Banks')
        ->and($body['room_status'])->toBe(RoomStatus::InProgress->value)
        ->and($body['medium'])->toBe(CallMedium::Video->value)
        ->and($body['agent_id'])->toBe($user->id);

    // DB row exists, scoped to the tenant, of medium=video.
    $call = Call::query()->find($body['id']);
    expect($call)->not->toBeNull();
    expect($call->medium->value)->toBe('video');
    expect($call->tenant_id)->toBe($user->tenant_id);
});

it('lists only the active video rooms in the tenant', function () {
    $user = $this->actingAsUser(role: UserRole::Closer);

    // Create two rooms; end one. The lobby's default filter (active only)
    // should return exactly one.
    $r1 = $this->postJson('/api/prime-connect/rooms', [])->json('data');
    $r2 = $this->postJson('/api/prime-connect/rooms', [])->json('data');

    $this->deleteJson("/api/prime-connect/rooms/{$r2['id']}")->assertOk();

    $list = $this->getJson('/api/prime-connect/rooms?room_status=in_progress')->assertOk();
    $ids = collect($list->json('data'))->pluck('id')->all();
    expect($ids)->toContain($r1['id']);
    expect($ids)->not->toContain($r2['id']);
});

it('forbids non-supervisor non-owner from ending someone else\'s room', function () {
    $owner = $this->actingAsUser(role: UserRole::Closer);
    $owned = $this->postJson('/api/prime-connect/rooms', [])->json('data');

    // Sign in as a different agent in the same tenant.
    $otherAgent = $this->actingAsUser(
        tenant: \App\Modules\Tenant\Domain\Models\Tenant::find($owner->tenant_id),
        role: UserRole::Closer,
    );

    $this->deleteJson("/api/prime-connect/rooms/{$owned['id']}")->assertForbidden();
});

it('lets a supervisor end any room in their tenant', function () {
    $owner = $this->actingAsUser(role: UserRole::Closer);
    $owned = $this->postJson('/api/prime-connect/rooms', [])->json('data');

    $this->actingAsUser(
        tenant: \App\Modules\Tenant\Domain\Models\Tenant::find($owner->tenant_id),
        role: UserRole::Supervisor,
    );

    $this->deleteJson("/api/prime-connect/rooms/{$owned['id']}")->assertOk();

    expect(Call::query()->find($owned['id'])->room_status->value)
        ->toBe(RoomStatus::Completed->value);
});

it('is idempotent on ending an already-ended room', function () {
    $this->actingAsUser(role: UserRole::Closer);
    $room = $this->postJson('/api/prime-connect/rooms', [])->json('data');

    $this->deleteJson("/api/prime-connect/rooms/{$room['id']}")->assertOk();
    $this->deleteJson("/api/prime-connect/rooms/{$room['id']}")
        ->assertOk()
        ->assertJson(['ok' => true, 'already_ended' => true]);
});
