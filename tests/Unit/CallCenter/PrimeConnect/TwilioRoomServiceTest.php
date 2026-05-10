<?php

declare(strict_types=1);

use App\Modules\CallCenter\Application\Services\CircuitBreaker;
use App\Modules\CallCenter\Application\Services\CircuitOpenException;
use App\Modules\CallCenter\Application\Services\TwilioRoomService;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository as Config;
use Twilio\Exceptions\RestException;

/**
 * The Twilio\Rest\Client SDK doesn't ship with friendly test doubles —
 * its `video->v1->rooms->create()` chain is hard to mock without a real
 * client. We test the resilience contract (retry policy + circuit
 * behaviour) by routing through the breaker and a stub op so we don't
 * have to fake the entire SDK.
 *
 * The S2 manual smoke-test (creating a real room against the dev Twilio
 * subaccount) is the integration check; these tests prove the wrapping
 * logic without requiring credentials.
 */

function newRoomService(int $retryAttempts = 3, int $threshold = 3): TwilioRoomService
{
    $cache = new CacheRepository(new ArrayStore);
    $breaker = new CircuitBreaker(
        cache: $cache,
        name: 'rs_test',
        failureThreshold: $threshold,
        windowSeconds: 30,
        cooldownSeconds: 5,
    );
    $config = new Config([
        'prime-connect' => [
            'resilience' => [
                'retry_attempts' => $retryAttempts,
                'retry_initial_delay_ms' => 1, // keep tests fast
            ],
            'room' => ['type' => 'group', 'record_participants_on_connect' => true, 'max_participants' => 8, 'status_callback_method' => 'POST'],
        ],
        'services' => ['twilio' => ['webhook_base' => 'https://example.test']],
    ]);

    // We don't actually invoke the Twilio client in these tests — we
    // exercise the resilience wrapper directly. Pass null and rely on the
    // method-level reflection to call withResilience without touching
    // ->video->v1->rooms.
    return new class(null, $breaker, $config) extends TwilioRoomService {
        public function __construct(?\Twilio\Rest\Client $twilio, CircuitBreaker $breaker, Config $config)
        {
            parent::__construct($twilio ?? new \stdClass, $breaker, $config); // @phpstan-ignore-line
        }

        // Expose the private wrapper for direct testing.
        public function exec(callable $op): mixed
        {
            $reflection = new ReflectionMethod(parent::class, 'withResilience');
            $reflection->setAccessible(true);

            return $reflection->invoke($this, $op);
        }
    };
}

it('retries a 503 RestException and ultimately succeeds', function () {
    $service = newRoomService(retryAttempts: 3);
    $calls = 0;

    $result = $service->exec(function () use (&$calls) {
        $calls++;
        if ($calls < 3) {
            throw new RestException('Service unavailable', 503, 503);
        }

        return 'ok';
    });

    expect($result)->toBe('ok');
    expect($calls)->toBe(3);
});

it('does NOT retry a 4xx RestException (other than 429)', function () {
    $service = newRoomService(retryAttempts: 3);
    $calls = 0;

    try {
        $service->exec(function () use (&$calls) {
            $calls++;
            throw new RestException('Bad request', 400, 400);
        });
    } catch (RestException $e) {
        expect($e->getStatusCode())->toBe(400);
    }

    expect($calls)->toBe(1); // no retries — bug, not transient
});

it('retries on 429 (rate limit)', function () {
    $service = newRoomService(retryAttempts: 2);
    $calls = 0;

    try {
        $service->exec(function () use (&$calls) {
            $calls++;
            throw new RestException('Too many requests', 429, 429);
        });
    } catch (RestException) {
        // expected: still fails after retry budget exhausted
    }

    expect($calls)->toBe(2);
});

it('opens the circuit after threshold counted failures and rejects subsequent calls', function () {
    $service = newRoomService(retryAttempts: 1, threshold: 2);

    // Each call burns the entire (single) retry budget and ticks the
    // breaker once. Two failures => circuit opens.
    foreach ([1, 2] as $_) {
        try {
            $service->exec(fn () => throw new RestException('boom', 502, 502));
        } catch (RestException) {
            // expected
        }
    }

    // Third call: should be rejected by the open circuit BEFORE running.
    expect(fn () => $service->exec(fn () => throw new RestException('would-run-but-shouldnt', 502, 502)))
        ->toThrow(CircuitOpenException::class);
});
