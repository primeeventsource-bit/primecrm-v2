<?php

declare(strict_types=1);

use App\Modules\Lead\Application\Actions\CreateLeadAction;
use App\Modules\Lead\Application\DTOs\LeadInputData;
use App\Modules\Lead\Application\Services\LeadDedupService;
use App\Modules\Lead\Domain\Models\Lead;
use Database\Factories\LeadFactory;

beforeEach(function () {
    $this->actingAsTenant();
});

it('detects an exact phone-hash duplicate', function () {
    LeadFactory::new()->withPhone('+14155551234')->create([
        'first_name' => 'Existing',
        'last_name' => 'Person',
    ]);

    $dedup = app(LeadDedupService::class);
    $result = $dedup->find(new LeadInputData(
        phone: '+14155551234',
        phoneHash: hash('sha256', '+14155551234'),
        firstName: 'New',
        lastName: 'Person',
    ));

    expect($result->isDuplicate)->toBeTrue();
    expect($result->matchType)->toBe('phone_exact');
});

it('detects an exact email duplicate when phone is different', function () {
    LeadFactory::new()
        ->withPhone('+14155551111')
        ->create(['email' => 'shared@example.com']);

    $dedup = app(LeadDedupService::class);
    $result = $dedup->find(new LeadInputData(
        phone: '+14155552222',
        phoneHash: hash('sha256', '+14155552222'),
        email: 'shared@example.com',
    ));

    expect($result->isDuplicate)->toBeTrue();
    expect($result->matchType)->toBe('email_exact');
});

it('does not collapse two same-named leads when there is no co-signal (city/postal)', function () {
    LeadFactory::new()
        ->withPhone('+14155551111')
        ->create([
            'first_name' => 'John',
            'last_name' => 'Smith',
            'email' => null,
            'city' => 'New York',
            'state' => 'NY',
            'postal_code' => '10001',
        ]);

    $dedup = app(LeadDedupService::class);
    $result = $dedup->find(new LeadInputData(
        phone: '+14155553333',
        phoneHash: hash('sha256', '+14155553333'),
        firstName: 'John',
        lastName: 'Smith',
        // Different city — no co-signal.
        city: 'Los Angeles',
        state: 'CA',
        postalCode: '90001',
    ));

    expect($result->isDuplicate)->toBeFalse();
});

it('folds same-named leads when postal_code matches (fuzzy with co-signal)', function () {
    LeadFactory::new()
        ->withPhone('+14155551111')
        ->create([
            'first_name' => 'Jonathan',
            'last_name' => 'Smith',
            'email' => null,
            'postal_code' => '10001',
        ]);

    $dedup = app(LeadDedupService::class);
    $result = $dedup->find(new LeadInputData(
        phone: '+14155553333',
        phoneHash: hash('sha256', '+14155553333'),
        firstName: 'Jonathon', // 1-edit distance from "Jonathan"
        lastName: 'Smith',
        postalCode: '10001',
    ));

    expect($result->matchType)->toBe('fuzzy_name');
    expect($result->confidence)->toBeGreaterThanOrEqual(0.5);
});

it('returns not-duplicate for an entirely fresh lead', function () {
    $dedup = app(LeadDedupService::class);
    $result = $dedup->find(new LeadInputData(
        phone: '+14155558888',
        phoneHash: hash('sha256', '+14155558888'),
        firstName: 'Brand',
        lastName: 'New',
        email: 'new@example.com',
    ));

    expect($result->isDuplicate)->toBeFalse();
    expect($result->matchType)->toBeNull();
});

it('CreateLeadAction merges into the existing record on duplicate without creating a new one', function () {
    LeadFactory::new()
        ->withPhone('+14155557777')
        ->create([
            'first_name' => null,
            'last_name' => null,
            'email' => null,
            'estimated_value' => null,
        ]);

    expect(Lead::query()->count())->toBe(1);

    $action = app(CreateLeadAction::class);
    $result = $action->execute(new LeadInputData(
        phone: '+14155557777',
        phoneHash: hash('sha256', '+14155557777'),
        firstName: 'Merged',
        lastName: 'Name',
        email: 'merged@example.com',
        estimatedValue: 5000,
        source: 'referral',
    ));

    expect($result['was_duplicate'])->toBeTrue();
    expect(Lead::query()->count())->toBe(1);
    expect($result['lead']->first_name)->toBe('Merged');
    expect($result['lead']->email)->toBe('merged@example.com');
    expect((float) $result['lead']->estimated_value)->toBe(5000.0);
});
