<?php

declare(strict_types=1);

use App\Modules\CallCenter\Application\Services\CallStateService;
use App\Modules\CallCenter\Domain\Events\CallConnected;
use App\Modules\CallCenter\Domain\Events\CallEnded;
use App\Modules\CallCenter\Domain\Events\CallInitiated;
use App\Modules\CallCenter\Domain\Models\CallEvent;
use App\Support\Enums\CallStatus;
use Database\Factories\CallFactory;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->actingAsTenant();
});

it('marks a call initiated and stamps the provider SID', function () {
    Event::fake();
    $call = CallFactory::new()->create(['provider_call_sid' => null, 'status' => CallStatus::Queued->value]);

    app(CallStateService::class)->markInitiated($call, 'CAtestSid', ['raw' => 'data']);

    $fresh = $call->fresh();
    expect($fresh->status)->toBe(CallStatus::Initiated);
    expect($fresh->provider_call_sid)->toBe('CAtestSid');
    expect($fresh->initiated_at)->not->toBeNull();

    Event::assertDispatched(CallInitiated::class);
});

it('promotes from ringing to in_progress and dispatches CallConnected', function () {
    Event::fake();
    $call = CallFactory::new()->ringing()->create();

    app(CallStateService::class)->markAnswered($call, 'twilio:CAx:in-progress');

    $fresh = $call->fresh();
    expect($fresh->status)->toBe(CallStatus::InProgress);
    expect($fresh->answered_at)->not->toBeNull();
    expect($fresh->ring_seconds)->toBeGreaterThanOrEqual(0);

    Event::assertDispatched(CallConnected::class);
});

it('does NOT downgrade a call from in_progress back to ringing', function () {
    $call = CallFactory::new()->answered()->create();

    app(CallStateService::class)->markRinging($call, 'twilio:CAlate:ringing');

    expect($call->fresh()->status)->toBe(CallStatus::InProgress);
});

it('rejects duplicate idempotent events silently', function () {
    $call = CallFactory::new()->ringing()->create();

    $service = app(CallStateService::class);
    $service->markAnswered($call, 'twilio:CAdup:in-progress');
    $service->markAnswered($call, 'twilio:CAdup:in-progress');

    $events = CallEvent::query()->where('call_id', $call->id)->where('event_type', 'answered')->count();
    expect($events)->toBe(1);
});

it('marks a call ended and dispatches CallEnded with the previous status', function () {
    Event::fake();
    $call = CallFactory::new()->answered()->create();

    app(CallStateService::class)->markEnded($call, CallStatus::Completed, 'twilio:CAend:completed');

    expect($call->fresh()->status)->toBe(CallStatus::Completed);
    expect($call->fresh()->ended_at)->not->toBeNull();

    Event::assertDispatched(CallEnded::class, fn (CallEnded $e) => $e->previousStatus === CallStatus::InProgress->value);
});
