<?php

declare(strict_types=1);

use App\Modules\Compliance\Application\Services\ComplianceGuardrailService;
use App\Modules\Compliance\Domain\Enums\ConsentType;
use App\Modules\Compliance\Domain\Enums\DncSource;
use App\Modules\Compliance\Domain\Enums\GuardrailRejectionCode;
use Carbon\Carbon;
use Database\Factories\CallingWindowFactory;
use Database\Factories\ConsentRecordFactory;
use Database\Factories\ContactAttemptFactory;
use Database\Factories\DncEntryFactory;
use Database\Factories\LeadFactory;

beforeEach(function () {
    $this->actingAsTenant();
    // Default federal calling window so the calling-window check has a rule.
    CallingWindowFactory::new()->create();
    // Pin time to a guaranteed-in-window weekday afternoon so window checks pass
    // for the happy-path and other gates' tests aren't accidentally caught
    // by the window gate.
    Carbon::setTestNow(Carbon::parse('2026-05-13 18:00:00', 'UTC')); // Wed 2pm ET
});

afterEach(function () {
    Carbon::setTestNow();
});

it('allows a clean lead through every gate', function () {
    $lead = LeadFactory::new()
        ->withPhone('+14155551234')
        ->withConsent()
        ->create(['state' => 'NY']);

    ConsentRecordFactory::new()
        ->forPhone('+14155551234')
        ->ofType(ConsentType::Autodialer)
        ->create();

    $guardrail = app(ComplianceGuardrailService::class);
    $decision = $guardrail->mayDial($lead);

    expect($decision->isAllowed())->toBeTrue();
});

it('rejects a lead on the federal DNC list', function () {
    $lead = LeadFactory::new()->withPhone('+14155557777')->create();
    DncEntryFactory::new()->forPhone('+14155557777')->create(); // global federal default

    ConsentRecordFactory::new()
        ->forPhone('+14155557777')
        ->ofType(ConsentType::Autodialer)
        ->create();

    $decision = app(ComplianceGuardrailService::class)->mayDial($lead);

    expect($decision->isRejected())->toBeTrue();
    expect($decision->rejectionCode)->toBe(GuardrailRejectionCode::DncFederal);
});

it('rejects a wireless DNC entry when matching tenant scope', function () {
    $tenantId = app(\App\Core\Shared\TenantContext::class)->id();
    $lead = LeadFactory::new()->withPhone('+14155556666')->create();
    DncEntryFactory::new()
        ->forPhone('+14155556666')
        ->forTenant($tenantId)
        ->source(DncSource::WirelessDnc)
        ->create();

    ConsentRecordFactory::new()
        ->forPhone('+14155556666')
        ->ofType(ConsentType::Autodialer)
        ->create();

    $decision = app(ComplianceGuardrailService::class)->mayDial($lead);

    expect($decision->isRejected())->toBeTrue();
    expect($decision->rejectionCode)->toBe(GuardrailRejectionCode::DncWireless);
});

it('rejects autodial without consent', function () {
    $lead = LeadFactory::new()->withPhone('+14155551111')->create();

    $decision = app(ComplianceGuardrailService::class)->mayDial($lead, 'predictive');

    expect($decision->rejectionCode)->toBe(GuardrailRejectionCode::ConsentMissing);
});

it('allows manual mode without consent (different bar)', function () {
    $lead = LeadFactory::new()->withPhone('+14155552222')->create(['state' => 'NY']);

    $decision = app(ComplianceGuardrailService::class)->mayDial($lead, 'manual');

    expect($decision->isAllowed())->toBeTrue();
});

it('rejects when consent has been revoked', function () {
    $lead = LeadFactory::new()->withPhone('+14155553333')->create();

    ConsentRecordFactory::new()
        ->forPhone('+14155553333')
        ->ofType(ConsentType::Autodialer)
        ->revoked()
        ->create();

    $decision = app(ComplianceGuardrailService::class)->mayDial($lead);

    expect($decision->rejectionCode)->toBe(GuardrailRejectionCode::ConsentRevoked);
});

it('rejects on cooldown when last attempt was within 4 hours', function () {
    $lead = LeadFactory::new()->withPhone('+14155554444')->create(['state' => 'NY']);
    ConsentRecordFactory::new()
        ->forPhone('+14155554444')
        ->ofType(ConsentType::Autodialer)
        ->create();

    ContactAttemptFactory::new()
        ->forPhoneHash($lead->phone_hash)
        ->attemptedAt(now()->subMinutes(30))
        ->create();

    $decision = app(ComplianceGuardrailService::class)->mayDial($lead);

    expect($decision->rejectionCode)->toBe(GuardrailRejectionCode::FrequencyTooSoon);
});

it('rejects on daily cap', function () {
    $lead = LeadFactory::new()->withPhone('+14155555555')->create(['state' => 'NY', 'timezone' => 'UTC']);
    ConsentRecordFactory::new()
        ->forPhone('+14155555555')
        ->ofType(ConsentType::Autodialer)
        ->create();

    // 3 attempts today, all > 4 hours apart (older than cooldown)
    foreach ([5, 9, 12] as $hoursAgo) {
        ContactAttemptFactory::new()
            ->forPhoneHash($lead->phone_hash)
            ->attemptedAt(now()->subHours($hoursAgo))
            ->create();
    }

    $decision = app(ComplianceGuardrailService::class)->mayDial($lead);

    expect($decision->rejectionCode)->toBe(GuardrailRejectionCode::FrequencyDailyCap);
});

it('rejects when called outside the calling window', function () {
    Carbon::setTestNow(Carbon::parse('2026-05-13 03:00:00', 'UTC')); // 11pm ET previous day → outside 8am-9pm

    $lead = LeadFactory::new()
        ->withPhone('+12125551234') // NY area code
        ->create(['state' => 'NY', 'timezone' => 'America/New_York']);

    ConsentRecordFactory::new()
        ->forPhone('+12125551234')
        ->ofType(ConsentType::Autodialer)
        ->create();

    $decision = app(ComplianceGuardrailService::class)->mayDial($lead);

    expect($decision->rejectionCode)->toBe(GuardrailRejectionCode::OutsideCallingWindow);
});

it('cheaply rejects leads flagged as on DNC at the model level', function () {
    $lead = LeadFactory::new()->onDnc()->create();

    $decision = app(ComplianceGuardrailService::class)->mayDial($lead);

    expect($decision->rejectionCode)->toBe(GuardrailRejectionCode::LeadOnDncFlag);
});

it('cheaply rejects leads in terminal status without hitting the DB for compliance', function () {
    $lead = LeadFactory::new()->closed()->create();

    $decision = app(ComplianceGuardrailService::class)->mayDial($lead);

    expect($decision->rejectionCode)->toBe(GuardrailRejectionCode::LeadStatusTerminal);
});
