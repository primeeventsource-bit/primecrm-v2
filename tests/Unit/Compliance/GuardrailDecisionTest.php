<?php

declare(strict_types=1);

use App\Modules\Compliance\Domain\Enums\GuardrailRejectionCode;
use App\Modules\Compliance\Domain\ValueObjects\GuardrailDecision;

it('builds an allow decision with empty rejection state', function () {
    $d = GuardrailDecision::allow(['foo' => 'bar']);

    expect($d->isAllowed())->toBeTrue();
    expect($d->isRejected())->toBeFalse();
    expect($d->rejectionCode)->toBeNull();
    expect($d->metadata)->toBe(['foo' => 'bar']);
});

it('builds a reject decision with code and reason', function () {
    $d = GuardrailDecision::reject(
        GuardrailRejectionCode::DncFederal,
        'On federal DNC list.',
        ['source' => 'federal_dnc'],
    );

    expect($d->isAllowed())->toBeFalse();
    expect($d->rejectionCode)->toBe(GuardrailRejectionCode::DncFederal);
    expect($d->reason)->toBe('On federal DNC list.');
    expect($d->category())->toBe('dnc');
});

it('serializes to a wire-friendly array', function () {
    $d = GuardrailDecision::reject(
        GuardrailRejectionCode::FrequencyTooSoon,
        'too soon',
        ['min_seconds_required' => 14400],
    );

    expect($d->toArray())->toMatchArray([
        'allowed' => false,
        'rejection_code' => 'frequency_too_soon',
        'category' => 'frequency',
        'reason' => 'too soon',
    ]);
});

it('categorizes rejection codes correctly', function () {
    expect(GuardrailRejectionCode::DncFederal->category())->toBe('dnc');
    expect(GuardrailRejectionCode::ConsentMissing->category())->toBe('consent');
    expect(GuardrailRejectionCode::FrequencyDailyCap->category())->toBe('frequency');
    expect(GuardrailRejectionCode::OutsideCallingWindow->category())->toBe('window');
    expect(GuardrailRejectionCode::BlockedHoliday->category())->toBe('window');
    expect(GuardrailRejectionCode::LeadStatusTerminal->category())->toBe('lead_state');
    expect(GuardrailRejectionCode::BadNumber->category())->toBe('lead_state');
});
