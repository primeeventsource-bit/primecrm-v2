<?php

declare(strict_types=1);

use App\Modules\CallCenter\Domain\Models\Call;
use App\Modules\CallCenter\Infrastructure\Telephony\TelephonyProvider;
use App\Modules\Compliance\Domain\Enums\ConsentType;
use App\Modules\Dialer\Application\Jobs\DialLeadJob;
use App\Modules\Dialer\Domain\Events\DialSkipped;
use App\Support\Enums\AgentStatus;
use Database\Factories\ConsentRecordFactory;
use Database\Factories\DncEntryFactory;
use Database\Factories\LeadFactory;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Tests\Support\FakeTelephonyProvider;

/**
 * THE structural property: every outbound call goes through the
 * compliance guardrail. These tests assert that there is no path through
 * DialLeadJob (which is the chokepoint) that places a Twilio call when
 * the guardrail rejects.
 */
beforeEach(function () {
    $this->actingAsTenant();
    $this->telephony = new FakeTelephonyProvider;
    $this->app->instance(TelephonyProvider::class, $this->telephony);
    Redis::connection('dialer')->flushdb();

    config(['telephony.providers.twilio.from_number' => '+15555550000']);
    config(['telephony.providers.twilio.webhook_base_url' => 'https://test.example.com']);
    \Database\Factories\CallingWindowFactory::new()->create();
    \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-05-13 18:00:00', 'UTC'));
});

afterEach(function () {
    \Carbon\Carbon::setTestNow();
});

it('does NOT place a call when the lead is on the federal DNC list', function () {
    $lead = LeadFactory::new()->withPhone('+14155551234')->create();
    DncEntryFactory::new()->forPhone('+14155551234')->create();

    Event::fake([DialSkipped::class]);

    (new DialLeadJob($lead->id))->handle(
        guardrail: app(\App\Modules\Compliance\Application\Services\ComplianceGuardrailService::class),
        initiate: app(\App\Modules\CallCenter\Application\Actions\InitiateCallAction::class),
        presence: app(\App\Modules\CallCenter\Application\Services\AgentPresenceService::class),
        queue: app(\App\Modules\Dialer\Application\Services\LeadQueueService::class),
        tenantContext: app(\App\Core\Shared\TenantContext::class),
    );

    expect($this->telephony->placedCalls)->toBeEmpty();
    expect(Call::query()->count())->toBe(0);
    Event::assertDispatched(DialSkipped::class);
});

it('does NOT place a call when consent is missing for autodial', function () {
    $lead = LeadFactory::new()->withPhone('+14155551111')->create();

    Event::fake([DialSkipped::class]);

    (new DialLeadJob($lead->id, dialerMode: 'predictive'))->handle(
        guardrail: app(\App\Modules\Compliance\Application\Services\ComplianceGuardrailService::class),
        initiate: app(\App\Modules\CallCenter\Application\Actions\InitiateCallAction::class),
        presence: app(\App\Modules\CallCenter\Application\Services\AgentPresenceService::class),
        queue: app(\App\Modules\Dialer\Application\Services\LeadQueueService::class),
        tenantContext: app(\App\Core\Shared\TenantContext::class),
    );

    expect($this->telephony->placedCalls)->toBeEmpty();
    expect(Call::query()->count())->toBe(0);
    Event::assertDispatched(DialSkipped::class, function (DialSkipped $event) {
        return $event->rejectionCode === 'consent_missing';
    });
});

it('places exactly one call when guardrail allows AND an agent is available', function () {
    $lead = LeadFactory::new()->withPhone('+14155552222')->create(['state' => 'NY']);

    ConsentRecordFactory::new()
        ->forPhone('+14155552222')
        ->ofType(ConsentType::Autodialer)
        ->create();

    $agent = UserFactory::new()->agent()->create();
    app(\App\Modules\CallCenter\Application\Services\AgentPresenceService::class)
        ->set($agent->id, AgentStatus::Available);

    (new DialLeadJob($lead->id, agentIdHint: $agent->id, dialerMode: 'predictive'))->handle(
        guardrail: app(\App\Modules\Compliance\Application\Services\ComplianceGuardrailService::class),
        initiate: app(\App\Modules\CallCenter\Application\Actions\InitiateCallAction::class),
        presence: app(\App\Modules\CallCenter\Application\Services\AgentPresenceService::class),
        queue: app(\App\Modules\Dialer\Application\Services\LeadQueueService::class),
        tenantContext: app(\App\Core\Shared\TenantContext::class),
    );

    expect($this->telephony->placedCalls)->toHaveCount(1);
    expect($this->telephony->placedCalls[0]['to'])->toBe('+14155552222');

    $call = Call::query()->first();
    expect($call)->not->toBeNull();
    expect($call->lead_id)->toBe($lead->id);
    expect($call->agent_id)->toBe($agent->id);
    expect($call->provider_call_sid)->not->toBeNull();
});

it('does NOT place a call when no agent is available in any mode', function () {
    $lead = LeadFactory::new()->withPhone('+14155553333')->create(['state' => 'NY']);

    ConsentRecordFactory::new()
        ->forPhone('+14155553333')
        ->ofType(ConsentType::Autodialer)
        ->create();

    Event::fake([DialSkipped::class]);

    (new DialLeadJob($lead->id, dialerMode: 'predictive'))->handle(
        guardrail: app(\App\Modules\Compliance\Application\Services\ComplianceGuardrailService::class),
        initiate: app(\App\Modules\CallCenter\Application\Actions\InitiateCallAction::class),
        presence: app(\App\Modules\CallCenter\Application\Services\AgentPresenceService::class),
        queue: app(\App\Modules\Dialer\Application\Services\LeadQueueService::class),
        tenantContext: app(\App\Core\Shared\TenantContext::class),
    );

    expect($this->telephony->placedCalls)->toBeEmpty();
    Event::assertDispatched(DialSkipped::class);
});

it('does NOT place a call when the calling window has closed (transient rejection)', function () {
    \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-05-13 03:00:00', 'UTC')); // 11pm ET

    $lead = LeadFactory::new()
        ->withPhone('+12125559999') // NY area code
        ->create(['state' => 'NY', 'timezone' => 'America/New_York']);

    ConsentRecordFactory::new()
        ->forPhone('+12125559999')
        ->ofType(ConsentType::Autodialer)
        ->create();

    Event::fake([DialSkipped::class]);

    (new DialLeadJob($lead->id, dialerMode: 'predictive'))->handle(
        guardrail: app(\App\Modules\Compliance\Application\Services\ComplianceGuardrailService::class),
        initiate: app(\App\Modules\CallCenter\Application\Actions\InitiateCallAction::class),
        presence: app(\App\Modules\CallCenter\Application\Services\AgentPresenceService::class),
        queue: app(\App\Modules\Dialer\Application\Services\LeadQueueService::class),
        tenantContext: app(\App\Core\Shared\TenantContext::class),
    );

    expect($this->telephony->placedCalls)->toBeEmpty();
    Event::assertDispatched(DialSkipped::class, function (DialSkipped $event) {
        return $event->rejectionCode === 'outside_calling_window';
    });
});
