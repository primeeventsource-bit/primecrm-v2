<?php

declare(strict_types=1);

namespace App\Modules\CallCenter\Application\Services;

use App\Modules\CallCenter\Domain\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent ingest of provider webhooks.
 *
 * The (provider, external_id) unique constraint on the table is the
 * structural guarantee. This service makes the Laravel-side flow easy:
 *
 *   $event = $store->ingest('twilio', $callSid.':'.$status, ...);
 *   if ($event === null) {
 *       // already-processed duplicate; nothing to do
 *       return;
 *   }
 *   // ... process $event ...
 *   $store->markProcessed($event);
 *
 * `ingest()` returns null if the row already exists with status = processed.
 * Otherwise returns the stored row (creating it if needed) so the caller
 * can move it through the lifecycle.
 *
 * @api
 */
final class WebhookEventStore
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function ingest(
        string $provider,
        string $externalId,
        string $eventType,
        array $payload,
        array $headers = [],
    ): ?WebhookEvent {
        return DB::transaction(function () use ($provider, $externalId, $eventType, $payload, $headers): ?WebhookEvent {
            $existing = WebhookEvent::query()
                ->where('provider', $provider)
                ->where('external_id', $externalId)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->status === WebhookEvent::STATUS_PROCESSED) {
                    return null;
                }
                // Retry of a failed/in-flight event — bump attempts so we can
                // back off externally and surface stuck rows in monitoring.
                $existing->increment('attempts');

                return $existing;
            }

            return WebhookEvent::query()->create([
                'provider' => $provider,
                'event_type' => $eventType,
                'external_id' => $externalId,
                'payload' => $payload,
                'headers' => $headers,
                'status' => WebhookEvent::STATUS_RECEIVED,
                'attempts' => 1,
            ]);
        });
    }

    public function markProcessing(WebhookEvent $event): void
    {
        $event->update(['status' => WebhookEvent::STATUS_PROCESSING]);
    }

    public function markProcessed(WebhookEvent $event, ?string $tenantId = null): void
    {
        $event->update([
            'status' => WebhookEvent::STATUS_PROCESSED,
            'processed_at' => now(),
            'tenant_id' => $tenantId ?? $event->tenant_id,
        ]);
    }

    public function markFailed(WebhookEvent $event, string $error): void
    {
        $event->update([
            'status' => WebhookEvent::STATUS_FAILED,
            'last_error' => $error,
        ]);
    }
}
