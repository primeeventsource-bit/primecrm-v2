<?php

declare(strict_types=1);

use App\Core\Shared\Services\PhoneNormalizer;

it('normalizes 10-digit US numbers to E.164', function () {
    $n = new PhoneNormalizer;

    expect($n->normalize('4155551234'))->toBe('+14155551234');
    expect($n->normalize('(415) 555-1234'))->toBe('+14155551234');
    expect($n->normalize('415.555.1234'))->toBe('+14155551234');
});

it('preserves an already-normalized E.164 number', function () {
    $n = new PhoneNormalizer;

    expect($n->normalize('+14155551234'))->toBe('+14155551234');
});

it('treats 11-digit numbers starting with 1 as US country code prefix', function () {
    $n = new PhoneNormalizer;

    expect($n->normalize('14155551234'))->toBe('+14155551234');
});

it('returns null for inputs that are not phone-shaped', function () {
    $n = new PhoneNormalizer;

    expect($n->normalize(''))->toBeNull();
    expect($n->normalize('abc'))->toBeNull();
    expect($n->normalize('123'))->toBeNull(); // too short
});

it('produces a stable SHA-256 hash for the same E.164 number', function () {
    $n = new PhoneNormalizer;

    $hash1 = $n->hash('+14155551234');
    $hash2 = $n->hash('+14155551234');

    expect($hash1)->toBe($hash2);
    expect($hash1)->toHaveLength(64);
});

it('round-trips normalize-and-hash on equivalent inputs', function () {
    $n = new PhoneNormalizer;

    $a = $n->normalizeAndHash('(415) 555-1234');
    $b = $n->normalizeAndHash('+1-415-555-1234');

    expect($a)->not->toBeNull();
    expect($b)->not->toBeNull();
    expect($a[0])->toBe($b[0]); // same E.164
    expect($a[1])->toBe($b[1]); // same hash
});
