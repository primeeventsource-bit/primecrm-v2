<?php

declare(strict_types=1);

use App\Modules\CallCenter\Domain\Models\Call;
use App\Modules\CallCenter\Domain\Models\CallEvent;
use App\Modules\CallCenter\Domain\Models\WebhookEvent;
use App\Modules\CallCenter\Infrastructure\Telephony\TelephonyProvider;
use Database\Factories\CallFactory;
use Tests\Support\FakeTelephonyProvider;

beforeEach(function () {
    $this->actingAsTenant();
    $this->telephony = new FakeTelephonyProvider;
    $this->app->instance(TelephonyProvider::class, $this->telephony);
});

it('accepts a Twilio status webhook and dispatches one processing job', function () {
    $call = CallFactory::new()->ringing()->create([
        'provider_call_sid' => 'CAabc123',
    ]);

    $payload = [
        'CallSid' => 'CAabc123',
        'CallStatus' => 'in-progress',
        'From' => $call->from_number,
        'To' => $call->to_number,
    ];

    $response = $this->postJson("/webhooks/twilio/status/{$call->id}", $payload, [
        'X-Twilio-Signature' => 'fake-but-fake-provider-accepts-anything',
    ]);

    $response->assertOk();

    expect(WebhookEvent::query()->count())->toBe(1);
    expect(WebhookEvent::query()->first()->external_id)->toBe('call-status:CAabc123:in-progress');
});

it('dedups a duplicate webhook with the same external_id', function () {
    $call = CallFactory::new()->ringing()->create([
        'provider_call_sid' => 'CAdup456',
    ]);

    $payload = [
        'CallSid' => 'CAdup456',
        'CallStatus' => 'completed',
        'CallDuration' => '180',
    ];

    // First arrival
    $this->postJson("/webhooks/twilio/status/{$call->id}", $payload, ['X-Twilio-Signature' => 'x'])
        ->assertOk();

    // Process the queued job synchronously (QUEUE_CONNECTION=sync in tests)
    // by re-running it manually if needed. With sync queue the controller-
    // dispatched job already ran. Verify state.
    expect(WebhookEvent::query()->count())->toBe(1);
    $event = WebhookEvent::query()->first();
    expect($event->status)->toBe(WebhookEvent::STATUS_PROCESSED);

    // Second arrival — duplicate. Same payload. The store should ingest()
    // return null (already processed), so no new row.
    $this->postJson("/webhooks/twilio/status/{$call->id}", $payload, ['X-Twilio-Signature' => 'x'])
        ->assertOk();

    expect(WebhookEvent::query()->count())->toBe(1);
});

it('rejects a webhook with an invalid signature when verification is on', function () {
    $call = CallFactory::new()->ringing()->create();
    $this->telephony->signaturesValid = false;

    $this->postJson("/webhooks/twilio/status/{$call->id}", [
        'CallSid' => 'CAxyz',
        'CallStatus' => 'completed',
    ], ['X-Twilio-Signature' => 'wrong'])->assertStatus(403);

    expect(WebhookEvent::query()->count())->toBe(0);
});

it('writes one call_event per webhook even with retries', function () {
    $call = CallFactory::new()->ringing()->create([
        'provider_call_sid' => 'CAretry789',
    ]);

    $payload = [
        'CallSid' => 'CAretry789',
        'CallStatus' => 'completed',
        'CallDuration' => '60',
    ];

    // Same logical webhook arrives 3 times.
    for ($i = 0; $i < 3; $i++) {
        $this->postJson("/webhooks/twilio/status/{$call->id}", $payload, ['X-Twilio-Signature' => 'x'])
            ->assertOk();
    }

    // Exactly one webhook_event row, exactly one call_event row for "ended".
    expect(WebhookEvent::query()->count())->toBe(1);
    $endedEvents = CallEvent::query()
        ->where('call_id', $call->id)
        ->where('event_type', 'ended')
        ->count();
    expect($endedEvents)->toBe(1);
});
