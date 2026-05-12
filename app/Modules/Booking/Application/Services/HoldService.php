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
 * Concurrency model — two layers, both engine-aware:
 *
 *   1. Advisory lock per (unit, check_in_date), keyed by a hash of the
 *      tuple. Dispatched per driver in {@see acquireAdvisoryLock}:
 *        - Postgres: `pg_advisory_xact_lock(hashtextextended(...))`,
 *          auto-released at end of transaction.
 *        - MySQL/MariaDB: `GET_LOCK(name, 5)` — session-scoped, so we
 *          register a transaction-commit/rollback hook to call
 *          `RELEASE_LOCK` deterministically. The 64-char identifier
 *          limit is respected by md5-prefixing the tuple.
 *        - sqlite (tests): no-op. SQLite serializes writes anyway.
 *      Without the lock, two requests doing SELECT-then-UPDATE could
 *      both see "available" and race the row update.
 *
 *   2. `lockForUpdate()` on the `inventory_availability` row inside
 *      the transaction. This is the structural backstop and works on
 *      every engine. The advisory lock just shortens the contention
 *      window when many agents pile onto the same week.
 *
 *      The Postgres-only partial unique index on
 *      (inventory_unit_id, check_in_date) WHERE status IN
 *      ('available','held','booked') was removed when the project
 *      moved to MySQL — see the comment in the booking migration.
 *      `UniqueConstraintViolationException` is still caught below to
 *      stay tolerant of any environment that brings it back.
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
        // Lock acquisition lives outside the transaction so that
        // engines requiring explicit release (MySQL GET_LOCK) get a
        // deterministic try/finally release path on both commit and
        // rollback. Postgres' pg_advisory_lock is session-scoped here
        // too — release() is the inverse on every supported engine.
        $releaseAdvisoryLock = $this->acquireAdvisoryLock(
            $availability->inventory_unit_id,
            $availability->check_in_date->toDateString(),
        );

        try {
            return DB::transaction(function () use ($availability, $heldByUserId, $leadId, $dealId, $ttlMinutesOverride): InventoryHold {
                // Re-fetch under the row lock — state may have shifted
                // since the caller's read. `lockForUpdate()` is the
                // structural race guard; the advisory lock above just
                // shortens the contention window.
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
        } finally {
            $releaseAdvisoryLock();
        }
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
     * Acquire an advisory lock on (unit, check_in_date). Returns a
     * release closure the caller MUST invoke (use try/finally — see
     * {@see hold()}). Dispatched per driver:
     *
     * - pgsql: `pg_advisory_lock(hashtextextended(...))` + matching
     *   `pg_advisory_unlock`. Session-scoped (we deliberately don't use
     *   `pg_advisory_xact_lock` so the acquire/release shape is uniform
     *   with MySQL and lives outside the transaction).
     * - mysql/mariadb: `GET_LOCK(name, 5)` + `RELEASE_LOCK`. Name is
     *   md5-prefixed to stay under the 64-char limit.
     * - other (sqlite for tests): no-op closure. SQLite serializes
     *   writes; the in-transaction `lockForUpdate()` is sufficient.
     *
     * The advisory lock just shortens the contention window. The
     * structural race guard is `lockForUpdate()` on the
     * inventory_availability row inside the transaction.
     */
    private function acquireAdvisoryLock(string $unitId, string $checkInDate): \Closure
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $key = "{$unitId}:{$checkInDate}";

        return match ($driver) {
            'pgsql' => $this->acquirePostgresLock($connection, $key),
            'mysql', 'mariadb' => $this->acquireMysqlLock($connection, $key),
            default => fn () => null,
        };
    }

    private function acquirePostgresLock(\Illuminate\Database\Connection $connection, string $key): \Closure
    {
        // hashtextextended returns a 64-bit signed bigint that
        // pg_advisory_lock takes directly.
        $connection->statement(
            'SELECT pg_advisory_lock(hashtextextended(?, 0))',
            [$key],
        );

        return function () use ($connection, $key): void {
            $connection->statement(
                'SELECT pg_advisory_unlock(hashtextextended(?, 0))',
                [$key],
            );
        };
    }

    private function acquireMysqlLock(\Illuminate\Database\Connection $connection, string $key): \Closure
    {
        // 64-char limit on MySQL lock names — md5 fits in 32 and is
        // unique enough for advisory purposes (collision implies the
        // same key, which is exactly the serialization we want).
        $name = 'hold:'.md5($key);

        $result = $connection->selectOne('SELECT GET_LOCK(?, 5) AS acquired', [$name]);

        // GET_LOCK returns 1 on success, 0 on timeout, NULL on error.
        // On timeout we proceed unlocked — lockForUpdate() inside the
        // transaction is still correct serialization, just slower under
        // contention. No throw.
        $acquired = $result && (int) $result->acquired === 1;

        return function () use ($connection, $name, $acquired): void {
            if ($acquired) {
                $connection->statement('DO RELEASE_LOCK(?)', [$name]);
            }
        };
    }
}
