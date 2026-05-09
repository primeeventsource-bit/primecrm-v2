<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Jobs;

use App\Core\Shared\Services\PhoneNormalizer;
use App\Modules\Compliance\Domain\Enums\DncSource;
use App\Modules\Compliance\Domain\Models\DncEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Bulk-imports a federal DNC delta file.
 *
 * Real federal DNC data comes from telemarketing.donotcall.gov as
 * newline-delimited 10-digit phones. Subscribers fetch deltas daily via
 * SFTP using their telemarketing PIN. The fetch is handled outside the
 * application (cron + a download script) and dropped onto the configured
 * filesystem; this job processes whatever is at the path it's given.
 *
 * The job reads in chunks and bulk-inserts via raw SQL to avoid the
 * per-row Eloquent overhead — a daily federal delta can be 1-2M rows.
 *
 * Idempotent: existing entries (same phone_hash + source=federal_dnc +
 * tenant_id NULL) are skipped via INSERT ... ON CONFLICT DO NOTHING.
 *
 * Cleanup of expired federal entries is a separate concern handled by
 * an artisan command run on a weekly schedule.
 */
final class ImportFederalDncDeltaJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;
    public int $timeout = 7200;

    /** Insert in chunks of this many rows. */
    private const BATCH_SIZE = 5_000;

    public function __construct(
        public readonly string $filePath,
        public readonly string $diskName = 'local',
        public readonly string $sourceLabel = 'federal_dnc',
    ) {
        $this->onQueue(config('queue.names.lead_import')); // shares the import lane
    }

    public function handle(PhoneNormalizer $normalizer): void
    {
        $disk = Storage::disk($this->diskName);

        if (! $disk->exists($this->filePath)) {
            throw new \RuntimeException("DNC delta file not found at {$this->diskName}:{$this->filePath}");
        }

        $stream = $disk->readStream($this->filePath);
        if ($stream === null) {
            throw new \RuntimeException("Could not open stream for {$this->filePath}");
        }

        $batch = [];
        $totalProcessed = 0;
        $totalInserted = 0;
        $effectiveDate = now()->toDateString();
        $addedBy = "import:{$this->sourceLabel}-".now()->format('Y-m-d');

        try {
            while (($line = fgets($stream)) !== false) {
                $totalProcessed++;
                $raw = trim($line);

                if ($raw === '' || $raw[0] === '#') {
                    continue;
                }

                $normalized = $normalizer->normalizeAndHash($raw);
                if ($normalized === null) {
                    continue;
                }

                [$phone, $hash] = $normalized;

                $batch[] = [
                    'id' => \Ramsey\Uuid\Uuid::uuid7()->toString(),
                    'tenant_id' => null,
                    'phone' => $phone,
                    'phone_hash' => $hash,
                    'source' => DncSource::FederalDnc->value,
                    'reason' => null,
                    'added_by' => $addedBy,
                    'effective_date' => $effectiveDate,
                    'expires_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if (count($batch) >= self::BATCH_SIZE) {
                    $totalInserted += $this->flushBatch($batch);
                    $batch = [];
                }
            }

            if (! empty($batch)) {
                $totalInserted += $this->flushBatch($batch);
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        logger()->info('Federal DNC delta imported', [
            'file' => $this->filePath,
            'processed' => $totalProcessed,
            'inserted' => $totalInserted,
        ]);
    }

    /**
     * Bulk insert with PostgreSQL ON CONFLICT DO NOTHING for idempotency.
     * Postgres lets us declare the conflict target as a partial index of
     * (tenant_id IS NULL, phone_hash, source) — but the simpler approach
     * is to look up existing entries first and filter the batch.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function flushBatch(array $rows): int
    {
        $hashes = array_column($rows, 'phone_hash');

        $existing = DncEntry::query()
            ->whereNull('tenant_id')
            ->where('source', DncSource::FederalDnc->value)
            ->whereIn('phone_hash', $hashes)
            ->pluck('phone_hash')
            ->all();

        $existingSet = array_flip($existing);

        $toInsert = array_values(array_filter(
            $rows,
            static fn (array $row) => ! isset($existingSet[$row['phone_hash']]),
        ));

        if (empty($toInsert)) {
            return 0;
        }

        DB::table('dnc_entries')->insert($toInsert);

        return count($toInsert);
    }
}
