<?php

declare(strict_types=1);

use App\Modules\Compliance\Application\Services\AreaCodeTimezoneResolver;

it('resolves NYC area codes to America/New_York', function () {
    $resolver = new AreaCodeTimezoneResolver;

    expect($resolver->resolve('+12125551234'))->toBe('America/New_York');
    expect($resolver->resolve('+13475551234'))->toBe('America/New_York');
});

it('resolves SF area codes to America/Los_Angeles', function () {
    $resolver = new AreaCodeTimezoneResolver;

    expect($resolver->resolve('+14155551234'))->toBe('America/Los_Angeles');
    expect($resolver->resolve('+16285551234'))->toBe('America/Los_Angeles');
});

it('resolves Phoenix to America/Phoenix (no DST)', function () {
    $resolver = new AreaCodeTimezoneResolver;

    expect($resolver->resolve('+16025551234'))->toBe('America/Phoenix');
});

it('resolves Hawaii area code to Pacific/Honolulu', function () {
    $resolver = new AreaCodeTimezoneResolver;

    expect($resolver->resolve('+18085551234'))->toBe('Pacific/Honolulu');
});

it('returns null for unknown or non-NANP numbers', function () {
    $resolver = new AreaCodeTimezoneResolver;

    expect($resolver->resolve('+447700900123'))->toBeNull(); // UK
    expect($resolver->resolve('+10005551234'))->toBeNull(); // not assigned
    expect($resolver->resolve('not a phone'))->toBeNull();
});
