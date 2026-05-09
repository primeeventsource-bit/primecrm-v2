<?php

declare(strict_types=1);

namespace App\Modules\Lead\Application\Services;

use App\Core\Shared\Services\PhoneNormalizer;
use App\Modules\Lead\Application\Actions\CreateLeadAction;
use App\Modules\Lead\Application\DTOs\LeadInputData;
use App\Modules\Lead\Domain\Models\LeadImport;
use Illuminate\Support\Facades\DB;
use League\Csv\Reader;

/**
 * Parses a CSV row stream and runs each row through the standard lead
 * creation pipeline (dedup → score → persist).
 *
 * Designed to be called from queue workers. The caller passes a stream
 * (file handle or in-memory buffer) plus the LeadImport batch record;
 * this service updates the batch's counters as it processes and writes
 * a sample of errors back into the batch's `errors` JSON column.
 *
 * The single-row error policy is "skip and record" — one bad row never
 * fails an entire import. The exception is fatal CSV format errors,
 * which mark the whole batch as failed.
 */
final class LeadImportService
{
    public function __construct(
        private readonly CreateLeadAction $createLead,
        private readonly PhoneNormalizer $phoneNormalizer,
    ) {}

    /**
     * Process a CSV stream end-to-end. Returns the final counters.
     *
     * @param  resource  $stream  CSV file handle (must support fgets)
     * @param  array<string, string>  $columnMapping  CSV header → field name
     * @return array{imported: int, duplicate: int, error: int, total: int}
     */
    public function processCsvStream($stream, LeadImport $batch, array $columnMapping): array
    {
        $batch->update([
            'status' => LeadImport::STATUS_PROCESSING,
            'started_at' => now(),
            'column_mapping' => $columnMapping,
        ]);

        $reader = Reader::createFromStream($stream);
        $reader->setHeaderOffset(0);

        $counters = ['imported' => 0, 'duplicate' => 0, 'error' => 0, 'total' => 0];
        $errorSamples = [];
        $errorCap = (int) config('leads.import.sample_error_cap', 100);
        $chunkSize = (int) config('leads.import.chunk_size', 1000);

        $rowNumber = 1; // 1 = header
        $chunkBuffer = [];

        foreach ($reader->getRecords() as $record) {
            $rowNumber++;
            $counters['total']++;

            try {
                $input = $this->mapRow($record, $columnMapping, $batch->id);

                if ($input === null) {
                    $counters['error']++;
                    $this->collectError($errorSamples, $errorCap, $rowNumber, 'invalid_phone', $record);

                    continue;
                }

                $result = $this->createLead->execute($input);

                if ($result['was_duplicate']) {
                    $counters['duplicate']++;
                } else {
                    $counters['imported']++;
                }
            } catch (\Throwable $e) {
                $counters['error']++;
                $this->collectError($errorSamples, $errorCap, $rowNumber, $e->getMessage(), $record);

                logger()->warning('Lead import row failed', [
                    'batch_id' => $batch->id,
                    'row' => $rowNumber,
                    'error' => $e->getMessage(),
                ]);
            }

            // Periodic batch counter flush so the UI sees progress.
            if (count($chunkBuffer) >= $chunkSize || $counters['total'] % $chunkSize === 0) {
                $this->flushCounters($batch, $counters, $errorSamples);
            }
        }

        $finalStatus = $counters['error'] > 0
            ? LeadImport::STATUS_COMPLETED_WITH_ERRORS
            : LeadImport::STATUS_COMPLETED;

        $batch->update([
            'status' => $finalStatus,
            'total_rows' => $counters['total'],
            'processed_rows' => $counters['total'],
            'imported_count' => $counters['imported'],
            'duplicate_count' => $counters['duplicate'],
            'error_count' => $counters['error'],
            'errors' => $errorSamples,
            'completed_at' => now(),
        ]);

        return $counters;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, string>  $mapping
     */
    private function mapRow(array $record, array $mapping, string $batchId): ?LeadInputData
    {
        $get = static function (array $record, array $mapping, string $field): ?string {
            $sourceColumn = array_search($field, $mapping, true);
            if ($sourceColumn === false) {
                return null;
            }
            $value = $record[$sourceColumn] ?? null;

            return is_string($value) && $value !== '' ? trim($value) : null;
        };

        $rawPhone = $get($record, $mapping, 'phone');

        if ($rawPhone === null) {
            return null;
        }

        $normalized = $this->phoneNormalizer->normalizeAndHash($rawPhone);

        if ($normalized === null) {
            return null;
        }

        [$phone, $hash] = $normalized;

        return new LeadInputData(
            phone: $phone,
            phoneHash: $hash,
            firstName: $get($record, $mapping, 'first_name'),
            lastName: $get($record, $mapping, 'last_name'),
            email: $get($record, $mapping, 'email'),
            country: $get($record, $mapping, 'country'),
            state: $get($record, $mapping, 'state'),
            city: $get($record, $mapping, 'city'),
            postalCode: $get($record, $mapping, 'postal_code'),
            source: $get($record, $mapping, 'source') ?? LeadImport::SOURCE_CSV,
            sourceCampaign: $get($record, $mapping, 'source_campaign'),
            sourceMedium: $get($record, $mapping, 'source_medium'),
            importedViaId: $batchId,
            resortInterest: $get($record, $mapping, 'resort_interest'),
            estimatedValue: ($v = $get($record, $mapping, 'estimated_value')) !== null ? (float) $v : null,
            priority: $get($record, $mapping, 'priority') ?? 'normal',
        );
    }

    /**
     * @param  array<int, array{row: int, error: string, sample: array<string, mixed>}>  &$samples
     * @param  array<string, mixed>  $record
     */
    private function collectError(array &$samples, int $cap, int $row, string $error, array $record): void
    {
        if (count($samples) >= $cap) {
            return;
        }

        $samples[] = [
            'row' => $row,
            'error' => $error,
            // Don't store full sensitive data — just enough to identify the offending row
            'sample' => array_slice($record, 0, 6),
        ];
    }

    /**
     * @param  array{imported: int, duplicate: int, error: int, total: int}  $counters
     * @param  array<int, array{row: int, error: string, sample: array<string, mixed>}>  $errorSamples
     */
    private function flushCounters(LeadImport $batch, array $counters, array $errorSamples): void
    {
        DB::table('lead_imports')
            ->where('id', $batch->id)
            ->update([
                'processed_rows' => $counters['total'],
                'imported_count' => $counters['imported'],
                'duplicate_count' => $counters['duplicate'],
                'error_count' => $counters['error'],
                'errors' => json_encode($errorSamples),
                'updated_at' => now(),
            ]);
    }
}
