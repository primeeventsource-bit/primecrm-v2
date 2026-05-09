<?php

declare(strict_types=1);

namespace App\Modules\CallCenter\Http\Controllers;

use App\Modules\CallCenter\Application\Jobs\ProcessTwilioRecordingWebhookJob;
use App\Modules\CallCenter\Application\Jobs\ProcessTwilioStatusWebhookJob;
use App\Modules\CallCenter\Application\Services\WebhookEventStore;
use App\Modules\CallCenter\Infrastructure\Telephony\TelephonyProvider;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * Twilio webhook receivers.
 *
 * These endpoints are PUBLIC (no auth middleware) — Twilio doesn't carry
 * a bearer token. Instead, every request is signature-verified against
 * the configured auth token. The request URL must match what Twilio
 * signed, so reverse-proxy setups need to preserve the original host.
 *
 * The controller does the absolute minimum: verify, ingest to
 * webhook_events for idempotency, dispatch a job, return 200. Twilio
 * retries up to ~5 times if we don't ack within ~15 seconds, but we
 * shouldn't be relying on that headroom.
 *
 * Voice URL (POST /webhooks/twilio/voice/{callId}):
 *   Twilio asks "what TwiML do I run for this leg?". We return TwiML
 *   that bridges the called party to the assigned agent's softphone.
 *   The TwiML lives outside this controller for testability.
 */
final class TwilioWebhookController extends Controller
{
    public function __construct(
        private readonly TelephonyProvider $provider,
        private readonly WebhookEventStore $store,
    ) {}

    /**
     * POST /webhooks/twilio/voice/{callId}
     *
     * Returns TwiML that connects the call to the agent's softphone via
     * a Twilio Client identity. The agent's identity is "agent-{userId}".
     */
    public function voice(Request $request, string $callId): Response
    {
        $this->guardSignature($request);

        $call = \App\Modules\CallCenter\Domain\Models\Call::query()
            ->withoutTenantScope()
            ->find($callId);

        if ($call === null || $call->agent_id === null) {
            return response(<<<'XML'
                <?xml version="1.0" encoding="UTF-8"?>
                <Response><Say>This call cannot be connected. Goodbye.</Say><Hangup/></Response>
                XML, 200, ['Content-Type' => 'application/xml']);
        }

        $agentClient = "agent-{$call->agent_id}";

        $xml = <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <Response>
                <Dial answerOnBridge="true" timeout="20">
                    <Client>{$agentClient}</Client>
                </Dial>
            </Response>
            XML;

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    /**
     * POST /webhooks/twilio/status/{callId}
     */
    public function status(Request $request, string $callId): Response
    {
        $this->guardSignature($request);

        $payload = $request->all();
        $sid = (string) ($payload['CallSid'] ?? '');
        $callStatus = (string) ($payload['CallStatus'] ?? 'unknown');

        if ($sid === '') {
            return response('missing CallSid', 400);
        }

        $event = $this->store->ingest(
            provider: 'twilio',
            externalId: "call-status:{$sid}:{$callStatus}",
            eventType: "call.{$callStatus}",
            payload: $payload,
            headers: $this->captureHeaders($request),
        );

        if ($event === null) {
            return response('OK', 200); // already processed
        }

        ProcessTwilioStatusWebhookJob::dispatch($event->id, $callId);

        return response('OK', 200);
    }

    /**
     * POST /webhooks/twilio/recording/{callId}
     */
    public function recording(Request $request, string $callId): Response
    {
        $this->guardSignature($request);

        $payload = $request->all();
        $recordingSid = (string) ($payload['RecordingSid'] ?? '');
        $status = (string) ($payload['RecordingStatus'] ?? 'unknown');

        if ($recordingSid === '') {
            return response('missing RecordingSid', 400);
        }

        $event = $this->store->ingest(
            provider: 'twilio',
            externalId: "recording:{$recordingSid}:{$status}",
            eventType: "recording.{$status}",
            payload: $payload,
            headers: $this->captureHeaders($request),
        );

        if ($event === null) {
            return response('OK', 200);
        }

        ProcessTwilioRecordingWebhookJob::dispatch($event->id, $callId);

        return response('OK', 200);
    }

    private function guardSignature(Request $request): void
    {
        $signature = (string) $request->header('X-Twilio-Signature', '');
        $url = $request->fullUrl();
        $params = $request->post();

        if (! $this->provider->verifyWebhookSignature($signature, $url, $params)) {
            abort(403, 'Invalid Twilio signature');
        }
    }

    /**
     * @return array<string, string>
     */
    private function captureHeaders(Request $request): array
    {
        // Only capture what's useful for re-verification or debugging — never
        // the raw body, never auth headers (we don't have any but defensive).
        return array_filter([
            'X-Twilio-Signature' => $request->header('X-Twilio-Signature'),
            'User-Agent' => $request->header('User-Agent'),
            'Idempotency-Key' => $request->header('I-Twilio-Idempotency-Token'),
        ]);
    }
}
