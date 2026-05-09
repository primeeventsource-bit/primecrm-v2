<?php

declare(strict_types=1);

use App\Modules\Lead\Application\Jobs\ImportLeadsBatchJob;
use App\Modules\Lead\Application\Services\LeadImportService;
use App\Modules\Lead\Domain\Models\Lead;
use App\Modules\Lead\Domain\Models\LeadImport;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->actingAsTenant();
});

it('imports a small CSV through the service end-to-end', function () {
    Storage::fake('local');
    $csv = <<<'CSV'
        phone,first,last,email
        4155551111,Alice,Adams,a@example.com
        4155552222,Bob,Brown,b@example.com
        not-a-phone,Bad,Row,bad@example.com
        4155551111,Alice,Adams,a@example.com
        CSV;
    Storage::disk('local')->put('test-import.csv', $csv);

    $batch = LeadImport::query()->create([
        'imported_by_id' => \Database\Factories\UserFactory::new()->create()->id,
        'source' => LeadImport::SOURCE_CSV,
        'original_filename' => 'test.csv',
        's3_path' => 'test-import.csv',
        'status' => LeadImport::STATUS_PENDING,
    ]);

    $stream = Storage::disk('local')->readStream('test-import.csv');
    app(LeadImportService::class)->processCsvStream($stream, $batch, [
        'phone' => 'phone',
        'first' => 'first_name',
        'last' => 'last_name',
        'email' => 'email',
    ]);

    $batch->refresh();

    expect($batch->status)->toBe(LeadImport::STATUS_COMPLETED_WITH_ERRORS);
    expect($batch->imported_count)->toBe(2);
    expect($batch->duplicate_count)->toBe(1);
    expect($batch->error_count)->toBe(1);
    expect(Lead::query()->count())->toBe(2);
});

it('marks a batch as failed when the file is missing', function () {
    Storage::fake('local');

    $batch = LeadImport::query()->create([
        'imported_by_id' => \Database\Factories\UserFactory::new()->create()->id,
        'source' => LeadImport::SOURCE_CSV,
        's3_path' => 'does-not-exist.csv',
        'status' => LeadImport::STATUS_PENDING,
    ]);

    config(['filesystems.default' => 'local']);
    (new ImportLeadsBatchJob($batch->id, ['phone' => 'phone']))
        ->handle(app(LeadImportService::class));

    expect($batch->fresh()->status)->toBe(LeadImport::STATUS_FAILED);
});
