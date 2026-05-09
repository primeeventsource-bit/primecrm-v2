<?php

declare(strict_types=1);

use App\Modules\CallCenter\Domain\Models\Call;
use App\Modules\CallCenter\Infrastructure\Telephony\TelephonyProvider;
use App\Modules\Compliance\Domain\Enums\ConsentType;
use App\Modules\Dialer\Domain\Models\DialSession;
use App\Support\Enums\UserRole;
use Database\Factories\ConsentRecordFactory;
use Database\Factories\DncEntryFactory;
use Database\Factories\LeadFactory;
use Illuminate\Support\Facades\Redis;
use Tests\Support\FakeTelephonyProvider;

beforeEach(function () {
    $this->user = $this->actingAsUser(role: UserRole::Agent);
    $this->telephony = new FakeTelephonyProvider;
    $this->app->instance(TelephonyProvider::class, $this->telephony);
    Redis::connection('dialer')->flushdb();

    config(['telephony.providers.twilio.from_number' => '+15555550000']);
    config(['telephony.providers.twilio.webhook_base_url' => 'https://test.example.com']);
    \Database\Factories\CallingWindowFactory::new()->create();
});

it('starts a session via POST /api/dialer/sessions', function () {
    $response = $this->postJson('/api/dialer/sessions');

    $response->assertCreated();
    $response->assertJsonPath('data.status', DialSession::STATUS_ACTIVE);
    $response->assertJsonPath('data.agent_id', $this->user->id);
});

it('GET /api/dialer/sessions/active returns the live session', function () {
    $this->postJson('/api/dialer/sessions')->assertCreated();

    $response = $this->getJson('/api/dialer/sessions/active');

    $response->assertOk();
    $response->assertJsonPath('data.agent_id', $this->user->id);
});

it('dial-now blocks when the guardrail rejects (DNC list)', function () {
    $session = $this->postJson('/api/dialer/sessions')->json('data');

    $lead = LeadFactory::new()->withPhone('+14155558844')->create();
    DncEntryFactory::new()->forPhone('+14155558844')->create();

    $response = $this->postJson("/api/dialer/sessions/{$session['id']}/dial-now", [
        'lead_id' => $lead->id,
    ]);

    $response->assertStatus(422);
    $response->assertJsonPath('decision.allowed', false);
    expect($this->telephony->placedCalls)->toBeEmpty();
});

it('dial-now succeeds and queues a DialLeadJob when guardrail allows', function () {
    $session = $this->postJson('/api/dialer/sessions')->json('data');

    $lead = LeadFactory::new()->withPhone('+14155557755')->create(['state' => 'NY']);
    ConsentRecordFactory::new()
        ->forPhone('+14155557755')
        ->ofType(ConsentType::Autodialer)
        ->create();

    $response = $this->postJson("/api/dialer/sessions/{$session['id']}/dial-now", [
        'lead_id' => $lead->id,
    ]);

    $response->assertStatus(202);
    $response->assertJsonPath('queued', true);

    // QUEUE_CONNECTION=sync in tests → DialLeadJob already ran.
    expect($this->telephony->placedCalls)->toHaveCount(1);
    expect(Call::query()->count())->toBe(1);
});

it('refuses to operate on another agent\'s session unless supervisor', function () {
    $session = $this->postJson('/api/dialer/sessions')->json('data');

    // Switch to a different non-supervisor agent
    $this->actingAsUser(role: UserRole::Agent);

    $this->postJson("/api/dialer/sessions/{$session['id']}/pause")
        ->assertStatus(403);
});
