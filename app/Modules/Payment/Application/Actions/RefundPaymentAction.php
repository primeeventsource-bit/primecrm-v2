<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Actions;

use App\Core\Shared\Services\AuditLogService;
use App\Core\Shared\TenantContext;
use App\Modules\Payment\Domain\Events\PaymentRefunded;
use App\Modules\Payment\Domain\Models\Payment;
use App\Modules\Payment\Infrastructure\Gateway\PaymentGateway;
use Illuminate\Support\Facades\DB;

/**
 * Issues a refund against a previously-cleared charge.
 *
 * Creates a NEW Payment row of type=refund linked via parent_payment_id
 * to the original charge. The original is updated to `refunded` /
 * `partially_refunded` based on whether full or partial.
 *
 * Commission reversal flows from the PaymentRefunded event — the
 * Commission module's listener writes a reversal commission_event,
 * which produces negative commission_calculations referencing the
 * originals (audit trail preserved).
 */
final class RefundPaymentAction
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly TenantContext $tenantContext,
        private readonly AuditLogService $audit,
    ) {}

    public function execute(Payment $original, ?float $amount = null, ?string $reason = null): Payment
    {
        if (! $original->isCharge() || ! $original->isCleared()) {
            throw new \DomainException("Cannot refund a payment that hasn't cleared.");
        }

        $refundAmount = $amount ?? (float) $original->amount;

        if ($refundAmount > (float) $original->amount + 0.01) {
            throw new \DomainException('Refund amount cannot exceed original charge amount.');
        }

        $refund = DB::transaction(function () use ($original, $refundAmount): Payment {
            return Payment::query()->create([
                'booking_id' => $original->booking_id,
                'deal_id' => $original->deal_id,
                'lead_id' => $original->lead_id,
                'processed_by_id' => $this->tenantContext->userId(),
                'provider' => $original->provider,
                'payment_method' => $original->payment_method,
                'amount' => $refundAmount,
                'currency' => $original->currency,
                'type' => Payment::TYPE_REFUND,
                'status' => Payment::STATUS_PENDING,
                'parent_payment_id' => $original->id,
            ]);
        });

        $result = $this->gateway->refund(
            providerChargeId: (string) $original->provider_payment_id,
            amount: $refundAmount,
            metadata: [
                'tenant_id' => (string) $this->tenantContext->id(),
                'refund_payment_id' => $refund->id,
                'reason' => $reason,
            ],
        );

        if (! $result->succeeded) {
            $refund->update([
                'status' => Payment::STATUS_FAILED,
                'provider_metadata' => $result->raw,
                'failure_reason' => 'Gateway refund failed.',
            ]);

            return $refund->fresh();
        }

        DB::transaction(function () use ($original, $refund, $refundAmount, $result): void {
            $refund->update([
                'status' => Payment::STATUS_SUCCEEDED,
                'provider_payment_id' => $result->providerRefundId,
                'authorized_at' => now(),
                'captured_at' => now(),
                'cleared_at' => now(),
                'refunded_at' => now(),
                'provider_metadata' => $result->raw,
            ]);

            $isFullRefund = $refundAmount >= (float) $original->amount - 0.01;
            $original->update([
                'status' => $isFullRefund ? Payment::STATUS_REFUNDED : Payment::STATUS_PARTIALLY_REFUNDED,
                'refunded_at' => $isFullRefund ? now() : $original->refunded_at,
            ]);
        });

        $this->audit->record(
            action: 'payment.refunded',
            entityType: 'payment',
            entityId: $refund->id,
            context: [
                'original_payment_id' => $original->id,
                'amount' => (string) $refundAmount,
                'reason' => $reason,
            ],
        );

        PaymentRefunded::dispatch($refund->fresh(), $original->fresh());

        return $refund->fresh();
    }
}
