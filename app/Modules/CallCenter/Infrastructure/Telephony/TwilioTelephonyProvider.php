<?php

declare(strict_types=1);

namespace App\Modules\CallCenter\Infrastructure\Telephony;

use App\Modules\CallCenter\Domain\ValueObjects\TwilioCallSnapshot;
use Twilio\Rest\Client as TwilioClient;
use Twilio\Security\RequestValidator;

/**
 * Twilio implementation of {@see TelephonyProvider}.
 *
 * The Twilio SDK is wrapped here so the rest of the system never
 * imports `Twilio\*`. Tests substitute a fake provider via the
 * container binding in CallCenterServiceProvider.
 */
final class TwilioTelephonyProvider implements TelephonyProvider
{
    public function __construct(
        private readonly TwilioClient $client,
        private readonly string $authToken,
        private readonly bool $verifySignatures,
        private readonly bool $recordingEnabled,
        private readonly ?string $machineDetection,
    ) {}

    public function placeCall(
        string $from,
        string $to,
        string $voiceUrl,
        string $statusCallbackUrl,
        ?string $recordingStatusCallbackUrl = null,
        array $extras = [],
    ): TwilioCallSnapshot {
        $params = array_filter([
            'url' => $voiceUrl,
            'statusCallback' => $statusCallbackUrl,
            'statusCallbackEvent' => ['initiated', 'ringing', 'answered', 'completed'],
            'statusCallbackMethod' => 'POST',
            'machineDetection' => $this->machineDetection,
            'record' => $this->recordingEnabled,
            'recordingStatusCallback' => $this->recordingEnabled ? $recordingStatusCallbackUrl : null,
            'recordingStatusCallbackEvent' => $this->recordingEnabled ? ['completed', 'absent'] : null,
            'timeout' => $extras['timeout'] ?? 30,
        ], static fn ($v) => $v !== null);

        $call = $this->client->calls->create($to, $from, $params);

        return new TwilioCallSnapshot(
            sid: $call->sid,
            status: $call->status,
            direction: $call->direction,
            from: (string) $call->from,
            to: (string) $call->to,
            parentCallSid: $call->parentCallSid,
            price: $call->price !== null ? (float) $call->price : null,
            priceCurrency: $call->priceUnit,
            raw: $call->toArray(),
        );
    }

    public function endCall(string $providerCallSid): void
    {
        $this->client->calls($providerCallSid)->update(['status' => 'completed']);
    }

    public function pauseRecording(string $providerCallSid): bool
    {
        $recordings = $this->client->calls($providerCallSid)->recordings->read([], 1);
        if (empty($recordings)) {
            return false;
        }
        $this->client->calls($providerCallSid)
            ->recordings($recordings[0]->sid)
            ->update(['status' => 'paused', 'pauseBehavior' => 'silence']);

        return true;
    }

    public function resumeRecording(string $providerCallSid): bool
    {
        $recordings = $this->client->calls($providerCallSid)->recordings->read(['status' => 'paused'], 1);
        if (empty($recordings)) {
            return false;
        }
        $this->client->calls($providerCallSid)
            ->recordings($recordings[0]->sid)
            ->update(['status' => 'in-progress']);

        return true;
    }

    public function downloadRecording(string $providerRecordingSid)
    {
        // Twilio recordings live at https://api.twilio.com/.../Recordings/{Sid}.mp3
        // The SDK's Recording resource exposes the MediaUrl; we stream the
        // download via an HTTP GET with the account credentials so we can
        // upload directly to S3 without buffering the whole file in memory.
        $accountSid = $this->client->getAccountSid();
        $url = "https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Recordings/{$providerRecordingSid}.mp3";

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => 'Authorization: Basic '.base64_encode($accountSid.':'.$this->authToken),
                'timeout' => 60,
            ],
        ]);

        $stream = fopen($url, 'rb', false, $context);

        if ($stream === false) {
            throw new \RuntimeException("Failed to open recording stream for {$providerRecordingSid}");
        }

        return $stream;
    }

    public function verifyWebhookSignature(string $signature, string $url, array $params): bool
    {
        if (! $this->verifySignatures) {
            // Local dev / test mode — explicit opt-out via TWILIO_VERIFY_SIGNATURE=false.
            return true;
        }

        $validator = new RequestValidator($this->authToken);

        return $validator->validate($signature, $url, $params);
    }
}
