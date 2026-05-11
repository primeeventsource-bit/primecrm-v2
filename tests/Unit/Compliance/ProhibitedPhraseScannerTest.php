<?php

declare(strict_types=1);

use App\Modules\Compliance\Application\Services\ProhibitedPhraseScanner;

/**
 * Pure-logic unit tests — no Laravel app, no DB. Each rule pattern in
 * the scanner has a positive and negative case so future edits to the
 * regex set surface immediately.
 *
 * The rules are the bright lines the FTC + state AGs actually enforce
 * against; missed regressions here are how companies in this space
 * get fined.
 */
it('lets benign agent speech through clean', function () {
    $scanner = new ProhibitedPhraseScanner;

    expect($scanner->scan('Hi, thanks for taking my call. I see you own a Marriott week in Orlando.'))
        ->toBe([]);
    expect($scanner->scan(''))->toBe([]);
});

it('blocks rental-guarantee phrasing', function () {
    $scanner = new ProhibitedPhraseScanner;

    $matches = $scanner->scan('I can guarantee your week will rent within 30 days.');

    expect($matches)->not->toBeEmpty();
    expect($matches[0]['severity'])->toBe('block');
    expect($scanner->hasBlocking('I can guarantee your week will rent within 30 days.'))->toBeTrue();
});

it('blocks definite-outcome assertions', function () {
    $scanner = new ProhibitedPhraseScanner;

    $matches = $scanner->scan("You'll definitely rent this property.");
    expect(collect($matches)->pluck('severity'))->toContain('block');
});

it('blocks buyers-waiting claims', function () {
    $scanner = new ProhibitedPhraseScanner;

    expect($scanner->hasBlocking('We have several buyers waiting for properties just like yours.'))
        ->toBeTrue();
    expect($scanner->hasBlocking('Multiple renters interested right now.'))->toBeTrue();
});

it('blocks money-back-on-no-rental promises', function () {
    $scanner = new ProhibitedPhraseScanner;

    expect($scanner->hasBlocking("You get a full refund if it doesn't rent."))
        ->toBeTrue();
});

it('blocks misrepresentations of our role', function () {
    $scanner = new ProhibitedPhraseScanner;

    expect($scanner->hasBlocking('We buy your timeshare and resell it.'))->toBeTrue();
    expect($scanner->hasBlocking('We sell your week directly.'))->toBeTrue();
});

it('flags urgency phrases as warn not block', function () {
    $scanner = new ProhibitedPhraseScanner;

    $matches = $scanner->scan('Act now — this is a limited time offer.');

    expect($matches)->not->toBeEmpty();
    expect($matches[0]['severity'])->toBe('warn');
    expect($scanner->hasBlocking('Act now — this is a limited time offer.'))->toBeFalse();
});

it('returns offset and length for each match so the frontend can highlight', function () {
    $scanner = new ProhibitedPhraseScanner;

    $text = 'I guarantee your week will rent.';
    $matches = $scanner->scan($text);

    expect($matches)->not->toBeEmpty();
    expect($matches[0])->toHaveKeys(['match', 'severity', 'reason', 'suggestion', 'offset', 'length']);
    expect($matches[0]['offset'])->toBeGreaterThanOrEqual(0);
    expect(substr($text, $matches[0]['offset'], $matches[0]['length']))->toBe($matches[0]['match']);
});

it('supplies a suggestion for every match so the coach has a script to read', function () {
    $scanner = new ProhibitedPhraseScanner;
    $samples = [
        'I guarantee your week will rent.',
        'We have buyers waiting.',
        "Money-back if it doesn't rent.",
        'We buy your timeshare.',
    ];

    foreach ($samples as $s) {
        foreach ($scanner->scan($s) as $m) {
            expect($m['suggestion'])->not->toBe('');
            expect($m['reason'])->not->toBe('');
        }
    }
});

it('returns multiple matches when an utterance crosses several lines', function () {
    $scanner = new ProhibitedPhraseScanner;

    // Crosses guarantee + buyers-waiting in one breath.
    $matches = $scanner->scan('I guarantee it will rent, we have buyers waiting.');

    expect(count($matches))->toBeGreaterThanOrEqual(2);
});
