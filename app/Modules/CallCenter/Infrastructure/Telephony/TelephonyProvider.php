<?php

declare(strict_types=1);

namespace App\Modules\CallCenter\Infrastructure\Telephony;

use App\Modules\CallCenter\Domain\ValueObjects\TwilioCallSnapshot;

/**
 * Provider-agnostic outbound call interface.
 *
 * The dialer talks to *this*, never to the Twilio SDK directly.
 * Adding Telnyx/Plivo/SIP later means a second implementation, not
 * a refactor of every job.
 */
interface TelephonyProvider
{
    /**
     * Initiate an outbound call.
     *
     * @param  array<string, mixed>  $extras  provider-specific overrides (machine detection, recording, etc)
     */
    public function placeCall(
        string $from,
        string $to,
        string $voiceUrl,
        string $statusCallbackUrl,
        ?string $recordingStatusCallbackUrl = null,
        array $extras = [],
    ): TwilioCallSnapshot;

    public function endCall(string $providerCallSid): void;

    /**
     * Pause/resume an active recording — used during PCI-sensitive
     * card capture. Returns true if the provider acknowledged the change.
     */
    public function pauseRecording(string $providerCallSid): bool;

    public function resumeRecording(string $providerCallSid): bool;

    /**
     * Download recording bytes from provider. Returns a stream resource —
     * the caller is responsible for closing it.
     *
     * @return resource
     */
    public function downloadRecording(string $providerRecordingSid);

    /**
     * Verify a webhook signature against the request URL and posted form
     * fields. The HTTP layer hands raw values; the provider checks them.
     *
     * @param  array<string, mixed>  $params
     */
    public function verifyWebhookSignature(string $signature, string $url, array $params): bool;
}
