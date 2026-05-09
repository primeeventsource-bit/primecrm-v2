<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Actions;

use App\Core\Shared\Services\AuditLogService;
use App\Core\Shared\TenantContext;
use App\Modules\Payment\Domain\Events\PaymentCleared;
use App\Modules\Payment\Domain\Events\PaymentFailed;
use App\Modules\Payment\Domain\Models\Payment;
use App\Modules\Payment\Infrastructure\Gateway\PaymentGateway;
use Illuminate\Support\Facades\DB;

/**
 * Creates a Payment row and runs the gateway charge.
 *
 * The Payment row is created BEFORE the gateway call so:
 *   - A webhook arriving microseconds later already has a row to land on.
 *   - If the gateway call fails, the row records the failure for reporting.
 *
 * Provider IDs (`provider_payment_id`) are stamped after the gateway call.
 * If the gateway succeeded synchronously and Stripe reports `cleared_at`
 * we set it now; otherwise the webhook flow will set it on
 * `charge.succeeded` / `payment_intent.succeeded`.
 *
 * Note on PCI: this action takes a `sourceToken` (Stripe `pm_...`),
 * not raw card data. The frontend must use Stripe Elements (or
 * equivalent) so card numbers never touch our server. The dialer's
 * `pauseRecording` is called by the agent UI before card capture and
 * resumed after — keeping the audio recording PCI-clean.
 */
final class ChargePaymentAction
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly TenantContext $tenantContext,
        private readonly AuditLogService $audit,
    ) {}

    public function execute(
        float $amount,
        string $currency,
        string $sourceToken,
        ?string $customerToken = null,
        ?string $bookingId = null,
        ?string $dealId = null,
        ?string $leadId = null,
        ?string $processedById = null,
        string $type = Payment::TYPE_CHARGE,
    ): Payment {
        $payment = DB::transaction(function () use ($amount, $currency, $bookingId, $dealId, $leadId, $processedById, $type): Payment {
            return Payment::query()->create([
                'booking_id' => $bookingId,
                'deal_id' => $dealId,
                'lead_id' => $leadId,
                'processed_by_id' => $processedById ?? $this->tenantContext->userId(),
                'provider' => 'stripe',
                'payment_method' => 'card',
                'amount' => $amount,
                'currency' => $currency,
                'type' => $type,
                'status' => Payment::STATUS_PENDING,
            ]);
        });

        $result = $this->gateway->charge(
            amount: $amount,
            currency: $currency,
            sourceToken: $sourceToken,
            customerToken: $customerToken,
            metadata: [
                'tenant_id' => (string) $this->tenantContext->id(),
                'payment_id' => $payment->id,
                'booking_id' => $bookingId,
                'deal_id' => $dealId,
            ],
        );

        if (! $result->succeeded) {
            $payment->update([
                'status' => Payment::STATUS_FAILED,
                'provider_payment_id' => $result->providerChargeId !== '' ? $result->providerChargeId : null,
                'failure_code' => $result->failureCode,
                'failure_reason' => $result->failureMessage,
                'provider_metadata' => $result->raw,
            ]);

            $this->audit->record(
                action: 'payment.failed',
                entityType: 'payment',
                entityId: $payment->id,
                context: [
                    'failure_code' => $result->failureCode,
                    'failure_reason' => $result->failureMessage,
                ],
            );

            PaymentFailed::dispatch($payment->fresh(), $result->failureCode, $result->failureMessage);

            return $payment->fresh();
        }

        // Gateway accepted. The intent's `status` tells us whether funds
        // already settled (`succeeded`) or are still in flight.
        $stripeStatus = $result->status;
        $statusToWrite = match ($stripeStatus) {
            'succeeded' => Payment::STATUS_SUCCEEDED,
            'requires_action', 'processing', 'requires_confirmation' => Payment::STATUS_PROCESSING,
            default => Payment::STATUS_PROCESSING,
        };

        $isCleared = $stripeStatus === 'succeeded';

        $payment->update([
            'status' => $statusToWrite,
            'provider_payment_id' => $result->providerChargeId,
            'card_last_four' => $result->cardLastFour,
            'card_brand' => $result->cardBrand,
            'authorized_at' => now(),
            'captured_at' => $isCleared ? now() : null,
            'cleared_at' => $isCleared ? now() : null,
            'provider_metadata' => $result->raw,
        ]);

        $this->audit->record(
            action: 'payment.charged',
            entityType: 'payment',
            entityId: $payment->id,
            context: [
                'amount' => (string) $amount,
                'currency' => $currency,
                'provider_id' => $result->providerChargeId,
                'cleared' => $isCleared,
            ],
        );

        if ($isCleared) {
            // Update the booking's paid_amount + flip its status if fully paid.
            $this->updateBookingPaidAmount($payment->fresh());

            PaymentCleared::dispatch($payment->fresh());
        }

        return $payment->fresh();
    }

    private function updateBookingPaidAmount(Payment $payment): void
    {
        if ($payment->booking_id === null) {
            return;
        }

        $booking = \App\Modules\Booking\Domain\Models\Booking::query()->find($payment->booking_id);
        if ($booking === null) {
            return;
        }

        $newPaidAmount = (float) $booking->paid_amount + (float) $payment->amount;
        $updates = ['paid_amount' => $newPaidAmount];

        if ($newPaidAmount >= (float) $booking->total_price - 0.01) {
            $updates['status'] = \App\Modules\Booking\Domain\Models\Booking::STATUS_PAID;
        }

        $booking->update($updates);
    }
}
