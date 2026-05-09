<?php

declare(strict_types=1);

use App\Modules\Compliance\Application\Actions\RecordConsentAction;
use App\Modules\Compliance\Domain\Enums\ConsentType;
use App\Modules\Lead\Domain\Models\Lead;
use Database\Factories\LeadFactory;

beforeEach(function () {
    $this->actingAsTenant();
});

it('flips lead.has_express_consent when an autodialer consent is recorded', function () {
    $lead = LeadFactory::new()->withPhone('+14155551212')->create();
    expect($lead->has_express_consent)->toBeFalse();

    app(RecordConsentAction::class)->execute(
        rawPhone: '+14155551212',
        consentType: ConsentType::Autodialer,
        source: 'web_form',
        sourceUrl: 'https://example.com/quote',
        sourceIp: '203.0.113.5',
        userAgent: 'Mozilla/5.0',
        consentTextSnapshot: ['version' => '1', 'text' => '…'],
    );

    expect($lead->fresh()->has_express_consent)->toBeTrue();
    expect($lead->fresh()->consent_at)->not->toBeNull();
});

it('does not flip the flag for non-autodialer consents', function () {
    $lead = LeadFactory::new()->withPhone('+14155551313')->create();

    app(RecordConsentAction::class)->execute(
        rawPhone: '+14155551313',
        consentType: ConsentType::Sms,
        source: 'web_form',
        sourceUrl: 'https://example.com',
        sourceIp: '203.0.113.5',
        userAgent: 'UA',
    );

    expect($lead->fresh()->has_express_consent)->toBeFalse();
});

it('clears the lead flag when the only active autodialer consent is revoked', function () {
    $lead = LeadFactory::new()->withPhone('+14155551414')->create();

    $consent = app(RecordConsentAction::class)->execute(
        rawPhone: '+14155551414',
        consentType: ConsentType::Autodialer,
        source: 'web_form',
        sourceUrl: 'https://example.com',
        sourceIp: '203.0.113.5',
        userAgent: 'UA',
    );

    expect($lead->fresh()->has_express_consent)->toBeTrue();

    app(RecordConsentAction::class)->revoke($consent->id, 'User asked us to stop calling');

    expect($lead->fresh()->has_express_consent)->toBeFalse();
});
