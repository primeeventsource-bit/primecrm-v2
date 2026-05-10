<?php

declare(strict_types=1);

namespace App\Modules\CallCenter\Application\Services;

use Illuminate\Contracts\Cache\Repository as Cache;
use RuntimeException;
use Throwable;

/**
 * Cache-backed circuit breaker for guarded calls into Twilio.
 *
 * State machine:
 *   closed  → execute the operation. On failure, increment failure count.
 *             When failures within `window_seconds` reach `failure_threshold`,
 *             flip to OPEN and reject calls for `cooldown_seconds`.
 *   open    → reject every call with CircuitOpenException. Lobby renders
 *             a "voice-only mode" banner when this fires.
 *   half-open → next call is permitted; on success → closed (failure
 *             counter reset); on failure → open (cooldown re-armed).
 *
 * Why cache-backed (Redis) and not in-process: Octane workers and queue
 * runners are independent processes. A single failing Twilio API call
 * inside a queue worker shouldn't open the breaker for the web request
 * pool, but a sustained outage absolutely should. Redis gives us shared
 * state across all workers.
 *
 * Note: this is a *coarse* circuit breaker. We don't track per-endpoint
 * failure rates — Twilio API failures usually affect everything (DNS,
 * cert, regional outage), so global is the right grain. If that
 * assumption breaks, switch to a per-key breaker keyed by op name.
 */
final class CircuitBreaker
{
    private const STATE_CLOSED = 'closed';
    private const STATE_OPEN = 'open';
    private const STATE_HALF_OPEN = 'half_open';

    public function __construct(
        private readonly Cache $cache,
        private readonly string $name,
        private readonly int $failureThreshold = 5,
        private readonly int $windowSeconds = 30,
        private readonly int $cooldownSeconds = 15,
    ) {}

    /**
     * Execute $op under the breaker. The breaker only counts $shouldCount
     * exceptions as failures — caller decides which exceptions are
     * "Twilio is unhealthy" vs "user input was bad" (4xx). 4xx must NOT
     * open the circuit, otherwise a wave of malformed requests would
     * lock everyone out of healthy Twilio.
     *
     * @template T
     * @param callable(): T $op
     * @param callable(Throwable): bool $shouldCount   true = treat as health-failure
     * @return T
     */
    public function execute(callable $op, callable $shouldCount): mixed
    {
        $state = $this->state();

        if ($state === self::STATE_OPEN) {
            throw new CircuitOpenException(
                "Circuit '{$this->name}' is open; refusing to call upstream."
            );
        }

        try {
            $result = $op();
        } catch (Throwable $e) {
            if ($shouldCount($e)) {
                $this->recordFailure();
            }
            throw $e;
        }

        // Success while half-open → close fully and clear counters.
        if ($state === self::STATE_HALF_OPEN) {
            $this->close();
        }

        return $result;
    }

    public function state(): string
    {
        $openedAt = $this->cache->get($this->key('opened_at'));
        if (is_int($openedAt)) {
            $age = time() - $openedAt;
            if ($age < $this->cooldownSeconds) {
                return self::STATE_OPEN;
            }
            // Cooldown elapsed; let one trial through.
            return self::STATE_HALF_OPEN;
        }

        return self::STATE_CLOSED;
    }

    public function recordFailure(): void
    {
        $key = $this->key('failures');
        $count = (int) $this->cache->get($key, 0);
        $count++;

        if ($count === 1) {
            $this->cache->put($key, $count, $this->windowSeconds);
        } else {
            // Re-put to keep TTL anchored to the first failure in the window.
            $ttl = $this->cache->getStore() instanceof \Illuminate\Cache\RedisStore
                ? max(1, $this->windowSeconds)
                : $this->windowSeconds;
            $this->cache->put($key, $count, $ttl);
        }

        if ($count >= $this->failureThreshold) {
            $this->open();
        }
    }

    public function open(): void
    {
        $this->cache->put($this->key('opened_at'), time(), $this->cooldownSeconds);
        $this->cache->forget($this->key('failures'));
    }

    public function close(): void
    {
        $this->cache->forget($this->key('failures'));
        $this->cache->forget($this->key('opened_at'));
    }

    private function key(string $segment): string
    {
        return "circuit:{$this->name}:{$segment}";
    }
}

/**
 * Thrown when the breaker is open. Callers (e.g. the lobby controller)
 * catch this specifically to surface a "service degraded — voice only"
 * banner instead of a generic 500.
 */
final class CircuitOpenException extends RuntimeException
{
}
