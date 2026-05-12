<?php

declare(strict_types=1);

namespace App\Modules\Listing\Application\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use League\Csv\Reader;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

/**
 * Parses a CSV/Excel upload into normalized rental-booking rows.
 *
 * Each row identifies a listing (either by listing_id directly, or
 * by owner_email/phone + resort + listing_check_in_date) plus a
 * renter + total price. Listings must exist already — the importer
 * does NOT create listings retroactively from booking rows because
 * the asking_price / commission etc. would be guessed.
 *
 * Header detection mirrors the inventory + listing parsers: same
 * canonicalization rules, generous synonym map.
 */
final class RentalBookingCsvParser
{
    /**
     * @var array<string, array<int, string>>
     */
    private const HEADER_ALIASES = [
        'listing_id' => ['listingid', 'listing'],
        'owner_email' => ['owneremail', 'email', 'ownermail'],
        'owner_phone' => ['ownerphone', 'phone', 'ownermobile'],
        'resort_name' => ['resortname', 'resort', 'propertyname', 'property'],
        'city' => ['city', 'locationcity'],
        'state' => ['state', 'locationstate'],
        'listing_check_in_date' => ['listingcheckindate', 'listingcheckin',
            'listingarrival', 'listingstartdate'],

        'renter_name' => ['rentername', 'guest', 'guestname', 'name'],
        'renter_email' => ['renteremail', 'guestemail'],
        'renter_phone' => ['renterphone', 'guestphone'],

        'check_in_date' => ['checkindate', 'checkin', 'arrival', 'startdate',
            'bookingcheckin', 'fromdate'],
        'check_out_date' => ['checkoutdate', 'checkout', 'departure', 'enddate',
            'bookingcheckout', 'todate'],

        'total_price' => ['totalprice', 'price', 'rate', 'amount', 'total',
            'bookingamount', 'rentalamount'],
        'commission_pct' => ['commissionpct', 'commission', 'fee', 'feepct'],
        'payment_status' => ['paymentstatus', 'payment', 'paid'],
        'confirmation_number' => ['confirmationnumber', 'confirmation', 'confnum'],
    ];

    private const PAYMENT_STATUSES = ['pending', 'deposit_paid', 'paid_in_full'];

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

        // Required: renter_name, total_price, plus enough columns to
        // identify the listing (either listing_id alone, or owner-id
        // + resort + listing_check_in).
        $required = ['renter_name', 'total_price'];
        $missing = array_diff($required, array_values($headerMap));
        if (! empty($missing)) {
            return [
                'rows' => [],
                'summary' => [
                    'total_rows' => 0,
                    'valid_rows' => 0,
                    'invalid_rows' => 0,
                    'fatal_error' => 'Missing required columns: '.implode(', ', $missing)
                        .'. Download the template or rename your columns.',
                ],
            ];
        }
        $hasListingPath = in_array('listing_id', $headerMap, true);
        $hasOwnerPath = (in_array('owner_email', $headerMap, true)
                || in_array('owner_phone', $headerMap, true))
            && in_array('resort_name', $headerMap, true)
            && in_array('listing_check_in_date', $headerMap, true);
        if (! $hasListingPath && ! $hasOwnerPath) {
            return [
                'rows' => [],
                'summary' => [
                    'total_rows' => 0,
                    'valid_rows' => 0,
                    'invalid_rows' => 0,
                    'fatal_error' => 'Need either a listing_id column, or all of '
                        .'(owner_email/owner_phone, resort_name, listing_check_in_date) '
                        .'to identify the listing.',
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
            if ($needle === str_replace('_', '', $canonical)) {
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

        // Listing identifier — one of (listing_id) or (owner + resort
        // + listing_check_in_date) must be usable.
        $listingIdRaw = $data['listing_id'] ?? '';
        $hasListingId = $listingIdRaw !== ''
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $listingIdRaw) === 1;
        if ($listingIdRaw !== '' && ! $hasListingId) {
            $errors[] = "Invalid listing_id format: '{$listingIdRaw}'.";
        }
        $data['listing_id'] = $hasListingId ? strtolower($listingIdRaw) : null;

        // Owner / resort path — only validated when we don't have a
        // direct listing_id. Lets the operator mix both styles in one
        // sheet (some rows know the id, some don't).
        $email = strtolower($data['owner_email'] ?? '');
        $phone = preg_replace('/[^0-9+]/', '', $data['owner_phone'] ?? '') ?? '';
        $data['owner_email'] = $email !== '' ? $email : null;
        $data['owner_phone'] = $phone !== '' ? $phone : null;

        if (! $hasListingId) {
            if ($email === '' && $phone === '') {
                $errors[] = 'Missing owner identifier: need owner_email or owner_phone (or set listing_id).';
            }
            if (empty($data['resort_name'])) {
                $errors[] = 'Missing resort_name (or set listing_id).';
            }
            if (empty($data['listing_check_in_date'])) {
                $errors[] = 'Missing listing_check_in_date (or set listing_id).';
            } else {
                try {
                    $data['listing_check_in_date'] = CarbonImmutable::parse(
                        (string) $data['listing_check_in_date']
                    )->toDateString();
                } catch (Throwable $e) {
                    $errors[] = "Could not parse listing_check_in_date: '{$data['listing_check_in_date']}'.";
                    $data['listing_check_in_date'] = null;
                }
            }
        } else {
            $data['listing_check_in_date'] = null;
        }

        // State normalize for the lookup path.
        if (! empty($data['state'])) {
            $data['state'] = strtoupper($data['state']);
        }

        // Renter — name required, contact optional but at least one
        // is a strong hint we may want for owner-notification email.
        if (empty($data['renter_name'])) {
            $errors[] = 'Missing renter_name.';
        }
        if (! empty($data['renter_email']) && ! filter_var($data['renter_email'], FILTER_VALIDATE_EMAIL)) {
            $warnings[] = "Invalid renter_email: '{$data['renter_email']}'; leaving blank.";
            $data['renter_email'] = null;
        }

        // Booking-specific dates default to the listing's window at
        // commit time; we just normalize what's here.
        foreach (['check_in_date', 'check_out_date'] as $dateKey) {
            if (! empty($data[$dateKey])) {
                try {
                    $data[$dateKey] = CarbonImmutable::parse((string) $data[$dateKey])->toDateString();
                } catch (Throwable $e) {
                    $warnings[] = "Could not parse {$dateKey}: '{$data[$dateKey]}'; will default to listing.";
                    $data[$dateKey] = null;
                }
            } else {
                $data[$dateKey] = null;
            }
        }

        // Total price — required, dollar/comma tolerant.
        if (empty($data['total_price'])) {
            $errors[] = 'Missing total_price.';
            $data['total_price'] = null;
        } else {
            $cleaned = preg_replace('/[^0-9.\-]/', '', (string) $data['total_price']) ?? '';
            if ($cleaned === '' || ! is_numeric($cleaned)) {
                $errors[] = "Invalid total_price: '{$data['total_price']}'.";
                $data['total_price'] = null;
            } else {
                $data['total_price'] = (float) $cleaned;
            }
        }

        // Commission percent — optional override; the importer falls
        // back to the listing's stored pct, then to 15.
        if (! empty($data['commission_pct'])) {
            $cleaned = preg_replace('/[^0-9.\-]/', '', (string) $data['commission_pct']) ?? '';
            if ($cleaned === '' || ! is_numeric($cleaned)) {
                $warnings[] = 'Invalid commission_pct; will use listing default.';
                $data['commission_pct'] = null;
            } else {
                $pct = (float) $cleaned;
                if ($pct < 0 || $pct > 100) {
                    $errors[] = "Commission must be 0-100 (got {$pct}).";
                }
                $data['commission_pct'] = $pct;
            }
        } else {
            $data['commission_pct'] = null;
        }

        // Payment status — validated, defaults to pending.
        if (! empty($data['payment_status'])) {
            $ps = strtolower((string) $data['payment_status']);
            // Tolerate a few common synonyms.
            $ps = match ($ps) {
                'paid', 'fullpaid', 'full' => 'paid_in_full',
                'deposit', 'partial' => 'deposit_paid',
                default => $ps,
            };
            if (! in_array($ps, self::PAYMENT_STATUSES, true)) {
                $warnings[] = "Unknown payment_status '{$data['payment_status']}'; defaulting to pending.";
                $ps = 'pending';
            }
            $data['payment_status'] = $ps;
        } else {
            $data['payment_status'] = 'pending';
        }

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
}
