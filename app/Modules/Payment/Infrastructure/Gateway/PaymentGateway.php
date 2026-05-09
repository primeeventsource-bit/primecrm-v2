<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Gateway;

/**
 * Provider-agnostic payment gateway interface.
 *
 * The application calls into this; concrete classes wrap Stripe,
 * Authorize.Net, etc. Adding a new gateway is implementing this
 * interface, not patching every payment flow.
 */
interface PaymentGateway
{
    /**
     * Create a charge. The amount is in major units (dollars), not cents
     * — gateways that need cents (like Stripe) convert internally.
     *
     * @param  array<string, mixed>  $metadata  attached to the charge for reconciliation
     */
    public function charge(
        float $amount,
        string $currency,
        string $sourceToken,    // payment method id (Stripe `pm_...`)
        ?string $customerToken, // optional `cus_...` for stored customers
        array $metadata = [],
    ): GatewayChargeResult;

    /**
     * Refund an existing charge. Pass null amount for full refund.
     */
    public function refund(string $providerChargeId, ?float $amount = null, array $metadata = []): GatewayRefundResult;

    /**
     * Verify a webhook signature. Returns the parsed event payload, or
     * null if the signature is invalid.
     *
     * @return array<string, mixed>|null
     */
    public function verifyAndParseWebhook(string $rawPayload, string $signatureHeader): ?array;
}
