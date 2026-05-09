<?php

declare(strict_types=1);

namespace App\Modules\Lead\Application\Jobs;

use App\Modules\Lead\Application\Services\LeadImportService;
use App\Modules\Lead\Domain\Models\LeadImport;
use App\Support\Concerns\AppliesTenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Processes a previously-uploaded CSV batch through the import pipeline.
 *
 * The HTTP layer uploads the file to S3 (or local disk in dev), creates
 * the LeadImport batch record in `pending` state, and dispatches this job.
 * Long-running CSVs (50k+ rows) shouldn't block the request.
 *
 * On failure, the batch is marked failed with the error captured. We do
 * NOT retry — partial imports are very confusing and re-running the whole
 * file would create duplicates of the already-imported rows. Operators
 * inspect the batch, fix the cause, and re-upload manually.
 */
final class ImportLeadsBatchJob implements ShouldQueue
{
    use AppliesTenantContext;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;
    public int $timeout = 3600;

    public function __construct(
        public readonly string $importId,
        public readonly array $columnMapping,
    ) {
        $this->captureTenantContext();
        $this->onQueue(config('queue.names.lead_import'));
    }

    public function handle(LeadImportService $importer): void
    {
        $this->applyTenantContext();

        $batch = LeadImport::query()->find($this->importId);

        if ($batch === null) {
            logger()->warning('ImportLeadsBatchJob: batch not found', ['import_id' => $this->importId]);

            return;
        }

        if ($batch->s3_path === null) {
            $this->fail($batch, 'no_file_attached');

            return;
        }

        $disk = config('filesystems.default');
        $stream = Storage::disk($disk)->readStream($batch->s3_path);

        if ($stream === null) {
            $this->fail($batch, 'file_not_readable');

            return;
        }

        try {
            $importer->processCsvStream($stream, $batch, $this->columnMapping);
        } catch (\Throwable $e) {
            $this->fail($batch, $e->getMessage());

            throw $e;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function fail(LeadImport $batch, string $reason): void
    {
        $batch->update([
            'status' => LeadImport::STATUS_FAILED,
            'errors' => array_merge((array) $batch->errors, [['fatal' => $reason]]),
            'completed_at' => now(),
        ]);
    }
}
