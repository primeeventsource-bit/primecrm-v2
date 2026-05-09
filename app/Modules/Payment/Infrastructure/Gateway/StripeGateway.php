<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Gateway;

use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;

/**
 * Stripe implementation of {@see PaymentGateway}.
 *
 * Stripe deals in cents for currencies that have minor units. This
 * implementation converts on the boundary — the rest of the system
 * works in major units.
 */
final class StripeGateway implements PaymentGateway
{
    public function __construct(
        private readonly StripeClient $client,
        private readonly string $webhookSecret,
    ) {}

    public function charge(
        float $amount,
        string $currency,
        string $sourceToken,
        ?string $customerToken,
        array $metadata = [],
    ): GatewayChargeResult {
        try {
            $intent = $this->client->paymentIntents->create([
                'amount' => $this->toMinorUnits($amount, $currency),
                'currency' => mb_strtolower($currency),
                'payment_method' => $sourceToken,
                'customer' => $customerToken,
                'confirm' => true,
                'off_session' => true,
                'metadata' => $metadata,
            ]);

            $charge = $intent->latest_charge ?? null;
            $card = $intent->payment_method
                ? $this->client->paymentMethods->retrieve((string) $intent->payment_method)
                : null;

            return new GatewayChargeResult(
                succeeded: $intent->status === 'succeeded',
                providerChargeId: (string) $intent->id,
                status: (string) $intent->status,
                cardLastFour: $card?->card?->last4,
                cardBrand: $card?->card?->brand,
                raw: $intent->toArray(),
            );
        } catch (ApiErrorException $e) {
            return new GatewayChargeResult(
                succeeded: false,
                providerChargeId: '',
                status: 'failed',
                failureCode: $e->getStripeCode(),
                failureMessage: $e->getMessage(),
                raw: ['error' => $e->getMessage()],
            );
        }
    }

    public function refund(string $providerChargeId, ?float $amount = null, array $metadata = []): GatewayRefundResult
    {
        $params = [
            'payment_intent' => $providerChargeId,
            'metadata' => $metadata,
        ];

        // Stripe requires the currency for amount conversion; we re-fetch the
        // intent to know what currency we're dealing with.
        $intent = $this->client->paymentIntents->retrieve($providerChargeId);
        $currency = (string) $intent->currency;

        if ($amount !== null) {
            $params['amount'] = $this->toMinorUnits($amount, $currency);
        }

        $refund = $this->client->refunds->create($params);

        return new GatewayRefundResult(
            succeeded: $refund->status === 'succeeded',
            providerRefundId: (string) $refund->id,
            amount: $this->fromMinorUnits($refund->amount, $currency),
            status: (string) $refund->status,
            raw: $refund->toArray(),
        );
    }

    public function verifyAndParseWebhook(string $rawPayload, string $signatureHeader): ?array
    {
        try {
            $event = Webhook::constructEvent($rawPayload, $signatureHeader, $this->webhookSecret);

            return $event->toArray();
        } catch (SignatureVerificationException) {
            return null;
        }
    }

    private function toMinorUnits(float $amount, string $currency): int
    {
        $zeroDecimal = ['JPY', 'KRW', 'CLP'];

        return in_array(mb_strtoupper($currency), $zeroDecimal, true)
            ? (int) round($amount)
            : (int) round($amount * 100);
    }

    private function fromMinorUnits(int $minor, string $currency): float
    {
        $zeroDecimal = ['JPY', 'KRW', 'CLP'];

        return in_array(mb_strtoupper($currency), $zeroDecimal, true)
            ? (float) $minor
            : $minor / 100.0;
    }
}
