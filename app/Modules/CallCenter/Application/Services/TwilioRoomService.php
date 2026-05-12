<?php

declare(strict_types=1);

namespace App\Modules\CallCenter\Application\Services;

use Illuminate\Contracts\Config\Repository as Config;
use Throwable;
use Twilio\Exceptions\RestException;
use Twilio\Exceptions\TwilioException;
use Twilio\Rest\Client;
use Twilio\Rest\Video\V1\RoomInstance;

/**
 * Thin facade over Twilio's REST client for video room CRUD, with two
 * resilience layers stacked:
 *
 *   1. Per-call exponential-backoff retry (handles transient 429 / 5xx).
 *   2. Coarse circuit breaker (handles sustained outage — opens after
 *      threshold consecutive failures so the whole app stops hammering
 *      Twilio when it's down, falls into "voice-only mode" UX).
 *
 * Why both: retries handle micro-blips (a 502 mid-call); the breaker
 * stops a thundering herd when Twilio's data plane is genuinely down.
 * Without the breaker, every web request would burn its retry budget on
 * a doomed call, blowing up p99 latency.
 *
 * 4xx (other than 429) is NEVER retried — that's a permanent client
 * error (bad room SID, missing grant) and retrying just amplifies a bug.
 * 4xx also doesn't tick the breaker counter; bad input from one user
 * shouldn't lock everyone else out of healthy Twilio.
 */
/**
 * NOT `final`: the test suite extends this class to bypass the real
 * Twilio\Rest\Client constructor argument (the SDK has no friendly
 * test double) and expose the private `withResilience` wrapper.
 * Production code should not subclass — the resilience contract is
 * the public API. See tests/Unit/CallCenter/PrimeConnect/TwilioRoomServiceTest.
 */
class TwilioRoomService
{
    /** HTTP statuses that justify a retry (transient on Twilio's side). */
    private const RETRYABLE_STATUSES = [429, 500, 502, 503, 504];

    public function __construct(
        private readonly Client $twilio,
        private readonly CircuitBreaker $breaker,
        private readonly Config $config,
    ) {}

    /**
     * Create a new Twilio Video room. Caller chooses the unique name
     * (we use the Call::id UUID so the SID round-trip is trivial).
     *
     * @param array<string, mixed> $extraOptions  Twilio room options to merge over our defaults
     */
    public function createRoom(string $uniqueName, array $extraOptions = []): RoomInstance
    {
        $options = array_merge([
            'uniqueName' => $uniqueName,
            'type' => $this->config->get('prime-connect.room.type', 'group'),
            'recordParticipantsOnConnect' => (bool) $this->config->get(
                'prime-connect.room.record_participants_on_connect', true
            ),
            'maxParticipants' => (int) $this->config->get('prime-connect.room.max_participants', 8),
            'statusCallback' => $this->statusCallbackUrl(),
            'statusCallbackMethod' => $this->config->get(
                'prime-connect.room.status_callback_method', 'POST'
            ),
        ], $extraOptions);

        return $this->withResilience(
            fn () => $this->twilio->video->v1->rooms->create($options)
        );
    }

    /**
     * Mark a room as completed via the REST API. Twilio will fire a
     * room-ended status callback shortly after, which the webhook handler
     * uses to write `ended_at` on the calls row.
     */
    public function endRoom(string $sid): RoomInstance
    {
        return $this->withResilience(
            fn () => $this->twilio->video->v1->rooms($sid)->update(['status' => 'completed'])
        );
    }

    /**
     * Look up a room by SID. Returns null on 404 — the caller (typically
     * a webhook handler reconciling state) decides whether the missing
     * room is fatal or expected (e.g. cleanup race).
     */
    public function findRoom(string $sid): ?RoomInstance
    {
        try {
            return $this->withResilience(
                fn () => $this->twilio->video->v1->rooms($sid)->fetch()
            );
        } catch (RestException $e) {
            if ($e->getStatusCode() === 404) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * @template T
     * @param callable(): T $op
     * @return T
     */
    private function withResilience(callable $op): mixed
    {
        $attempts = (int) $this->config->get('prime-connect.resilience.retry_attempts', 3);
        $initialMs = (int) $this->config->get('prime-connect.resilience.retry_initial_delay_ms', 200);

        return $this->breaker->execute(
            op: function () use ($op, $attempts, $initialMs) {
                $lastError = null;
                for ($attempt = 1; $attempt <= $attempts; $attempt++) {
                    try {
                        return $op();
                    } catch (Throwable $e) {
                        $lastError = $e;
                        if (! $this->isRetryable($e) || $attempt === $attempts) {
                            throw $e;
                        }
                        // Exponential backoff: 200ms, 400ms, 800ms ...
                        $delayMicros = $initialMs * (2 ** ($attempt - 1)) * 1000;
                        usleep($delayMicros);
                    }
                }
                // Unreachable: loop either returns or throws.
                throw $lastError ?? new TwilioException('Twilio call failed without exception');
            },
            shouldCount: fn (Throwable $e) => $this->isRetryable($e),
        );
    }

    private function isRetryable(Throwable $e): bool
    {
        if ($e instanceof RestException) {
            return in_array($e->getStatusCode(), self::RETRYABLE_STATUSES, true);
        }
        // Network-layer exceptions from the underlying HTTP client manifest
        // as TwilioException (or its subclasses). Treat as retryable —
        // the alternative is silently failing on a transient DNS blip.
        return $e instanceof TwilioException;
    }

    private function statusCallbackUrl(): string
    {
        $base = rtrim((string) $this->config->get('services.twilio.webhook_base'), '/')
            ?: rtrim((string) $this->config->get('telephony.providers.twilio.webhook_base_url'), '/');

        return $base.'/webhooks/twilio/video';
    }
}
