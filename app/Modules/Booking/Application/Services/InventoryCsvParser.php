<?php

declare(strict_types=1);

namespace App\Modules\Booking\Application\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use League\Csv\Reader;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

/**
 * Parses an uploaded CSV/Excel file into normalized inventory rows.
 *
 * Smart header detection: the canonical column names below map to a
 * pile of common synonyms. The parser is permissive on input but
 * strict on output — every successful row contains the same shape,
 * regardless of which synonym the operator used. Unknown headers are
 * preserved in the parsed row but ignored downstream.
 *
 * The parser does NOT touch the database. It returns valid + invalid
 * rows with per-row error arrays so the controller can build the
 * preview UI without an extra round trip.
 *
 * Excel parsing uses phpoffice/phpspreadsheet (added to composer.json
 * for this feature). Only the first sheet is read — multi-sheet
 * workbooks are too ambiguous to import without explicit operator
 * sheet-selection, which isn't worth the UI complexity right now.
 */
final class InventoryCsvParser
{
    /**
     * Canonical column → list of accepted aliases (lowercased,
     * stripped of non-alphanum). The header lookup tolerates any
     * casing or punctuation the operator's spreadsheet exported.
     *
     * @var array<string, array<int, string>>
     */
    private const HEADER_ALIASES = [
        'resort_name' => ['resortname', 'resort', 'name', 'property', 'propertyname'],
        'resort_brand' => ['resortbrand', 'brand'],
        'city' => ['city', 'locationcity', 'town'],
        'state' => ['state', 'locationstate', 'province', 'region'],
        'country' => ['country', 'locationcountry'],
        'timezone' => ['timezone', 'tz', 'tzname'],
        'unit_type' => ['unittype', 'unit', 'type', 'roomtype'],
        'sleeps' => ['sleeps', 'capacity', 'maxoccupancy', 'occupancy'],
        'features' => ['features', 'amenities'],
        'check_in_date' => ['checkindate', 'checkin', 'checkinfrom', 'arrival', 'startdate', 'fromdate'],
        'check_out_date' => ['checkoutdate', 'checkout', 'checkinto', 'departure', 'enddate', 'todate'],
        'base_price' => ['baseprice', 'price', 'rate', 'totalprice', 'rentalprice', 'amount'],
        'currency' => ['currency', 'ccy'],
    ];

    /**
     * Unit type values we accept. Anything else is a row error.
     * Lowercased on input; normalized strings used for matching.
     *
     * @var array<int, string>
     */
    private const UNIT_TYPES = ['studio', '1br', '2br', '3br', 'presidential'];

    /**
     * @return array{
     *   rows: array<int, array<string, mixed>>,
     *   summary: array{total_rows: int, valid_rows: int, invalid_rows: int}
     * }
     */
    public function parse(UploadedFile $file): array
    {
        $rawRows = $this->extractRawRows($file);

        if (empty($rawRows)) {
            return [
                'rows' => [],
                'summary' => ['total_rows' => 0, 'valid_rows' => 0, 'invalid_rows' => 0],
            ];
        }

        $headerRow = array_shift($rawRows);
        $headerMap = $this->mapHeaders($headerRow);

        // Required canonical columns. If any is missing the whole file
        // fails up-front — there's no point parsing rows we can't use.
        $required = [
            'resort_name', 'city', 'state',
            'unit_type', 'sleeps',
            'check_in_date', 'check_out_date', 'base_price',
        ];
        $missing = array_diff($required, array_values($headerMap));
        if (! empty($missing)) {
            return [
                'rows' => [],
                'summary' => [
                    'total_rows' => 0,
                    'valid_rows' => 0,
                    'invalid_rows' => 0,
                    'fatal_error' => 'Missing required columns: '.implode(', ', $missing)
                        .'. Download the template or rename your columns to match.',
                ],
            ];
        }

        $parsed = [];
        $rowNumber = 1; // 1 = header; data rows start at 2
        foreach ($rawRows as $rawRow) {
            $rowNumber++;
            // Skip fully-blank rows so trailing whitespace in CSVs
            // doesn't surface as 100 fake errors.
            if ($this->isBlankRow($rawRow)) {
                continue;
            }

            $parsed[] = $this->parseRow($rowNumber, $rawRow, $headerMap);
        }

        $validCount = 0;
        foreach ($parsed as $r) {
            if ($r['valid']) {
                $validCount++;
            }
        }

        return [
            'rows' => $parsed,
            'summary' => [
                'total_rows' => count($parsed),
                'valid_rows' => $validCount,
                'invalid_rows' => count($parsed) - $validCount,
            ],
        ];
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function extractRawRows(UploadedFile $file): array
    {
        $ext = strtolower($file->getClientOriginalExtension());

        if (in_array($ext, ['xlsx', 'xls'], true)) {
            return $this->readSpreadsheet($file);
        }

        // Default to CSV for .csv, .txt, and anything else.
        return $this->readCsv($file);
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function readCsv(UploadedFile $file): array
    {
        try {
            $reader = Reader::createFromPath($file->getRealPath(), 'r');
            // skipInputBOM strips the UTF-8 byte-order mark Excel adds
            // when saving as "CSV UTF-8" — without it the first header
            // arrives as "﻿resort_name" and the alias map misses.
            $reader->setHeaderOffset(null);

            $rows = [];
            foreach ($reader->getRecords() as $record) {
                $rows[] = array_values($record);
            }

            // Manually strip BOM from the first cell of the first row.
            if (! empty($rows) && isset($rows[0][0])) {
                $rows[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', $rows[0][0]) ?? $rows[0][0];
            }

            return $rows;
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function readSpreadsheet(UploadedFile $file): array
    {
        try {
            $spreadsheet = IOFactory::load($file->getRealPath());
            $sheet = $spreadsheet->getActiveSheet();
            $rows = [];
            foreach ($sheet->getRowIterator() as $row) {
                $cells = [];
                $cellIterator = $row->getCellIterator();
                $cellIterator->setIterateOnlyExistingCells(false);
                foreach ($cellIterator as $cell) {
                    $value = $cell->getFormattedValue();
                    $cells[] = $value === null ? '' : (string) $value;
                }
                $rows[] = $cells;
            }

            return $rows;
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * @param  array<int, string>  $headerRow
     * @return array<int, string>  index → canonical column name
     */
    private function mapHeaders(array $headerRow): array
    {
        $map = [];
        foreach ($headerRow as $i => $rawHeader) {
            $canonical = $this->canonicalize($rawHeader);
            if ($canonical !== null) {
                $map[$i] = $canonical;
            }
        }

        return $map;
    }

    private function canonicalize(string $rawHeader): ?string
    {
        $needle = strtolower(preg_replace('/[^a-z0-9]/i', '', $rawHeader) ?? '');
        if ($needle === '') {
            return null;
        }

        foreach (self::HEADER_ALIASES as $canonical => $aliases) {
            if ($needle === $canonical || in_array($needle, $aliases, true)) {
                return $canonical;
            }
            // Match the canonical name with punctuation stripped too.
            $canonicalStripped = str_replace('_', '', $canonical);
            if ($needle === $canonicalStripped) {
                return $canonical;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $row
     * @param  array<int, string>  $headerMap
     * @return array<string, mixed>
     */
    private function parseRow(int $rowNumber, array $row, array $headerMap): array
    {
        $errors = [];
        $warnings = [];
        $data = [];

        // Pull each canonical field via the header map.
        foreach ($headerMap as $colIndex => $canonical) {
            $value = $row[$colIndex] ?? '';
            $data[$canonical] = is_string($value) ? trim($value) : '';
        }

        // Required-field presence.
        foreach (['resort_name', 'city', 'state', 'unit_type', 'sleeps',
            'check_in_date', 'check_out_date', 'base_price'] as $req) {
            if (! isset($data[$req]) || $data[$req] === '') {
                $errors[] = "Missing required field: {$req}.";
            }
        }

        // State → 2-letter upper.
        if (! empty($data['state'])) {
            $data['state'] = strtoupper($data['state']);
            if (strlen($data['state']) !== 2) {
                $errors[] = "State must be a 2-letter code (got '{$data['state']}').";
            }
        }

        // Country defaults to US; normalized to 2-letter upper.
        $data['country'] = strtoupper($data['country'] ?? 'US') ?: 'US';
        if (strlen($data['country']) !== 2) {
            $errors[] = "Country must be a 2-letter code (got '{$data['country']}').";
        }

        // Unit type → lower, validated against allowlist.
        if (! empty($data['unit_type'])) {
            $data['unit_type'] = strtolower($data['unit_type']);
            if (! in_array($data['unit_type'], self::UNIT_TYPES, true)) {
                $errors[] = "Unit type must be one of: "
                    .implode(', ', self::UNIT_TYPES)." (got '{$data['unit_type']}').";
            }
        }

        // Sleeps → int.
        if (! empty($data['sleeps'])) {
            $sleeps = (int) preg_replace('/[^0-9]/', '', (string) $data['sleeps']);
            if ($sleeps < 1 || $sleeps > 20) {
                $errors[] = "Sleeps must be between 1 and 20 (got {$sleeps}).";
            }
            $data['sleeps'] = $sleeps;
        }

        // Dates — Carbon handles most formats. We normalize to Y-m-d.
        foreach (['check_in_date', 'check_out_date'] as $dateKey) {
            if (! empty($data[$dateKey])) {
                try {
                    $data[$dateKey] = CarbonImmutable::parse((string) $data[$dateKey])
                        ->toDateString();
                } catch (Throwable $e) {
                    $errors[] = "Could not parse {$dateKey}: '{$data[$dateKey]}'.";
                    $data[$dateKey] = null;
                }
            }
        }

        // Check-out must be after check-in.
        if (! empty($data['check_in_date']) && ! empty($data['check_out_date'])) {
            if ($data['check_out_date'] <= $data['check_in_date']) {
                $errors[] = 'Check-out must be after check-in.';
            } else {
                $data['nights'] = CarbonImmutable::parse($data['check_in_date'])
                    ->diffInDays(CarbonImmutable::parse($data['check_out_date']));
            }
        }

        // Price — strip currency symbols and grouping commas.
        if (! empty($data['base_price'])) {
            $cleaned = preg_replace('/[^0-9.\-]/', '', (string) $data['base_price']) ?? '';
            if ($cleaned === '' || ! is_numeric($cleaned)) {
                $errors[] = "Invalid base_price: '{$data['base_price']}'.";
                $data['base_price'] = null;
            } else {
                $price = (float) $cleaned;
                if ($price < 0 || $price > 9_999_999.99) {
                    $errors[] = "base_price out of range: {$price}.";
                }
                $data['base_price'] = $price;
            }
        }

        // Currency → 3-letter upper, default USD.
        $data['currency'] = strtoupper($data['currency'] ?? 'USD') ?: 'USD';
        if (strlen($data['currency']) !== 3) {
            $warnings[] = "Currency '{$data['currency']}' is not 3 chars; defaulting to USD.";
            $data['currency'] = 'USD';
        }

        // Features — split on commas into an array.
        $featuresRaw = $data['features'] ?? '';
        $data['features'] = $featuresRaw === ''
            ? []
            : array_values(array_filter(array_map('trim', explode(',', $featuresRaw))));

        // Resort + unit grouping keys — used to group "new entities"
        // in the controller without duplicating the same resort row.
        $data['resort_key'] = $this->resortKey(
            $data['resort_name'] ?? '',
            $data['city'] ?? '',
            $data['state'] ?? '',
        );
        $data['unit_key'] = $this->unitKey(
            $data['resort_key'],
            $data['unit_type'] ?? '',
            (int) ($data['sleeps'] ?? 0),
        );

        return [
            'row_num' => $rowNumber,
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'data' => $data,
        ];
    }

    /**
     * @param  array<int, string>  $row
     */
    private function isBlankRow(array $row): bool
    {
        foreach ($row as $cell) {
            if (is_string($cell) && trim($cell) !== '') {
                return false;
            }
        }

        return true;
    }

    public function resortKey(string $name, string $city, string $state): string
    {
        return strtolower(trim($name)).'|'.strtolower(trim($city)).'|'.strtoupper(trim($state));
    }

    public function unitKey(string $resortKey, string $unitType, int $sleeps): string
    {
        return $resortKey.'|'.strtolower($unitType).'|'.$sleeps;
    }
}
