<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\CallCenter\Domain\ValueObjects\TwilioCallSnapshot;
use App\Modules\CallCenter\Infrastructure\Telephony\TelephonyProvider;

/**
 * Test double for the telephony provider.
 *
 * Captures every call placed for assertion. Optionally throws on placeCall
 * for failure-path tests. Default verifyWebhookSignature returns whatever
 * `signaturesValid` is set to.
 */
final class FakeTelephonyProvider implements TelephonyProvider
{
    /** @var list<array{from: string, to: string, voiceUrl: string, statusUrl: string}> */
    public array $placedCalls = [];

    /** @var list<string> */
    public array $endedCalls = [];

    public bool $signaturesValid = true;

    public ?\Throwable $throwOnPlace = null;

    public string $nextSid = 'CA00000000000000000000000000000001';

    public function placeCall(
        string $from,
        string $to,
        string $voiceUrl,
        string $statusCallbackUrl,
        ?string $recordingStatusCallbackUrl = null,
        array $extras = [],
    ): TwilioCallSnapshot {
        if ($this->throwOnPlace !== null) {
            throw $this->throwOnPlace;
        }

        $this->placedCalls[] = [
            'from' => $from,
            'to' => $to,
            'voiceUrl' => $voiceUrl,
            'statusUrl' => $statusCallbackUrl,
        ];

        $sid = $this->nextSid;
        // Bump for next call so each placed call gets a unique SID
        $this->nextSid = 'CA'.str_pad((string) (((int) substr($sid, 2)) + 1), 32, '0', STR_PAD_LEFT);

        return new TwilioCallSnapshot(
            sid: $sid,
            status: 'queued',
            direction: 'outbound-api',
            from: $from,
            to: $to,
            parentCallSid: null,
            price: null,
            priceCurrency: null,
            raw: ['Sid' => $sid, 'Status' => 'queued'],
        );
    }

    public function endCall(string $providerCallSid): void
    {
        $this->endedCalls[] = $providerCallSid;
    }

    public function pauseRecording(string $providerCallSid): bool
    {
        return true;
    }

    public function resumeRecording(string $providerCallSid): bool
    {
        return true;
    }

    public function downloadRecording(string $providerRecordingSid)
    {
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, "fake-recording-bytes-for-{$providerRecordingSid}");
        rewind($stream);

        return $stream;
    }

    public function verifyWebhookSignature(string $signature, string $url, array $params): bool
    {
        return $this->signaturesValid;
    }

    public function reset(): void
    {
        $this->placedCalls = [];
        $this->endedCalls = [];
        $this->signaturesValid = true;
        $this->throwOnPlace = null;
        $this->nextSid = 'CA00000000000000000000000000000001';
    }
}
