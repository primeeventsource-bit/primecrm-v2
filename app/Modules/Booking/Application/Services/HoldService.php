<?php

declare(strict_types=1);

namespace App\Modules\Booking\Application\Services;

use App\Core\Shared\Services\AuditLogService;
use App\Core\Shared\TenantContext;
use App\Modules\Booking\Domain\Events\HoldCreated;
use App\Modules\Booking\Domain\Events\HoldReleased;
use App\Modules\Booking\Domain\Exceptions\InventoryUnavailableException;
use App\Modules\Booking\Domain\Models\InventoryAvailability;
use App\Modules\Booking\Domain\Models\InventoryHold;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Hold creation and release.
 *
 * Concurrency model — two layers:
 *
 *   1. Postgres advisory lock per (tenant, unit, check_in_date).
 *      Acquired with `pg_advisory_xact_lock(hashtextextended(...))` so
 *      it's automatically released at end of transaction. This serializes
 *      simultaneous hold attempts on the same week. Without it, two
 *      requests doing SELECT-then-UPDATE could both see "available" and
 *      race to the partial unique index.
 *
 *   2. Partial unique index on inventory_availability
 *      (inventory_unit_id, check_in_date) WHERE status IN
 *      ('available','held','booked'). The advisory lock above prevents
 *      most races; the index is the structural backstop. If something
 *      slips past the lock (cross-transaction, replication lag), the
 *      index makes the second commit fail with 23505.
 *
 * Hold TTL comes from the resort's `hold_ttl_minutes` setting (default
 * 30 min). Holds expire automatically via the scheduled
 * ExpireInventoryHoldsJob.
 */
final class HoldService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * Acquire a hold on the given availability row.
     *
     * @throws InventoryUnavailableException if the unit was grabbed first
     */
    public function hold(
        InventoryAvailability $availability,
        string $heldByUserId,
        ?string $leadId = null,
        ?string $dealId = null,
        ?int $ttlMinutesOverride = null,
    ): InventoryHold {
        return DB::transaction(function () use ($availability, $heldByUserId, $leadId, $dealId, $ttlMinutesOverride): InventoryHold {
            $this->acquireAdvisoryLock($availability->inventory_unit_id, $availability->check_in_date->toDateString());

            // Re-fetch under the lock — state may have shifted since the
            // caller's read.
            /** @var InventoryAvailability $fresh */
            $fresh = InventoryAvailability::query()
                ->lockForUpdate()
                ->find($availability->id);

            if ($fresh === null || ! $fresh->isAvailable()) {
                throw InventoryUnavailableException::forUnit(
                    $availability->inventory_unit_id,
                    $availability->check_in_date->toDateString(),
                );
            }

            $ttl = $ttlMinutesOverride
                ?? (int) ($fresh->resort?->hold_ttl_minutes ?? 30);

            try {
                $hold = InventoryHold::query()->create([
                    'inventory_availability_id' => $fresh->id,
                    'lead_id' => $leadId,
                    'deal_id' => $dealId,
                    'held_by_id' => $heldByUserId,
                    'expires_at' => now()->addMinutes($ttl),
                ]);

                $fresh->update([
                    'status' => InventoryAvailability::STATUS_HELD,
                    'current_hold_id' => $hold->id,
                ]);
            } catch (UniqueConstraintViolationException $e) {
                throw InventoryUnavailableException::forUnit(
                    $availability->inventory_unit_id,
                    $availability->check_in_date->toDateString(),
                );
            }

            $this->audit->record(
                action: 'inventory.hold_created',
                entityType: 'inventory_hold',
                entityId: $hold->id,
                context: [
                    'availability_id' => $fresh->id,
                    'lead_id' => $leadId,
                    'deal_id' => $dealId,
                    'expires_at' => $hold->expires_at?->toIso8601String(),
                ],
            );

            HoldCreated::dispatch($hold);

            return $hold;
        });
    }

    /**
     * Release a hold voluntarily (agent_released or converted).
     *
     * Returns the hold so callers can chain. No-op if already released.
     */
    public function release(InventoryHold $hold, string $reason = InventoryHold::REASON_AGENT_RELEASED): InventoryHold
    {
        if ($hold->released_at !== null) {
            return $hold;
        }

        DB::transaction(function () use ($hold, $reason): void {
            $hold->update([
                'released_at' => now(),
                'release_reason' => $reason,
            ]);

            $availability = $hold->availability;

            // Only flip back to 'available' if this hold was still the
            // current one. A hold that was converted into a booking moved
            // the row to 'booked' already — we leave it alone.
            if ($availability !== null
                && $availability->status === InventoryAvailability::STATUS_HELD
                && $availability->current_hold_id === $hold->id
            ) {
                $availability->update([
                    'status' => InventoryAvailability::STATUS_AVAILABLE,
                    'current_hold_id' => null,
                ]);
            }
        });

        $this->audit->record(
            action: 'inventory.hold_released',
            entityType: 'inventory_hold',
            entityId: $hold->id,
            context: ['reason' => $reason],
        );

        HoldReleased::dispatch($hold->fresh(), $reason);

        return $hold->fresh();
    }

    /**
     * Sweep expired holds — invoked by ExpireInventoryHoldsJob.
     * Returns the number of holds released.
     */
    public function expireStale(): int
    {
        $count = 0;

        InventoryHold::query()
            ->expired()
            ->withoutTenantScope() // sweep job is system-level
            ->chunkById(200, function ($holds) use (&$count): void {
                foreach ($holds as $hold) {
                    DB::transaction(function () use ($hold, &$count): void {
                        $hold->update([
                            'released_at' => now(),
                            'release_reason' => InventoryHold::REASON_EXPIRED,
                        ]);

                        $availability = InventoryAvailability::query()
                            ->withoutTenantScope()
                            ->find($hold->inventory_availability_id);

                        if ($availability !== null
                            && $availability->status === InventoryAvailability::STATUS_HELD
                            && $availability->current_hold_id === $hold->id
                        ) {
                            $availability->update([
                                'status' => InventoryAvailability::STATUS_AVAILABLE,
                                'current_hold_id' => null,
                            ]);
                        }
                        $count++;
                    });

                    HoldReleased::dispatch($hold->fresh(), InventoryHold::REASON_EXPIRED);
                }
            });

        return $count;
    }

    /**
     * pg_advisory_xact_lock takes a 64-bit signed bigint. We hash the
     * (unit, date) tuple to a stable bigint via Postgres' hashtextextended.
     *
     * The lock is auto-released at end of transaction; callers don't
     * release it explicitly.
     */
    private function acquireAdvisoryLock(string $unitId, string $checkInDate): void
    {
        DB::statement(
            'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
            ["{$unitId}:{$checkInDate}"],
        );
    }
}
