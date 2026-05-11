<?php

declare(strict_types=1);

namespace App\Modules\Listing\Application\Services;

use App\Modules\Listing\Domain\Models\PartnerSite;
use App\Modules\Listing\Domain\Models\PartnerWebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Records partner-webhook attempts to the audit log.
 *
 * The webhook controllers call record() at every exit point so we
 * never lose an attempt — even sig failures, which are the events
 * the operator most needs to see (silent integration outages almost
 * always start there).
 *
 * Best-effort: a DB failure recording the event MUST NOT take down
 * the webhook handler. We catch + swallow. The price of dropping a
 * log row is lower than the price of failing a partner delivery and
 * triggering retries.
 */
final class PartnerWebhookEventLogger
{
    /**
     * @param  'inquiry'|'booking'  $kind
     */
    public function record(
        Request $request,
        PartnerSite $site,
        string $kind,
        int $httpStatus,
        bool $signatureValid,
        ?string $externalInquiryId = null,
        ?string $externalBookingId = null,
        ?string $relatedId = null,
        ?string $errorMessage = null,
    ): void {
        try {
            // user_agent comes in as nullable string. Truncate to fit
            // the column — some bots send absurdly long headers.
            $userAgent = $request->userAgent();
            if (is_string($userAgent) && strlen($userAgent) > 500) {
                $userAgent = substr($userAgent, 0, 500);
            }

            $payloadBytes = strlen($request->getContent());

            PartnerWebhookEvent::query()->create([
                'tenant_id' => $site->tenant_id,
                'partner_site_id' => $site->id,
                'kind' => $kind,
                'http_status' => $httpStatus,
                'signature_valid' => $signatureValid,
                'external_inquiry_id' => $externalInquiryId,
                'external_booking_id' => $externalBookingId,
                'related_id' => $relatedId,
                'error_message' => $errorMessage !== null
                    ? mb_substr($errorMessage, 0, 4000)
                    : null,
                'request_ip' => $request->ip(),
                'user_agent' => $userAgent,
                'payload_size_bytes' => $payloadBytes,
                'created_at' => Carbon::now(),
            ]);
        } catch (Throwable) {
            // Swallowed. See class docblock — logging must never
            // affect the webhook handler's HTTP response.
        }
    }
}
