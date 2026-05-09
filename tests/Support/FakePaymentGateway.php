<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Payment\Infrastructure\Gateway\GatewayChargeResult;
use App\Modules\Payment\Infrastructure\Gateway\GatewayRefundResult;
use App\Modules\Payment\Infrastructure\Gateway\PaymentGateway;

/**
 * Test double for PaymentGateway.
 *
 * `parseReturn` is what verifyAndParseWebhook returns — set it before
 * firing the webhook controller in tests. Set it to null to simulate
 * an invalid signature.
 */
final class FakePaymentGateway implements PaymentGateway
{
    /** @var list<array{amount: float, currency: string, source: string}> */
    public array $charges = [];

    /** @var list<array{providerChargeId: string, amount: ?float}> */
    public array $refunds = [];

    /** @var array<string, mixed>|null */
    public ?array $parseReturn = null;

    public bool $chargeSucceeds = true;

    public string $chargeStatus = 'succeeded';

    public function charge(
        float $amount,
        string $currency,
        string $sourceToken,
        ?string $customerToken,
        array $metadata = [],
    ): GatewayChargeResult {
        $this->charges[] = ['amount' => $amount, 'currency' => $currency, 'source' => $sourceToken];

        return new GatewayChargeResult(
            succeeded: $this->chargeSucceeds,
            providerChargeId: 'pi_'.bin2hex(random_bytes(8)),
            status: $this->chargeStatus,
            cardLastFour: '4242',
            cardBrand: 'visa',
            failureCode: $this->chargeSucceeds ? null : 'card_declined',
            failureMessage: $this->chargeSucceeds ? null : 'Card was declined.',
            raw: ['simulated' => true],
        );
    }

    public function refund(string $providerChargeId, ?float $amount = null, array $metadata = []): GatewayRefundResult
    {
        $this->refunds[] = ['providerChargeId' => $providerChargeId, 'amount' => $amount];

        return new GatewayRefundResult(
            succeeded: true,
            providerRefundId: 're_'.bin2hex(random_bytes(8)),
            amount: $amount ?? 0,
            status: 'succeeded',
            raw: ['simulated' => true],
        );
    }

    public function verifyAndParseWebhook(string $rawPayload, string $signatureHeader): ?array
    {
        return $this->parseReturn;
    }
}
