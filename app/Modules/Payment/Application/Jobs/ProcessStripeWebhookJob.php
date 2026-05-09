<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Jobs;

use App\Modules\CallCenter\Application\Services\WebhookEventStore;
use App\Modules\CallCenter\Domain\Models\WebhookEvent;
use App\Modules\Payment\Domain\Events\ChargebackOccurred;
use App\Modules\Payment\Domain\Events\PaymentCleared;
use App\Modules\Payment\Domain\Events\PaymentFailed;
use App\Modules\Payment\Domain\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Asynchronously processes a Stripe webhook event.
 *
 * The HTTP controller verifies the signature, ingests via
 * WebhookEventStore (using `event.id` as external_id), and dispatches
 * this job. Idempotency is structural: the unique (provider, external_id)
 * constraint on webhook_events guarantees one row per Stripe event.
 *
 * Handled events:
 *   payment_intent.succeeded     → PaymentCleared (commission trigger)
 *   payment_intent.payment_failed → PaymentFailed
 *   charge.dispute.created       → ChargebackOccurred (commission reversal)
 *   charge.refunded              → handled by RefundPaymentAction; this
 *                                  webhook just confirms what we already
 *                                  did, so we no-op to be idempotent
 */
final class ProcessStripeWebhookJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;
    public array $backoff = [10, 30, 60, 120, 300];

    public function __construct(public readonly string $webhookEventId)
    {
        $this->onQueue(config('queue.names.webhooks_stripe'));
    }

    public function handle(
        WebhookEventStore $store,
        \App\Core\Shared\TenantContext $tenantContext,
    ): void {
        $event = WebhookEvent::query()->find($this->webhookEventId);
        if ($event === null || $event->status === WebhookEvent::STATUS_PROCESSED) {
            return;
        }

        $store->markProcessing($event);

        try {
            $payload = $event->payload;
            $type = (string) ($payload['type'] ?? '');
            $data = (array) ($payload['data']['object'] ?? []);

            $tenantResolved = $this->resolveTenant($data);
            if ($tenantResolved !== null) {
                $tenantContext->set($tenantResolved);
            }

            switch ($type) {
                case 'payment_intent.succeeded':
                    $this->handleSucceeded($data);
                    break;

                case 'payment_intent.payment_failed':
                    $this->handleFailed($data);
                    break;

                case 'charge.dispute.created':
                case 'charge.dispute.funds_withdrawn':
                    $this->handleChargeback($data);
                    break;

                case 'charge.refunded':
                    // Refund initiated from our side via RefundPaymentAction.
                    // Webhook is a confirmation; we already wrote the row.
                    break;
            }

            $store->markProcessed($event, $tenantResolved);
        } catch (\Throwable $e) {
            $store->markFailed($event, $e->getMessage());
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveTenant(array $data): ?string
    {
        // Stripe attaches our metadata when we created the charge.
        $metadata = (array) ($data['metadata'] ?? []);

        return $metadata['tenant_id'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function handleSucceeded(array $data): void
    {
        $intentId = (string) ($data['id'] ?? '');
        if ($intentId === '') {
            return;
        }

        $payment = Payment::query()
            ->withoutTenantScope()
            ->where('provider_payment_id', $intentId)
            ->first();

        if ($payment === null) {
            // Race: the action's DB transaction hadn't committed when we got
            // the webhook. The job will retry; if the row appears later,
            // we'll succeed.
            throw new \RuntimeException("Payment row for intent {$intentId} not found yet; will retry.");
        }

        if ($payment->isCleared()) {
            return; // already processed via the synchronous path
        }

        DB::transaction(function () use ($payment, $data): void {
            $payment->update([
                'status' => Payment::STATUS_SUCCEEDED,
                'captured_at' => $payment->captured_at ?? now(),
                'cleared_at' => now(),
                'provider_metadata' => $data,
            ]);

            $this->updateBookingPaid($payment->fresh());
        });

        PaymentCleared::dispatch($payment->fresh());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function handleFailed(array $data): void
    {
        $intentId = (string) ($data['id'] ?? '');
        $payment = Payment::query()
            ->withoutTenantScope()
            ->where('provider_payment_id', $intentId)
            ->first();

        if ($payment === null) {
            return;
        }

        $code = (string) ($data['last_payment_error']['code'] ?? '');
        $reason = (string) ($data['last_payment_error']['message'] ?? '');

        $payment->update([
            'status' => Payment::STATUS_FAILED,
            'failure_code' => $code !== '' ? $code : null,
            'failure_reason' => $reason !== '' ? $reason : null,
            'provider_metadata' => $data,
        ]);

        PaymentFailed::dispatch($payment->fresh(), $code, $reason);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function handleChargeback(array $data): void
    {
        // Stripe dispute objects carry the underlying charge id at $data['charge']
        $chargeId = (string) ($data['charge'] ?? '');
        if ($chargeId === '') {
            return;
        }

        // Find the original charge — the payment_intent associated with it.
        // We persisted provider_payment_id as the intent id; the dispute
        // carries the charge id, so we look up by Stripe's API.
        $original = Payment::query()
            ->withoutTenantScope()
            ->where('type', Payment::TYPE_CHARGE)
            ->whereJsonContains('provider_metadata->latest_charge', $chargeId)
            ->orWhere('provider_payment_id', $chargeId)
            ->first();

        if ($original === null) {
            return;
        }

        $amount = isset($data['amount']) ? ((float) $data['amount']) / 100 : (float) $original->amount;

        $chargeback = DB::transaction(function () use ($original, $amount, $data): Payment {
            $cb = Payment::query()->create([
                'booking_id' => $original->booking_id,
                'deal_id' => $original->deal_id,
                'lead_id' => $original->lead_id,
                'tenant_id' => $original->tenant_id,
                'provider' => $original->provider,
                'provider_payment_id' => (string) ($data['id'] ?? null),
                'payment_method' => $original->payment_method,
                'amount' => $amount,
                'currency' => $original->currency,
                'type' => Payment::TYPE_CHARGEBACK,
                'status' => Payment::STATUS_SUCCEEDED,
                'parent_payment_id' => $original->id,
                'authorized_at' => now(),
                'captured_at' => now(),
                'cleared_at' => now(),
                'provider_metadata' => $data,
            ]);

            $original->update(['status' => Payment::STATUS_CHARGEBACK]);

            return $cb;
        });

        ChargebackOccurred::dispatch($chargeback, $original->fresh());
    }

    private function updateBookingPaid(Payment $payment): void
    {
        if ($payment->booking_id === null) {
            return;
        }

        $booking = \App\Modules\Booking\Domain\Models\Booking::query()
            ->withoutTenantScope()
            ->find($payment->booking_id);

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
