<?php

declare(strict_types=1);

use App\Modules\Compliance\Application\Jobs\ImportFederalDncDeltaJob;
use App\Modules\Compliance\Domain\Enums\DncSource;
use App\Modules\Compliance\Domain\Models\DncEntry;
use Illuminate\Support\Facades\Storage;

it('parses a delta file and inserts unique global DNC entries', function () {
    Storage::fake('local');
    Storage::disk('local')->put('dnc-delta.txt', <<<'CSV'
        4155551234
        4155555678
        # comment
        2125551111
        not-a-phone
        4155551234
        CSV); // last is a duplicate within the file

    (new ImportFederalDncDeltaJob('dnc-delta.txt', 'local'))
        ->handle(app(\App\Core\Shared\Services\PhoneNormalizer::class));

    $entries = DncEntry::query()
        ->whereNull('tenant_id')
        ->where('source', DncSource::FederalDnc->value)
        ->get();

    // 3 unique valid numbers; 'not-a-phone' rejected; comment skipped; duplicate folded.
    expect($entries)->toHaveCount(3);
    expect($entries->pluck('phone')->all())
        ->toContain('+14155551234', '+14155555678', '+12125551111');
});

it('is idempotent: re-running the same file does not double-insert', function () {
    Storage::fake('local');
    Storage::disk('local')->put('dnc.txt', "4155551234\n");

    (new ImportFederalDncDeltaJob('dnc.txt', 'local'))
        ->handle(app(\App\Core\Shared\Services\PhoneNormalizer::class));

    (new ImportFederalDncDeltaJob('dnc.txt', 'local'))
        ->handle(app(\App\Core\Shared\Services\PhoneNormalizer::class));

    expect(DncEntry::query()->whereNull('tenant_id')->count())->toBe(1);
});
