<?php

declare(strict_types=1);

namespace App\Modules\Commission\Application\Services;

use App\Core\Shared\Services\AuditLogService;
use App\Core\Shared\TenantContext;
use App\Modules\Commission\Domain\Events\PayoutApproved;
use App\Modules\Commission\Domain\Models\CommissionAdjustment;
use App\Modules\Commission\Domain\Models\CommissionCalculation;
use App\Modules\Commission\Domain\Models\CommissionPayout;
use Illuminate\Support\Facades\DB;

/**
 * Builds period payout rows from calculations + adjustments.
 *
 * Math:
 *   total_earned     = sum of positive non-reversal payable calculations
 *   total_reversed   = sum of negative (reversal) calculations
 *                      (a positive number representing what was clawed back)
 *   total_adjustments = sum of adjustments (signed)
 *   net_payable      = total_earned - total_reversed + total_adjustments
 *
 * Rebuild safety: if a payout for (user, period) exists in `draft`, the
 * service updates it in place. If it's already `approved` or beyond, the
 * rebuild is refused — operators must void/reissue rather than mutate
 * post-approval.
 */
final class PayoutService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditLogService $audit,
    ) {}

    public function buildForPeriod(
        string $userId,
        string $periodStart,
        string $periodEnd,
    ): CommissionPayout {
        $existing = CommissionPayout::query()
            ->where('user_id', $userId)
            ->where('period_start', $periodStart)
            ->where('period_end', $periodEnd)
            ->first();

        if ($existing !== null && $existing->status !== CommissionPayout::STATUS_DRAFT) {
            return $existing;
        }

        $calculations = CommissionCalculation::query()
            ->forUser($userId)
            ->forPeriod($periodStart, $periodEnd)
            ->whereIn('status', [
                CommissionCalculation::STATUS_PAYABLE,
                CommissionCalculation::STATUS_PENDING,
            ])
            ->get();

        $earned = 0.0;
        $reversed = 0.0;
        $ids = [];

        foreach ($calculations as $calc) {
            $ids[] = $calc->id;
            $amount = (float) $calc->amount;
            if ($calc->is_reversal || $amount < 0) {
                // Reversed amounts are stored as negative; flip sign for
                // the running total so it represents "how much clawed back".
                $reversed += abs($amount);
            } else {
                $earned += $amount;
            }
        }

        $adjustmentsTotal = (float) CommissionAdjustment::query()
            ->where('user_id', $userId)
            ->whereDate('payable_period', '>=', $periodStart)
            ->whereDate('payable_period', '<=', $periodEnd)
            ->sum('amount');

        $netPayable = round($earned - $reversed + $adjustmentsTotal, 2);

        $payload = [
            'tenant_id' => $this->tenantContext->id(),
            'user_id' => $userId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'total_earned' => round($earned, 2),
            'total_reversed' => round($reversed, 2),
            'total_adjustments' => round($adjustmentsTotal, 2),
            'net_payable' => $netPayable,
            'currency' => 'USD',
            'status' => CommissionPayout::STATUS_DRAFT,
            'calculation_ids' => $ids,
        ];

        $payout = $existing !== null
            ? tap($existing)->update($payload)
            : CommissionPayout::query()->create($payload);

        $this->audit->record(
            action: 'commission.payout_built',
            entityType: 'commission_payout',
            entityId: $payout->id,
            context: [
                'user_id' => $userId,
                'period' => "{$periodStart}–{$periodEnd}",
                'net_payable' => $netPayable,
                'calculation_count' => count($ids),
            ],
        );

        return $payout->fresh();
    }

    public function approve(CommissionPayout $payout, string $approverId): CommissionPayout
    {
        if ($payout->status !== CommissionPayout::STATUS_DRAFT) {
            return $payout;
        }

        DB::transaction(function () use ($payout, $approverId): void {
            $payout->update([
                'status' => CommissionPayout::STATUS_APPROVED,
                'approved_by_id' => $approverId,
                'approved_at' => now(),
            ]);

            // Move the calculations from payable → paid? No — paid happens
            // when the payout itself is paid. Here we just lock them by
            // marking the payout approved; downstream payroll integration
            // sets paid status.
        });

        $this->audit->record(
            action: 'commission.payout_approved',
            entityType: 'commission_payout',
            entityId: $payout->id,
            context: ['approver_id' => $approverId],
        );

        PayoutApproved::dispatch($payout->fresh());

        return $payout->fresh();
    }

    public function markPaid(CommissionPayout $payout, string $reference): CommissionPayout
    {
        if ($payout->status !== CommissionPayout::STATUS_APPROVED) {
            return $payout;
        }

        DB::transaction(function () use ($payout, $reference): void {
            $payout->update([
                'status' => CommissionPayout::STATUS_PAID,
                'paid_at' => now(),
                'payment_reference' => $reference,
            ]);

            CommissionCalculation::query()
                ->whereIn('id', (array) $payout->calculation_ids)
                ->where('status', CommissionCalculation::STATUS_PAYABLE)
                ->update(['status' => CommissionCalculation::STATUS_PAID]);
        });

        $this->audit->record(
            action: 'commission.payout_paid',
            entityType: 'commission_payout',
            entityId: $payout->id,
            context: ['reference' => $reference],
        );

        return $payout->fresh();
    }
}
