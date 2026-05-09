<?php

declare(strict_types=1);

namespace App\Modules\CallCenter\Domain\ValueObjects;

/**
 * Provider-agnostic shape of an outbound call response.
 *
 * Today this is what Twilio's PHP SDK returns wrapped into our own type
 * so callers don't depend on Twilio\Rest\Api\V2010\Account\CallInstance.
 * When Telnyx is added (config supports it; SDK switch lives in
 * CallProviderClient::resolve()), the same shape comes out — the rest
 * of the system doesn't care which provider placed the call.
 */
final class TwilioCallSnapshot
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly string $sid,
        public readonly string $status,         // queued, ringing, in-progress, completed, busy, no-answer, failed, canceled
        public readonly ?string $direction,
        public readonly string $from,
        public readonly string $to,
        public readonly ?string $parentCallSid,
        public readonly ?float $price,
        public readonly ?string $priceCurrency,
        public readonly array $raw,
    ) {}
}
