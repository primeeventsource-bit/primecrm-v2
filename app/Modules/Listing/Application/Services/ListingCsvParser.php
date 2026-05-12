<?php

declare(strict_types=1);

namespace App\Modules\Listing\Application\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use League\Csv\Reader;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

/**
 * Parses an uploaded CSV/Excel file into normalized listing rows.
 *
 * Each row must identify an owner (via email or phone) AND a
 * property location (resort + city + state). The importer resolves
 * those against existing leads/properties — new ones get the
 * "approve before create" preview treatment.
 *
 * Listing-only fields (dates, asking price, commission) are
 * validated here so the preview UI can show row-level errors before
 * the operator commits.
 *
 * Headers are matched case-insensitively against a synonym map; a
 * spreadsheet exported from a partner with "asking rate" or "list
 * price" gets mapped to the canonical `asking_price` column.
 */
final class ListingCsvParser
{
    /**
     * @var array<string, array<int, string>>
     */
    private const HEADER_ALIASES = [
        'owner_email' => ['owneremail', 'email', 'ownermail'],
        'owner_phone' => ['ownerphone', 'phone', 'ownermobile', 'mobile'],
        'owner_first_name' => ['ownerfirstname', 'firstname', 'ownerfirst', 'first'],
        'owner_last_name' => ['ownerlastname', 'lastname', 'ownerlast', 'last'],
        'resort_name' => ['resortname', 'resort', 'propertyname', 'property'],
        'resort_brand' => ['resortbrand', 'brand'],
        'city' => ['city', 'locationcity', 'town'],
        'state' => ['state', 'locationstate', 'province', 'region'],
        'country' => ['country', 'locationcountry'],
        'unit_number' => ['unitnumber', 'unit', 'unitno'],
        'bedrooms' => ['bedrooms', 'bedroomcount', 'beds', 'bedroom'],
        'sleeps' => ['sleeps', 'capacity', 'maxoccupancy', 'occupancy'],
        'ownership_type' => ['ownershiptype', 'ownership'],
        'check_in_date' => ['checkindate', 'checkin', 'checkinfrom', 'arrival', 'startdate', 'fromdate'],
        'check_out_date' => ['checkoutdate', 'checkout', 'checkinto', 'departure', 'enddate', 'todate'],
        'asking_price' => ['askingprice', 'price', 'listprice', 'rate', 'askingrate', 'asking', 'rent'],
        'reserve_price' => ['reserveprice', 'reserve', 'minprice', 'floor', 'minrent'],
        'our_commission_pct' => ['ourcommissionpct', 'commission', 'commissionpct', 'fee', 'feepct'],
        'marketing_description' => ['marketingdescription', 'description', 'marketing', 'notes', 'comments'],
        'go_live' => ['golive', 'live', 'publish'],
    ];

    private const OWNERSHIP_TYPES = ['deeded', 'points', 'right_to_use', 'leasehold'];

    /**
     * @return array{
     *   rows: array<int, array<string, mixed>>,
     *   summary: array<string, mixed>,
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

        // Required: resort+city+state, dates, asking_price, plus at
        // least one owner identifier. Owner-id check is enforced
        // per-row (because the map says "either email or phone").
        $required = ['resort_name', 'city', 'state',
            'check_in_date', 'check_out_date', 'asking_price'];
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
        $hasOwnerId = in_array('owner_email', $headerMap, true)
            || in_array('owner_phone', $headerMap, true);
        if (! $hasOwnerId) {
            return [
                'rows' => [],
                'summary' => [
                    'total_rows' => 0,
                    'valid_rows' => 0,
                    'invalid_rows' => 0,
                    'fatal_error' => 'Need at least one of owner_email or owner_phone to identify the owner.',
                ],
            ];
        }

        $parsed = [];
        $rowNumber = 1;
        foreach ($rawRows as $rawRow) {
            $rowNumber++;
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

        return in_array($ext, ['xlsx', 'xls'], true)
            ? $this->readSpreadsheet($file)
            : $this->readCsv($file);
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function readCsv(UploadedFile $file): array
    {
        try {
            $reader = Reader::createFromPath($file->getRealPath(), 'r');
            $reader->setHeaderOffset(null);
            $rows = [];
            foreach ($reader->getRecords() as $record) {
                $rows[] = array_values($record);
            }
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
     * @return array<int, string>
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

        foreach ($headerMap as $colIndex => $canonical) {
            $value = $row[$colIndex] ?? '';
            $data[$canonical] = is_string($value) ? trim($value) : '';
        }

        // Owner identity — at least one of email/phone.
        $email = strtolower($data['owner_email'] ?? '');
        $phone = preg_replace('/[^0-9+]/', '', $data['owner_phone'] ?? '') ?? '';
        if ($email === '' && $phone === '') {
            $errors[] = 'Missing owner identity: need owner_email or owner_phone.';
        }
        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid owner_email: '{$data['owner_email']}'.";
        }
        $data['owner_email'] = $email !== '' ? $email : null;
        $data['owner_phone'] = $phone !== '' ? $phone : null;

        // Required text fields.
        foreach (['resort_name', 'city', 'state'] as $req) {
            if (! isset($data[$req]) || $data[$req] === '') {
                $errors[] = "Missing required field: {$req}.";
            }
        }

        // State / country normalization.
        if (! empty($data['state'])) {
            $data['state'] = strtoupper($data['state']);
            if (strlen($data['state']) !== 2) {
                $errors[] = "State must be a 2-letter code (got '{$data['state']}').";
            }
        }
        $data['country'] = strtoupper($data['country'] ?? 'US') ?: 'US';
        if (strlen($data['country']) !== 2) {
            $errors[] = "Country must be a 2-letter code (got '{$data['country']}').";
        }

        // Ownership type — optional, validated.
        if (! empty($data['ownership_type'])) {
            $data['ownership_type'] = strtolower($data['ownership_type']);
            if (! in_array($data['ownership_type'], self::OWNERSHIP_TYPES, true)) {
                $warnings[] = "Unknown ownership_type '{$data['ownership_type']}'; will be left blank.";
                $data['ownership_type'] = null;
            }
        } else {
            $data['ownership_type'] = null;
        }

        // Integer-ish optional fields.
        foreach (['bedrooms', 'sleeps'] as $intKey) {
            if (! empty($data[$intKey])) {
                $clean = (int) preg_replace('/[^0-9]/', '', (string) $data[$intKey]);
                $data[$intKey] = $clean > 0 ? $clean : null;
            } else {
                $data[$intKey] = null;
            }
        }

        // Dates.
        foreach (['check_in_date', 'check_out_date'] as $dateKey) {
            if (! empty($data[$dateKey])) {
                try {
                    $data[$dateKey] = CarbonImmutable::parse((string) $data[$dateKey])->toDateString();
                } catch (Throwable $e) {
                    $errors[] = "Could not parse {$dateKey}: '{$data[$dateKey]}'.";
                    $data[$dateKey] = null;
                }
            }
        }
        if (! empty($data['check_in_date']) && ! empty($data['check_out_date'])
            && $data['check_out_date'] <= $data['check_in_date']) {
            $errors[] = 'check_out_date must be after check_in_date.';
        }

        // Prices.
        foreach (['asking_price', 'reserve_price'] as $priceKey) {
            $raw = $data[$priceKey] ?? '';
            if ($raw === '' || $raw === null) {
                $data[$priceKey] = null;
                continue;
            }
            $cleaned = preg_replace('/[^0-9.\-]/', '', (string) $raw) ?? '';
            if ($cleaned === '' || ! is_numeric($cleaned)) {
                $errors[] = "Invalid {$priceKey}: '{$raw}'.";
                $data[$priceKey] = null;
            } else {
                $data[$priceKey] = (float) $cleaned;
            }
        }
        if ($data['asking_price'] === null && empty(array_filter($errors, fn ($e) => str_contains($e, 'asking_price')))) {
            $errors[] = 'Missing required field: asking_price.';
        }

        // Commission percent.
        if (! empty($data['our_commission_pct'])) {
            $cleaned = preg_replace('/[^0-9.\-]/', '', (string) $data['our_commission_pct']) ?? '';
            if ($cleaned === '' || ! is_numeric($cleaned)) {
                $warnings[] = "Invalid commission percent; defaulting to 15.";
                $data['our_commission_pct'] = 15.0;
            } else {
                $pct = (float) $cleaned;
                if ($pct < 0 || $pct > 100) {
                    $errors[] = "Commission must be 0-100 (got {$pct}).";
                }
                $data['our_commission_pct'] = $pct;
            }
        } else {
            $data['our_commission_pct'] = null;
        }

        // go_live flag — "yes" / "y" / "true" / "1" → true.
        $live = strtolower((string) ($data['go_live'] ?? ''));
        $data['go_live'] = in_array($live, ['1', 'y', 'yes', 'true', 'on'], true);

        // Grouping keys for the preview.
        $data['owner_key'] = $this->ownerKey($data['owner_email'], $data['owner_phone']);
        $data['property_key'] = $this->propertyKey(
            $data['owner_key'],
            $data['resort_name'] ?? '',
            $data['city'] ?? '',
            $data['state'] ?? '',
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

    public function ownerKey(?string $email, ?string $phone): string
    {
        // Email wins because it's stable across phone-number changes;
        // phone is the fallback when email is missing.
        if (! empty($email)) {
            return 'e:'.strtolower(trim($email));
        }
        if (! empty($phone)) {
            return 'p:'.preg_replace('/[^0-9+]/', '', $phone);
        }

        return '';
    }

    public function propertyKey(string $ownerKey, string $resortName, string $city, string $state): string
    {
        return $ownerKey.'|'.strtolower(trim($resortName)).'|'
            .strtolower(trim($city)).'|'.strtoupper(trim($state));
    }
}
