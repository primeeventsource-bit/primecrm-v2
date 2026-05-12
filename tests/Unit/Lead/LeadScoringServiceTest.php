<?php

declare(strict_types=1);

/*
 * LeadScoringService reads its weights from config/leads.php. Even though
 * the service is logically pure, that dependency on the Laravel config
 * helper means we boot the framework — but no DB.
 */
uses(Tests\TestCase::class);

use App\Modules\Lead\Application\Services\LeadScoringService;
use App\Modules\Lead\Domain\Models\Lead;
use App\Support\Enums\LeadPriority;

it('produces a deterministic score from a fully-populated lead', function () {
    $service = new LeadScoringService;

    $lead = makeStubLead([
        'priority' => LeadPriority::Hot,
        'has_express_consent' => true,
        'resort_interest' => 'Westgate Park City',
        'phone' => '+14155551234',
        'email' => 'lead@example.com',
        'source' => 'referral',
        'estimated_value' => 8000,
        'contact_attempts' => 0,
        'created_at' => now(),
    ]);

    $result = $service->compute($lead);

    expect($result)
        ->toHaveKeys(['score', 'breakdown'])
        ->and($result['score'])->toBeInt()
        ->toBeGreaterThan(0)
        ->toBeLessThanOrEqual(1000);

    expect($result['breakdown'])
        ->toHaveKey('priority', 500)
        ->toHaveKey('has_express_consent', 75)
        ->toHaveKey('resort_interest_known', 40)
        ->toHaveKey('source', 80)
        ->toHaveKey('email_present', 15);

    expect($result['breakdown']['estimated_value'])->toBe(40); // min(100, 8000/200)
});

it('clamps the score to zero when penalties exceed contributions', function () {
    $service = new LeadScoringService;

    $lead = makeStubLead([
        'priority' => LeadPriority::Low,
        'has_express_consent' => false,
        'resort_interest' => null,
        'phone' => '14155551234', // not E.164 — no bonus
        'email' => null,
        'source' => 'unknown',
        'estimated_value' => null,
        'contact_attempts' => 100, // big negative
        'created_at' => now()->subYears(2),
    ]);

    $result = $service->compute($lead);

    expect($result['score'])->toBe(0);
    expect($result['breakdown']['attempt_penalty'])->toBeLessThan(0);
    expect($result['breakdown']['age_penalty'])->toBeLessThan(0);
});

it('applies the configured priority weight verbatim', function () {
    $service = new LeadScoringService;

    // Enum cases can't be array keys (PHP allows only int|string).
    // Iterate as a list of tuples instead.
    $cases = [
        [LeadPriority::Low, 0],
        [LeadPriority::Normal, 50],
        [LeadPriority::High, 200],
        [LeadPriority::Hot, 500],
    ];
    foreach ($cases as [$priority, $expected]) {
        $lead = makeStubLead(['priority' => $priority, 'phone' => '+14155551234', 'created_at' => now()]);
        $result = $service->compute($lead);
        expect($result['breakdown']['priority'])->toBe($expected);
    }
});

/**
 * @param  array<string, mixed>  $attrs
 */
function makeStubLead(array $attrs): Lead
{
    $lead = new Lead;

    foreach ($attrs as $key => $value) {
        $lead->setAttribute($key, $value);
    }

    return $lead;
}
