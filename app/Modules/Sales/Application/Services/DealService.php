<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application\Services;

use App\Core\Shared\Services\AuditLogService;
use App\Core\Shared\TenantContext;
use App\Modules\Sales\Domain\Events\DealClosedWon;
use App\Modules\Sales\Domain\Events\DealStageChanged;
use App\Modules\Sales\Domain\Models\Deal;
use App\Modules\Sales\Domain\Models\DealStageTransition;
use App\Support\Enums\DealStage;
use Illuminate\Support\Facades\DB;

/**
 * Owns the deal stage machine.
 *
 * Stage advancement is logged in deal_stage_transitions (append-only)
 * and the row is updated atomically. Closing won fires DealClosedWon
 * which the Commission module listens for.
 */
final class DealService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditLogService $audit,
    ) {}

    public function advanceStage(
        Deal $deal,
        DealStage $toStage,
        ?string $reason = null,
        ?string $changedByUserId = null,
    ): Deal {
        $fromStage = $deal->stage instanceof DealStage ? $deal->stage : DealStage::New;

        if ($fromStage === $toStage) {
            return $deal;
        }

        DB::transaction(function () use ($deal, $fromStage, $toStage, $reason, $changedByUserId): void {
            DealStageTransition::query()->create([
                'deal_id' => $deal->id,
                'changed_by_id' => $changedByUserId ?? $this->tenantContext->userId(),
                'from_stage' => $fromStage->value,
                'to_stage' => $toStage->value,
                'reason' => $reason,
                'metadata' => [],
                'occurred_at' => now(),
            ]);

            $updates = [
                'stage' => $toStage->value,
                'previous_stage' => $fromStage->value,
                'stage_changed_at' => now(),
            ];

            if ($toStage === DealStage::ClosedWon || $toStage === DealStage::ClosedLost) {
                $updates['closed_at'] = now();
            }

            if ($toStage === DealStage::ClosedLost && $reason !== null) {
                $updates['lost_reason'] = $reason;
            }

            $deal->update($updates);
        });

        $this->audit->record(
            action: 'deal.stage_changed',
            entityType: 'deal',
            entityId: $deal->id,
            changes: ['stage' => ['from' => $fromStage->value, 'to' => $toStage->value]],
            context: ['reason' => $reason],
        );

        DealStageChanged::dispatch($deal->fresh(), $fromStage, $toStage, $changedByUserId, $reason);

        if ($toStage === DealStage::ClosedWon) {
            DealClosedWon::dispatch($deal->fresh());
        }

        return $deal->fresh();
    }
}
