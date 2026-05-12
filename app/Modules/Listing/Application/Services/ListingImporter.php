<?php

declare(strict_types=1);

namespace App\Modules\Listing\Application\Services;

use App\Modules\Lead\Domain\Models\Lead;
use App\Modules\Listing\Domain\Enums\ListingStatus;
use App\Modules\Listing\Domain\Models\Listing;
use App\Modules\Listing\Domain\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Resolves parsed listing rows against the tenant's existing leads
 * and properties, then commits the bulk import.
 *
 * Two-phase, mirroring InventoryImporter:
 *
 *   resolveAgainstTenant() — attach existing entity ids OR flag rows
 *                            as "new owner / new property" so the
 *                            preview UI can ask for approval.
 *
 *   commit()               — given approval flags, find-or-create
 *                            owners + properties, then create listings
 *                            in one DB transaction. Rows whose new
 *                            entity wasn't approved get skipped, not
 *                            failed.
 *
 * Owner match priority: email exact-match within tenant, falling
 * back to phone (digits-only normalized). Property match within an
 * owner: lower(resort_name) + lower(city) + upper(state).
 */
final class ListingImporter
{
    public function __construct(private readonly ListingCsvParser $parser) {}

    /**
     * @param  array{rows: array<int, array<string, mixed>>, summary: array<string, mixed>}  $parsed
     * @return array{
     *   rows: array<int, array<string, mixed>>,
     *   summary: array<string, mixed>,
     *   new_owners: array<int, array<string, mixed>>,
     *   new_properties: array<int, array<string, mixed>>,
     * }
     */
    public function resolveAgainstTenant(string $tenantId, array $parsed): array
    {
        $rows = $parsed['rows'];
        $summary = $parsed['summary'];

        // Pull every email/phone we'll need in one round-trip rather
        // than N queries — same idea as the inventory resolver.
        $emails = [];
        $phones = [];
        foreach ($rows as $r) {
            if (! empty($r['data']['owner_email'])) {
                $emails[] = strtolower($r['data']['owner_email']);
            }
            if (! empty($r['data']['owner_phone'])) {
                $phones[] = preg_replace('/[^0-9+]/', '', $r['data']['owner_phone']);
            }
        }
        $emails = array_values(array_unique($emails));
        $phones = array_values(array_unique(array_filter($phones)));

        $ownersByEmail = [];
        $ownersByPhone = [];
        if (! empty($emails) || ! empty($phones)) {
            $existingLeads = Lead::query()
                ->where(function ($q) use ($emails, $phones): void {
                    if (! empty($emails)) {
                        $q->orWhereIn(DB::raw('LOWER(email)'), $emails);
                    }
                    if (! empty($phones)) {
                        $q->orWhereIn('phone', $phones);
                    }
                })
                ->get(['id', 'email', 'phone']);

            foreach ($existingLeads as $l) {
                if ($l->email !== null) {
                    $ownersByEmail[strtolower($l->email)] = $l->id;
                }
                if ($l->phone !== null) {
                    $ownersByPhone[(string) $l->phone] = $l->id;
                }
            }
        }

        // Walk rows: figure out owner_id (existing or new), then
        // property_id. Build the preview's new-entity groupings.
        $newOwnersByKey = [];
        $newPropertiesByKey = [];
        $resolvedRows = [];

        // We can't pre-cache property lookups efficiently before we
        // know the owner_id, so we walk the rows and query properties
        // per row. To avoid N queries, we batch-load properties for
        // each owner the first time we hit them.
        $propertiesCachedForOwner = [];
        $propertyMapByOwner = [];

        foreach ($rows as $row) {
            $rk = $row['data']['owner_key'] ?? '';
            $pk = $row['data']['property_key'] ?? '';

            $ownerId = null;
            if (! empty($row['data']['owner_email'])) {
                $ownerId = $ownersByEmail[strtolower($row['data']['owner_email'])] ?? null;
            }
            if ($ownerId === null && ! empty($row['data']['owner_phone'])) {
                $ownerId = $ownersByPhone[(string) $row['data']['owner_phone']] ?? null;
            }

            // Try property match (only possible if owner exists).
            $propertyId = null;
            if ($ownerId !== null) {
                if (! isset($propertiesCachedForOwner[$ownerId])) {
                    $props = Property::query()
                        ->where('owner_id', $ownerId)
                        ->get(['id', 'resort_name', 'location_city', 'location_state']);
                    foreach ($props as $p) {
                        $key = $this->parser->propertyKey(
                            $rk,
                            (string) $p->resort_name,
                            (string) $p->location_city,
                            (string) $p->location_state,
                        );
                        $propertyMapByOwner[$ownerId][$key] = $p->id;
                    }
                    $propertiesCachedForOwner[$ownerId] = true;
                }
                $propertyId = $propertyMapByOwner[$ownerId][$pk] ?? null;
            }

            // Build new-entity preview entries. Only valid rows count
            // toward "new" — invalid rows don't get to suggest entity
            // creation.
            if ($row['valid'] && $ownerId === null && $rk !== '') {
                if (! isset($newOwnersByKey[$rk])) {
                    $newOwnersByKey[$rk] = [
                        'key' => $rk,
                        'email' => $row['data']['owner_email'],
                        'phone' => $row['data']['owner_phone'],
                        'first_name' => $row['data']['owner_first_name'] ?? null,
                        'last_name' => $row['data']['owner_last_name'] ?? null,
                        'row_count' => 0,
                    ];
                }
                $newOwnersByKey[$rk]['row_count']++;
            }
            if ($row['valid'] && $propertyId === null && $pk !== '') {
                if (! isset($newPropertiesByKey[$pk])) {
                    $newPropertiesByKey[$pk] = [
                        'key' => $pk,
                        'owner_key' => $rk,
                        'owner_email' => $row['data']['owner_email'],
                        'resort_name' => $row['data']['resort_name'],
                        'resort_brand' => $row['data']['resort_brand'] ?? null,
                        'city' => $row['data']['city'],
                        'state' => $row['data']['state'],
                        'country' => $row['data']['country'] ?? 'US',
                        'row_count' => 0,
                    ];
                }
                $newPropertiesByKey[$pk]['row_count']++;
            }

            $resolvedRows[] = array_merge($row, [
                'owner_match' => $ownerId !== null ? 'existing' : 'new',
                'property_match' => $propertyId !== null ? 'existing' : 'new',
                'owner_id' => $ownerId,
                'property_id' => $propertyId,
            ]);
        }

        $summary['new_owners_count'] = count($newOwnersByKey);
        $summary['new_properties_count'] = count($newPropertiesByKey);

        return [
            'rows' => $resolvedRows,
            'summary' => $summary,
            'new_owners' => array_values($newOwnersByKey),
            'new_properties' => array_values($newPropertiesByKey),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>    $approvedOwnerKeys
     * @param  array<int, string>    $approvedPropertyKeys
     * @return array{
     *   owners_created: int,
     *   properties_created: int,
     *   listings_created: int,
     *   rows_skipped: int,
     *   skip_reasons: array<int, array{row_num: int, reason: string}>,
     * }
     */
    public function commit(
        string $tenantId,
        array $payload,
        array $approvedOwnerKeys,
        array $approvedPropertyKeys,
    ): array {
        $approvedOwnerSet = array_flip($approvedOwnerKeys);
        $approvedPropertySet = array_flip($approvedPropertyKeys);

        $ownersCreated = 0;
        $propertiesCreated = 0;
        $listingsCreated = 0;
        $skipped = 0;
        $skipReasons = [];

        // Cache to avoid re-resolving across rows that share an owner
        // or property.
        $ownerIdByKey = [];
        $propertyIdByKey = [];

        foreach ($payload['rows'] as $row) {
            if (! empty($row['owner_id']) && ! empty($row['data']['owner_key'])) {
                $ownerIdByKey[$row['data']['owner_key']] = $row['owner_id'];
            }
            if (! empty($row['property_id']) && ! empty($row['data']['property_key'])) {
                $propertyIdByKey[$row['data']['property_key']] = $row['property_id'];
            }
        }

        DB::transaction(function () use (
            $tenantId, $payload,
            $approvedOwnerSet, $approvedPropertySet,
            &$ownerIdByKey, &$propertyIdByKey,
            &$ownersCreated, &$propertiesCreated, &$listingsCreated,
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

                $rk = $row['data']['owner_key'];
                $pk = $row['data']['property_key'];

                // Resolve owner.
                $ownerId = $ownerIdByKey[$rk] ?? null;
                if ($ownerId === null) {
                    if (! isset($approvedOwnerSet[$rk])) {
                        $skipped++;
                        $skipReasons[] = [
                            'row_num' => $row['row_num'],
                            'reason' => 'new owner not approved',
                        ];

                        continue;
                    }
                    $owner = $this->createOwner($tenantId, $row['data']);
                    $ownerId = $owner->id;
                    $ownerIdByKey[$rk] = $ownerId;
                    $ownersCreated++;
                }

                // Resolve property.
                $propertyId = $propertyIdByKey[$pk] ?? null;
                if ($propertyId === null) {
                    if (! isset($approvedPropertySet[$pk])) {
                        $skipped++;
                        $skipReasons[] = [
                            'row_num' => $row['row_num'],
                            'reason' => 'new property not approved',
                        ];

                        continue;
                    }
                    $property = $this->createProperty($tenantId, $ownerId, $row['data']);
                    $propertyId = $property->id;
                    $propertyIdByKey[$pk] = $propertyId;
                    $propertiesCreated++;
                }

                // Soft-dedup: same (property_id, check_in_date) with
                // an active listing → skip instead of stacking dupes.
                $existing = Listing::query()
                    ->where('property_id', $propertyId)
                    ->where('check_in_date', $row['data']['check_in_date'])
                    ->whereNotIn('status', [
                        ListingStatus::Cancelled->value,
                        ListingStatus::UnrentedExpired->value,
                    ])
                    ->whereNull('deleted_at')
                    ->first();

                if ($existing !== null) {
                    $skipped++;
                    $skipReasons[] = [
                        'row_num' => $row['row_num'],
                        'reason' => 'duplicate listing (property + check-in already active)',
                    ];

                    continue;
                }

                $askingPrice = (float) $row['data']['asking_price'];
                $commissionPct = $row['data']['our_commission_pct'] !== null
                    ? (float) $row['data']['our_commission_pct']
                    : null;
                $ownerPayout = $commissionPct !== null
                    ? round($askingPrice * (1 - $commissionPct / 100), 2)
                    : null;

                $status = $row['data']['go_live']
                    ? ListingStatus::Live->value
                    : ListingStatus::PendingDistribution->value;

                Listing::query()->create([
                    'tenant_id' => $tenantId,
                    'property_id' => $propertyId,
                    'check_in_date' => $row['data']['check_in_date'],
                    'check_out_date' => $row['data']['check_out_date'],
                    'asking_price' => $askingPrice,
                    'reserve_price' => $row['data']['reserve_price'],
                    'owner_payout' => $ownerPayout,
                    'our_commission_pct' => $commissionPct,
                    'status' => $status,
                    'went_live_at' => $status === ListingStatus::Live->value
                        ? CarbonImmutable::now() : null,
                    'marketing_description' => $row['data']['marketing_description'] ?? null,
                ]);

                $listingsCreated++;
            }
        });

        return [
            'owners_created' => $ownersCreated,
            'properties_created' => $propertiesCreated,
            'listings_created' => $listingsCreated,
            'rows_skipped' => $skipped,
            'skip_reasons' => $skipReasons,
        ];
    }

    /**
     * @param  array<string, mixed>  $rowData
     */
    private function createOwner(string $tenantId, array $rowData): Lead
    {
        $phone = $rowData['owner_phone'] ?? null;

        return Lead::query()->create([
            'tenant_id' => $tenantId,
            'first_name' => $rowData['owner_first_name'] ?? null,
            'last_name' => $rowData['owner_last_name'] ?? null,
            'email' => $rowData['owner_email'] ?? null,
            'phone' => $phone,
            // Match the singular-create dedup path: SHA-256 of the
            // normalized phone. Empty when no phone is on file.
            'phone_hash' => $phone !== null && $phone !== ''
                ? hash('sha256', (string) $phone)
                : null,
            'country' => $rowData['country'] ?? 'US',
            'state' => $rowData['state'] ?? null,
            'city' => $rowData['city'] ?? null,
            'source' => 'csv_import',
            'priority' => 'normal',
        ]);
    }

    /**
     * @param  array<string, mixed>  $rowData
     */
    private function createProperty(string $tenantId, string $ownerId, array $rowData): Property
    {
        return Property::query()->create([
            'tenant_id' => $tenantId,
            'owner_id' => $ownerId,
            'resort_name' => $rowData['resort_name'],
            'resort_brand' => $rowData['resort_brand'] ?? null,
            'location_city' => $rowData['city'],
            'location_state' => $rowData['state'],
            'location_country' => $rowData['country'] ?? 'US',
            'unit_number' => $rowData['unit_number'] ?? null,
            'bedrooms' => $rowData['bedrooms'] ?? null,
            'sleeps' => $rowData['sleeps'] ?? null,
            'ownership_type' => $rowData['ownership_type'] ?? null,
            'ownership_verified' => false,
            'rental_allowed_by_resort' => false,
        ]);
    }
}
