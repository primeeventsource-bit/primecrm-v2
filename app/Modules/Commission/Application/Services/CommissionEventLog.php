<?php

declare(strict_types=1);

namespace App\Modules\Commission\Application\Services;

use App\Modules\Commission\Domain\Models\CommissionEvent;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Append-only writer for commission_events.
 *
 * The unique constraint on `idempotency_key` is the structural property
 * that makes commission accounting safe under retries. Listeners compute
 * the key from the source event (e.g. `payment.cleared:{payment_id}`) so
 * a re-dispatched domain event collides at the DB level — this method
 * returns null for duplicates so callers know to skip downstream work.
 */
final class CommissionEventLog
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function append(
        string $eventType,
        string $sourceEntityType,
        string $sourceEntityId,
        array $payload,
        string $idempotencyKey,
        ?\DateTimeInterface $occurredAt = null,
    ): ?CommissionEvent {
        try {
            return CommissionEvent::query()->create([
                'event_type' => $eventType,
                'source_entity_type' => $sourceEntityType,
                'source_entity_id' => $sourceEntityId,
                'payload' => $payload,
                'idempotency_key' => $idempotencyKey,
                'occurred_at' => $occurredAt ?? now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return null; // duplicate; downstream callers skip
        }
    }
}
