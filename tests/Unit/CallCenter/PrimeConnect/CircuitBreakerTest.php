<?php

declare(strict_types=1);

use App\Modules\CallCenter\Application\Services\CircuitBreaker;
use App\Modules\CallCenter\Application\Services\CircuitOpenException;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;

/**
 * Pure unit tests against an in-memory ArrayStore so no Redis is needed.
 * The breaker's external dependency is just the Cache contract; ArrayStore
 * implements it.
 */

function newBreaker(int $threshold = 3, int $window = 30, int $cooldown = 5): CircuitBreaker
{
    $cache = new CacheRepository(new ArrayStore);

    return new CircuitBreaker(
        cache: $cache,
        name: 'test_breaker',
        failureThreshold: $threshold,
        windowSeconds: $window,
        cooldownSeconds: $cooldown,
    );
}

it('returns operation result on success without tripping', function () {
    $breaker = newBreaker();

    $result = $breaker->execute(
        op: fn () => 'ok',
        shouldCount: fn () => false,
    );

    expect($result)->toBe('ok');
    expect($breaker->state())->toBe('closed');
});

it('counts only failures the caller flags as health-significant', function () {
    $breaker = newBreaker(threshold: 2);

    // 4xx-style failures (shouldCount: false) — bad input from the user;
    // breaker shouldn't trip even after many of them.
    for ($i = 0; $i < 5; $i++) {
        try {
            $breaker->execute(
                op: fn () => throw new RuntimeException('400 bad request'),
                shouldCount: fn () => false,
            );
        } catch (RuntimeException) {
            // expected
        }
    }
    expect($breaker->state())->toBe('closed');
});

it('opens after threshold consecutive counted failures', function () {
    $breaker = newBreaker(threshold: 3);

    for ($i = 0; $i < 3; $i++) {
        try {
            $breaker->execute(
                op: fn () => throw new RuntimeException('502 bad gateway'),
                shouldCount: fn () => true,
            );
        } catch (RuntimeException) {
            // expected
        }
    }

    expect($breaker->state())->toBe('open');
});

it('rejects new calls with CircuitOpenException while open', function () {
    $breaker = newBreaker(threshold: 1);

    try {
        $breaker->execute(
            op: fn () => throw new RuntimeException('boom'),
            shouldCount: fn () => true,
        );
    } catch (RuntimeException) {
        // expected
    }

    expect(fn () => $breaker->execute(
        op: fn () => 'should-not-run',
        shouldCount: fn () => true,
    ))->toThrow(CircuitOpenException::class);
});

it('transitions to half-open after cooldown elapses, then closes on success', function () {
    $breaker = newBreaker(threshold: 1, cooldown: 1);

    try {
        $breaker->execute(
            op: fn () => throw new RuntimeException('boom'),
            shouldCount: fn () => true,
        );
    } catch (RuntimeException) {
        // expected
    }
    expect($breaker->state())->toBe('open');

    // Wait out the cooldown. 1.1s is enough to cross the boundary
    // without making the test flake-prone.
    usleep(1_100_000);
    expect($breaker->state())->toBe('half_open');

    $result = $breaker->execute(
        op: fn () => 'recovered',
        shouldCount: fn () => true,
    );
    expect($result)->toBe('recovered');
    expect($breaker->state())->toBe('closed');
});
