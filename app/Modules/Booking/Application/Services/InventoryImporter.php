<?php

declare(strict_types=1);

namespace App\Modules\Booking\Application\Services;

use App\Modules\Booking\Domain\Models\InventoryAvailability;
use App\Modules\Booking\Domain\Models\InventoryUnit;
use App\Modules\Booking\Domain\Models\Resort;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Resolves parsed rows against existing tenant entities and commits
 * the bulk import.
 *
 * Two phases live here:
 *
 *   resolveAgainstTenant()  — match parsed resort_key / unit_key
 *                             against existing rows; flag "new"
 *                             entities so the preview UI can ask the
 *                             operator to confirm them.
 *
 *   commit()                — given the previewed payload + the
 *                             operator's approval lists, create
 *                             resorts/units/availability rows in one
 *                             DB transaction. Rows whose required
 *                             new entity wasn't approved are skipped
 *                             (counted, not failed).
 *
 * The single-add path also calls findOrCreateResort / findOrCreateUnit
 * directly so the same matching logic stays in one place.
 */
final class InventoryImporter
{
    public function __construct(private readonly InventoryCsvParser $parser) {}

    /**
     * Map of resort_key/unit_key → existing entity id (if any), plus
     * the set of new entities the operator will be asked to approve.
     *
     * @param  array{
     *   rows: array<int, array<string, mixed>>,
     *   summary: array<string, mixed>,
     * }  $parsed
     * @return array{
     *   rows: array<int, array<string, mixed>>,
     *   summary: array<string, mixed>,
     *   new_resorts: array<int, array<string, mixed>>,
     *   new_units: array<int, array<string, mixed>>,
     * }
     */
    public function resolveAgainstTenant(string $tenantId, array $parsed): array
    {
        $rows = $parsed['rows'];
        $summary = $parsed['summary'];

        // Existing resorts by (name|city|state) key, lowercased.
        $existingResorts = DB::table('resorts')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->get(['id', 'name', 'city', 'state']);

        $existingResortMap = [];
        foreach ($existingResorts as $r) {
            $key = $this->parser->resortKey($r->name, $r->city, $r->state);
            $existingResortMap[$key] = $r->id;
        }

        // Existing units — only ones whose resort is in this tenant.
        $existingUnits = DB::table('inventory_units as u')
            ->join('resorts as r', 'r.id', '=', 'u.resort_id')
            ->where('u.tenant_id', $tenantId)
            ->whereNull('r.deleted_at')
            ->get(['u.id', 'u.resort_id', 'u.unit_type', 'u.sleeps',
                'r.name as resort_name', 'r.city', 'r.state']);

        $existingUnitMap = [];
        foreach ($existingUnits as $u) {
            $resortKey = $this->parser->resortKey($u->resort_name, $u->city, $u->state);
            $key = $this->parser->unitKey($resortKey, (string) $u->unit_type, (int) $u->sleeps);
            $existingUnitMap[$key] = ['id' => $u->id, 'resort_id' => $u->resort_id];
        }

        // Walk parsed rows, attach resort/unit match metadata, and
        // group "new entities" so the UI shows each one once with a
        // row-count, not 100 dupes.
        $newResortsByKey = [];
        $newUnitsByKey = [];
        $resolvedRows = [];

        foreach ($rows as $row) {
            $rk = $row['data']['resort_key'] ?? '';
            $uk = $row['data']['unit_key'] ?? '';

            $resortId = $existingResortMap[$rk] ?? null;
            $unitInfo = $existingUnitMap[$uk] ?? null;

            // Track new resorts (only valid rows count toward "new").
            if ($row['valid'] && $resortId === null && $rk !== '') {
                if (! isset($newResortsByKey[$rk])) {
                    $newResortsByKey[$rk] = [
                        'key' => $rk,
                        'name' => $row['data']['resort_name'],
                        'brand' => $row['data']['resort_brand'] ?? null,
                        'city' => $row['data']['city'],
                        'state' => $row['data']['state'],
                        'country' => $row['data']['country'] ?? 'US',
                        'row_count' => 0,
                    ];
                }
                $newResortsByKey[$rk]['row_count']++;
            }

            // Track new units (any unit on a new resort is, by
            // definition, new — but a new unit on an existing resort
            // is also new).
            if ($row['valid'] && $unitInfo === null && $uk !== '') {
                if (! isset($newUnitsByKey[$uk])) {
                    $newUnitsByKey[$uk] = [
                        'key' => $uk,
                        'resort_key' => $rk,
                        'resort_name' => $row['data']['resort_name'],
                        'unit_type' => $row['data']['unit_type'],
                        'sleeps' => $row['data']['sleeps'],
                        'features' => $row['data']['features'] ?? [],
                        'row_count' => 0,
                    ];
                }
                $newUnitsByKey[$uk]['row_count']++;
            }

            $resolvedRows[] = array_merge($row, [
                'resort_match' => $resortId !== null ? 'existing' : 'new',
                'unit_match' => $unitInfo !== null ? 'existing' : 'new',
                'resort_id' => $resortId,
                'unit_id' => $unitInfo['id'] ?? null,
            ]);
        }

        $summary['new_resorts_count'] = count($newResortsByKey);
        $summary['new_units_count'] = count($newUnitsByKey);

        return [
            'rows' => $resolvedRows,
            'summary' => $summary,
            'new_resorts' => array_values($newResortsByKey),
            'new_units' => array_values($newUnitsByKey),
        ];
    }

    /**
     * Commit a previewed import.
     *
     * @param  array<string, mixed>  $payload    output of resolveAgainstTenant()
     * @param  array<int, string>    $approvedResortKeys
     * @param  array<int, string>    $approvedUnitKeys
     * @return array{
     *   resorts_created: int,
     *   units_created: int,
     *   availability_created: int,
     *   rows_skipped: int,
     *   skip_reasons: array<int, array{row_num: int, reason: string}>,
     * }
     */
    public function commit(
        string $tenantId,
        array $payload,
        array $approvedResortKeys,
        array $approvedUnitKeys,
    ): array {
        $approvedResortSet = array_flip($approvedResortKeys);
        $approvedUnitSet = array_flip($approvedUnitKeys);

        $resortsCreated = 0;
        $unitsCreated = 0;
        $availabilityCreated = 0;
        $skipped = 0;
        $skipReasons = [];

        // Cache resort/unit ids during commit so we don't re-query
        // for every row that touches the same entity.
        $resortIdByKey = [];
        $unitInfoByKey = [];

        // Pre-seed cache with already-existing matches from preview.
        foreach ($payload['rows'] as $row) {
            $rk = $row['data']['resort_key'] ?? '';
            $uk = $row['data']['unit_key'] ?? '';
            if (! empty($row['resort_id']) && $rk !== '') {
                $resortIdByKey[$rk] = $row['resort_id'];
            }
            if (! empty($row['unit_id']) && $uk !== '') {
                $unitInfoByKey[$uk] = [
                    'id' => $row['unit_id'],
                    'resort_id' => $row['resort_id']
                        ?? $resortIdByKey[$rk]
                        ?? null,
                ];
            }
        }

        DB::transaction(function () use (
            $tenantId, $payload,
            $approvedResortSet, $approvedUnitSet,
            &$resortIdByKey, &$unitInfoByKey,
            &$resortsCreated, &$unitsCreated, &$availabilityCreated,
            &$skipped, &$skipReasons,
        ): void {
            foreach ($payload['rows'] as $row) {
                if (! $row['valid']) {
                    $skipped++;
                    $skipReasons[] = [
                        'row_num' => $row['row_num'],
                        'reason' => 'row has validation errors',
                    ];

                    continue;
                }

                $rk = $row['data']['resort_key'];
                $uk = $row['data']['unit_key'];

                // Resolve resort id.
                $resortId = $resortIdByKey[$rk] ?? null;
                if ($resortId === null) {
                    if (! isset($approvedResortSet[$rk])) {
                        $skipped++;
                        $skipReasons[] = [
                            'row_num' => $row['row_num'],
                            'reason' => 'new resort not approved',
                        ];

                        continue;
                    }
                    $resort = $this->findOrCreateResort(
                        $tenantId,
                        $row['data']['resort_name'],
                        $row['data']['resort_brand'] ?? null,
                        $row['data']['city'],
                        $row['data']['state'],
                        $row['data']['country'] ?? 'US',
                        $row['data']['timezone'] ?? 'UTC',
                    );
                    if ($resort->wasRecentlyCreated) {
                        $resortsCreated++;
                    }
                    $resortId = $resort->id;
                    $resortIdByKey[$rk] = $resortId;
                }

                // Resolve unit id.
                $unitInfo = $unitInfoByKey[$uk] ?? null;
                if ($unitInfo === null) {
                    if (! isset($approvedUnitSet[$uk])) {
                        $skipped++;
                        $skipReasons[] = [
                            'row_num' => $row['row_num'],
                            'reason' => 'new unit not approved',
                        ];

                        continue;
                    }
                    $unit = $this->findOrCreateUnit(
                        $tenantId,
                        $resortId,
                        $row['data']['unit_type'],
                        (int) $row['data']['sleeps'],
                        $row['data']['features'] ?? null,
                    );
                    if ($unit->wasRecentlyCreated) {
                        $unitsCreated++;
                    }
                    $unitInfo = ['id' => $unit->id, 'resort_id' => $resortId];
                    $unitInfoByKey[$uk] = $unitInfo;
                }

                // Skip duplicates — same unit + check-in already live.
                $existing = InventoryAvailability::query()
                    ->where('inventory_unit_id', $unitInfo['id'])
                    ->where('check_in_date', $row['data']['check_in_date'])
                    ->whereIn('status', [
                        InventoryAvailability::STATUS_AVAILABLE,
                        InventoryAvailability::STATUS_HELD,
                        InventoryAvailability::STATUS_BOOKED,
                    ])
                    ->first();

                if ($existing !== null) {
                    $skipped++;
                    $skipReasons[] = [
                        'row_num' => $row['row_num'],
                        'reason' => 'duplicate availability (unit + check-in already exists)',
                    ];

                    continue;
                }

                $checkIn = CarbonImmutable::parse($row['data']['check_in_date']);
                $checkOut = CarbonImmutable::parse($row['data']['check_out_date']);

                $price = (float) ($row['data']['base_price'] ?? 0);
                $currency = $row['data']['currency'] ?? 'USD';

                InventoryAvailability::query()->create([
                    'tenant_id' => $tenantId,
                    'resort_id' => $resortId,
                    'inventory_unit_id' => $unitInfo['id'],
                    'check_in_date' => $checkIn->toDateString(),
                    'check_out_date' => $checkOut->toDateString(),
                    'nights' => $checkIn->diffInDays($checkOut),
                    'status' => InventoryAvailability::STATUS_AVAILABLE,
                    'base_price' => $price,
                    'current_price' => $price,
                    'currency' => $currency,
                ]);

                $availabilityCreated++;
            }
        });

        return [
            'resorts_created' => $resortsCreated,
            'units_created' => $unitsCreated,
            'availability_created' => $availabilityCreated,
            'rows_skipped' => $skipped,
            'skip_reasons' => $skipReasons,
        ];
    }

    /**
     * Find a tenant resort by (name, city, state) — case-insensitive —
     * or create one. Used by both single-add and bulk-import paths.
     */
    public function findOrCreateResort(
        string $tenantId,
        string $name,
        ?string $brand,
        string $city,
        string $state,
        string $country = 'US',
        string $timezone = 'UTC',
    ): Resort {
        $existing = Resort::query()
            ->whereRaw('LOWER(name) = ?', [strtolower(trim($name))])
            ->whereRaw('LOWER(city) = ?', [strtolower(trim($city))])
            ->where('state', strtoupper(trim($state)))
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return Resort::query()->create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'brand' => $brand,
            'slug' => $this->uniqueSlug($tenantId, $name, $city, $state),
            'country' => strtoupper($country),
            'state' => strtoupper($state),
            'city' => $city,
            'timezone' => $timezone,
            'hold_ttl_minutes' => 30,
            'active' => true,
        ]);
    }

    /**
     * Find a tenant unit under a resort by (unit_type, sleeps), or
     * create one.
     */
    public function findOrCreateUnit(
        string $tenantId,
        string $resortId,
        string $unitType,
        int $sleeps,
        ?array $features = null,
    ): InventoryUnit {
        $existing = InventoryUnit::query()
            ->where('resort_id', $resortId)
            ->where('unit_type', strtolower($unitType))
            ->where('sleeps', $sleeps)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return InventoryUnit::query()->create([
            'tenant_id' => $tenantId,
            'resort_id' => $resortId,
            'unit_type' => strtolower($unitType),
            'sleeps' => $sleeps,
            'features' => $features ?: null,
            'active' => true,
        ]);
    }

    /**
     * Build a tenant-unique slug. The resorts table has a unique
     * index on (tenant_id, slug); collisions get a short hash
     * suffix rather than a numeric counter (cheaper for the
     * common-name case like "Marriott Resort").
     */
    private function uniqueSlug(string $tenantId, string $name, string $city, string $state): string
    {
        $base = Str::slug("{$name} {$city} {$state}");
        $candidate = $base;

        if (! Resort::query()->where('tenant_id', $tenantId)->where('slug', $candidate)->exists()) {
            return $candidate;
        }

        return $base.'-'.substr(md5(Str::uuid()->toString()), 0, 6);
    }
}
