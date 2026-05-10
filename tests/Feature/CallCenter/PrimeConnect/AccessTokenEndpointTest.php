<?php

declare(strict_types=1);

use App\Support\Enums\UserRole;

beforeEach(function () {
    // Format-valid Twilio creds so the JWT minter doesn't throw on blanks.
    config([
        'services.twilio.account_sid' => 'AC'.str_repeat('a', 32),
        'services.twilio.api_key_sid' => 'SK'.str_repeat('b', 32),
        'services.twilio.api_key_secret' => 'fake-secret-for-tests',
        'prime-connect.token.ttl_minutes' => 60,
    ]);
});

it('rejects unauthenticated callers', function () {
    $this->postJson('/api/prime-connect/access-token', [
        'role' => 'agent',
    ])->assertUnauthorized();
});

it('mints an agent token for an authenticated user in their tenant', function () {
    $user = $this->actingAsUser(role: UserRole::Agent);

    $response = $this->postJson('/api/prime-connect/access-token', [
        'role' => 'agent',
        'room_name' => 'RM-test-room',
    ])->assertOk();

    $payload = $response->json();
    expect($payload['identity'])->toBe("agent:{$user->id}")
        ->and($payload['token'])->toBeString()
        ->and(strlen($payload['token']))->toBeGreaterThan(64) // base64.base64.base64
        ->and($payload['expires_at'])->toBeString();
});

it('forbids an agent from minting a supervisor_listen token', function () {
    $this->actingAsUser(role: UserRole::Closer);

    $this->postJson('/api/prime-connect/access-token', [
        'role' => 'supervisor_listen',
    ])->assertForbidden();
});

it('lets a supervisor mint a supervisor_whisper token', function () {
    $user = $this->actingAsUser(role: UserRole::Supervisor);

    $response = $this->postJson('/api/prime-connect/access-token', [
        'role' => 'supervisor_whisper',
        'room_name' => 'RM-target-room',
    ])->assertOk();

    expect($response->json('identity'))->toBe("supervisor_whisper:{$user->id}");
});

it('rejects an unknown role value', function () {
    $this->actingAsUser(role: UserRole::Agent);

    $this->postJson('/api/prime-connect/access-token', [
        'role' => 'tour_guide',
    ])->assertStatus(422);
});

it('caps ttl_minutes at the documented max', function () {
    $this->actingAsUser(role: UserRole::Agent);

    // 999 > 240 max → validation fail
    $this->postJson('/api/prime-connect/access-token', [
        'role' => 'agent',
        'ttl_minutes' => 999,
    ])->assertStatus(422);
});
