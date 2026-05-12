<?php

declare(strict_types=1);

use App\Modules\CallCenter\Application\DTOs\AccessTokenDto;
use App\Modules\CallCenter\Application\Services\TwilioAccessTokenService;
use Illuminate\Config\Repository as Config;

/**
 * Pure unit tests — Twilio's AccessToken JWT minter does no network IO,
 * so we run it against fake-but-format-valid credentials and decode the
 * resulting JWT to assert claims.
 *
 * These tests require the twilio/sdk composer package to be installed
 * (it is — composer.json line 1). They run on Cloud / CI; locally they
 * need `composer install` first.
 */

function configWithTwilio(array $overrides = []): Config
{
    return new Config(array_replace_recursive([
        'services' => [
            'twilio' => [
                // Fake but format-valid SIDs (AC.. and SK.. are 34 chars total).
                'account_sid' => 'AC'.str_repeat('a', 32),
                'api_key_sid' => 'SK'.str_repeat('b', 32),
                'api_key_secret' => 'fake-api-key-secret-value-for-tests',
            ],
        ],
        'prime-connect' => [
            'token' => ['ttl_minutes' => 60],
        ],
    ], $overrides));
}

/** Decode a JWT's payload segment without verifying the signature (test-only). */
function decodeJwtPayload(string $jwt): array
{
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        throw new RuntimeException('Malformed JWT');
    }
    $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);
    expect($payload)->not->toBeFalse();

    return json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
}

it('mints a JWT with the requested identity and an expiry around the configured TTL', function () {
    $service = new TwilioAccessTokenService(configWithTwilio());

    $beforeMs = (int) (microtime(true) * 1000);
    $token = $service->mint('agent:01HX', roomName: null, ttlMinutes: 60);
    $afterMs = (int) (microtime(true) * 1000);

    expect($token)->toBeInstanceOf(AccessTokenDto::class);
    expect($token->identity)->toBe('agent:01HX');

    $payload = decodeJwtPayload($token->jwt);

    expect($payload['sub'] ?? null)->toBe('AC'.str_repeat('a', 32))
        ->and($payload['iss'] ?? null)->toBe('SK'.str_repeat('b', 32));

    // Expiry should be ~3600s after mint time (within a 5s wall-clock fudge).
    $expectedExpMs = $beforeMs + 3600 * 1000;
    $expMs = ((int) ($payload['exp'] ?? 0)) * 1000;
    expect(abs($expMs - $expectedExpMs))->toBeLessThan(5000);

    // The DTO's expiresAt should align with the JWT's exp claim.
    expect($token->expiresAt->getTimestamp())->toBe((int) $payload['exp']);
});

it('binds the JWT to a room name when one is provided', function () {
    $service = new TwilioAccessTokenService(configWithTwilio());

    $token = $service->mint('supervisor_listen:01HX', roomName: 'RM-room-uuid', ttlMinutes: 30);

    $payload = decodeJwtPayload($token->jwt);
    $videoGrant = $payload['grants']['video'] ?? [];

    expect($videoGrant['room'] ?? null)->toBe('RM-room-uuid');
});

it('omits the room binding when room name is null', function () {
    $service = new TwilioAccessTokenService(configWithTwilio());

    $token = $service->mint('agent:01HX', roomName: null);

    $payload = decodeJwtPayload($token->jwt);
    $videoGrant = $payload['grants']['video'] ?? [];

    // No room key (or empty room) means the token can join any room the
    // identity is otherwise authorized for. Used for lobby reconnect flows
    // where the room isn't known until the participant picks one.
    expect($videoGrant['room'] ?? null)->toBeNull();
});

it('falls back to the configured TTL when none is passed', function () {
    $config = configWithTwilio(['prime-connect' => ['token' => ['ttl_minutes' => 15]]]);
    $service = new TwilioAccessTokenService($config);

    $before = time();
    $token = $service->mint('agent:01HX');
    $payload = decodeJwtPayload($token->jwt);

    // Twilio's AccessToken JWT doesn't include an `iat` claim — it
    // only sets `exp` = time() + ttl at mint. Compute the effective
    // TTL by comparing exp against the wall clock around the mint
    // call (same pattern as the first test in this file).
    $effectiveTtl = ((int) $payload['exp']) - $before;
    expect($effectiveTtl)->toBeGreaterThanOrEqual(15 * 60)
        ->and($effectiveTtl)->toBeLessThanOrEqual(15 * 60 + 2); // 2s wall-clock fudge
});

it('throws when Twilio credentials are blank rather than minting an unsigned junk token', function () {
    $service = new TwilioAccessTokenService(new Config([
        'services' => ['twilio' => ['account_sid' => '', 'api_key_sid' => '', 'api_key_secret' => '']],
        'prime-connect' => ['token' => ['ttl_minutes' => 60]],
    ]));

    expect(fn () => $service->mint('agent:01HX'))->toThrow(RuntimeException::class);
});
