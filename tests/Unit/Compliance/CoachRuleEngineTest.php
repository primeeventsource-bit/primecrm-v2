<?php

declare(strict_types=1);

use App\Modules\Compliance\Application\Services\CoachRuleEngine;
use App\Modules\Compliance\Application\Services\ProhibitedPhraseScanner;

/**
 * The rule engine's priority order is load-bearing for compliance:
 * a blocking phrase MUST always produce a 'STOP.' rescue, regardless
 * of what else the utterance contains. These tests pin that contract.
 */
function coachHintFor(string $utterance): array
{
    $scanner = new ProhibitedPhraseScanner;
    $rules = new CoachRuleEngine;

    return $rules->nextHint($utterance, $scanner->scan($utterance));
}

it('emits a compliance_rescue with STOP prefix on any blocking phrase', function () {
    $hint = coachHintFor('I guarantee your week will rent.');

    expect($hint['priority'])->toBe('compliance_rescue');
    expect($hint['red_zone'])->toBeTrue();
    expect($hint['hint'])->toStartWith('STOP.');
});

it('compliance rescue beats every other signal in the same utterance', function () {
    // Owner objection + guarantee misrepresentation in one line.
    // Objection patterns would normally win — compliance must override.
    $hint = coachHintFor("It's gone unrented for years, but I guarantee mine will rent for you.");

    expect($hint['priority'])->toBe('compliance_rescue');
});

it('emits compliance_caution (warn severity) without STOP', function () {
    $hint = coachHintFor('Act now — limited time offer.');

    expect($hint['priority'])->toBe('compliance_caution');
    expect($hint['red_zone'])->toBeFalse();
    expect($hint['hint'])->toStartWith('CAUTION:');
});

it('emits an objection hint when the owner uses an unrented-vent', function () {
    $hint = coachHintFor("It's been sitting unrented for two years.");

    expect($hint['priority'])->toBe('objection');
    expect($hint['hint'])->toStartWith('OBJECTION:');
});

it('emits an objection hint for competitor comparisons', function () {
    $hint = coachHintFor('Why are you so much more expensive than the other company?');

    expect($hint['priority'])->toBe('objection');
});

it('emits an objection hint for trust signals (catch / scam)', function () {
    $hint = coachHintFor("This sounds too good to be true — what's the catch?");

    expect($hint['priority'])->toBe('objection');
});

it('surfaces a value hint when the owner is brief', function () {
    $hint = coachHintFor('Yeah.'); // < 8 words

    // 'yeah' matches the close pattern (assent), so it bypasses value.
    // Use a non-assent short utterance.
    $hint = coachHintFor('hmm interesting');
    expect($hint['priority'])->toBe('value');
});

it('emits a close hint on assent signals', function () {
    $hint = coachHintFor('OK that sounds good to me.');

    expect($hint['priority'])->toBe('close');
});

it('falls back to a default discovery hint when no signal matches', function () {
    $hint = coachHintFor('My check-in is in March and the unit sleeps six people.');

    expect($hint['priority'])->toBe('default');
    expect($hint['red_zone'])->toBeFalse();
});

it('carries the matches array through for compliance_rescue', function () {
    $hint = coachHintFor('We have buyers waiting for your week.');

    expect($hint['matches'])->not->toBeEmpty();
    expect($hint['matches'][0]['severity'])->toBe('block');
});
