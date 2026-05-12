<?php

declare(strict_types=1);

namespace App\Modules\Booking\Http\Controllers;

use App\Core\Shared\TenantContext;
use App\Modules\Booking\Application\Services\InventoryCsvParser;
use App\Modules\Booking\Application\Services\InventoryImporter;
use App\Modules\Booking\Domain\Models\InventoryAvailability;
use App\Modules\Booking\Domain\Models\InventoryUnit;
use App\Modules\Booking\Domain\Models\Resort;
use App\Modules\Booking\Http\Requests\InventoryBulkImportRequest;
use App\Modules\Booking\Http\Requests\InventoryBulkPreviewRequest;
use App\Modules\Booking\Http\Requests\StoreInventoryAvailabilityRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Inventory CRUD for operators: add a single resort+unit+availability
 * triple, or bulk-import many via CSV/XLSX.
 *
 *   POST /api/inventory/availability        single add (auto-create
 *                                           resort/unit if needed)
 *   GET  /api/inventory/resorts-picker      typeahead for the form
 *   GET  /api/inventory/units-picker        typeahead, scoped to a resort
 *   GET  /api/inventory/template.csv        downloads the CSV template
 *   POST /api/inventory/bulk-preview        upload → parsed preview JSON
 *   POST /api/inventory/bulk-import         commit the previewed import
 *
 * Search-side endpoints (GET /search, holds, bookings) live in the
 * other controllers; this class is purely write-side.
 *
 * Bulk flow:
 *   1. Operator uploads a file. We parse, validate per-row, group
 *      "new entities" we'd create, and stash the parsed payload in
 *      cache keyed by a one-time token.
 *   2. Frontend renders a preview: rows, errors, per-new-resort and
 *      per-new-unit checkboxes ("create this entity? yes/no").
 *   3. Operator posts the token + approval flags. We pull from cache,
 *      filter to approved entities, and commit inside one DB
 *      transaction. Token expires after 30 minutes.
 */
final class InventoryManagementController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly InventoryCsvParser $parser,
        private readonly InventoryImporter $importer,
    ) {}

    /* ----------------------------------------------------------------
     | Singular add
     | ---------------------------------------------------------------- */

    public function store(StoreInventoryAvailabilityRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $tenantId = $this->tenantContext->id();

        // Resolve resort — either existing or create-new inline.
        if (! empty($validated['resort_id'])) {
            $resort = Resort::query()->find($validated['resort_id']);
            if ($resort === null) {
                return response()->json([
                    'message' => 'Resort not found in this workspace.',
                    'errors' => ['resort_id' => ['Resort not found in this workspace.']],
                ], 422);
            }
        } else {
            $resort = $this->importer->findOrCreateResort(
                $tenantId,
                $validated['resort_new']['name'],
                $validated['resort_new']['brand'] ?? null,
                $validated['resort_new']['city'],
                strtoupper($validated['resort_new']['state']),
                $validated['resort_new']['country'] ?? 'US',
                $validated['resort_new']['timezone'] ?? 'UTC',
            );
        }

        // Resolve unit — either existing under that resort, or create.
        if (! empty($validated['unit_id'])) {
            $unit = InventoryUnit::query()
                ->where('resort_id', $resort->id)
                ->find($validated['unit_id']);
            if ($unit === null) {
                return response()->json([
                    'message' => 'Unit does not belong to the selected resort.',
                    'errors' => ['unit_id' => ['Unit does not belong to the selected resort.']],
                ], 422);
            }
        } else {
            $unit = $this->importer->findOrCreateUnit(
                $tenantId,
                $resort->id,
                $validated['unit_new']['unit_type'],
                (int) $validated['unit_new']['sleeps'],
                $validated['unit_new']['features'] ?? null,
            );
        }

        // Build the availability row.
        $checkIn = CarbonImmutable::parse($validated['check_in_date']);
        $checkOut = CarbonImmutable::parse($validated['check_out_date']);
        $nights = $checkIn->diffInDays($checkOut);

        // Duplicate check at the app layer — PostgreSQL has a partial
        // unique index but MySQL doesn't enforce it. We re-check here
        // so the UI gets a clean 409 instead of a 500.
        $existing = InventoryAvailability::query()
            ->where('inventory_unit_id', $unit->id)
            ->where('check_in_date', $checkIn->toDateString())
            ->whereIn('status', [
                InventoryAvailability::STATUS_AVAILABLE,
                InventoryAvailability::STATUS_HELD,
                InventoryAvailability::STATUS_BOOKED,
            ])
            ->first();

        if ($existing !== null) {
            return response()->json([
                'message' => 'A live availability row already exists for this unit and check-in date.',
                'errors' => ['check_in_date' => ['This unit already has a live row for that date.']],
                'data' => ['existing_availability_id' => $existing->id],
            ], 409);
        }

        $price = (float) $validated['base_price'];
        $currency = strtoupper($validated['currency'] ?? 'USD');

        $availability = InventoryAvailability::query()->create([
            'tenant_id' => $tenantId,
            'resort_id' => $resort->id,
            'inventory_unit_id' => $unit->id,
            'check_in_date' => $checkIn->toDateString(),
            'check_out_date' => $checkOut->toDateString(),
            'nights' => $nights,
            'status' => InventoryAvailability::STATUS_AVAILABLE,
            'base_price' => $price,
            'current_price' => $price,
            'currency' => $currency,
        ]);

        return response()->json([
            'message' => 'Inventory row created.',
            'data' => [
                'id' => $availability->id,
                'resort' => [
                    'id' => $resort->id,
                    'name' => $resort->name,
                    'created_now' => empty($validated['resort_id']),
                ],
                'unit' => [
                    'id' => $unit->id,
                    'unit_type' => $unit->unit_type,
                    'sleeps' => (int) $unit->sleeps,
                    'created_now' => empty($validated['unit_id']),
                ],
                'check_in_date' => $availability->check_in_date->toDateString(),
                'check_out_date' => $availability->check_out_date->toDateString(),
                'base_price' => (float) $availability->base_price,
            ],
        ], 201);
    }

    /* ----------------------------------------------------------------
     | Pickers — typeaheads for the singular form
     | ---------------------------------------------------------------- */

    public function resortsPicker(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'only_active' => ['nullable', 'boolean'],
        ]);

        $tenantId = $this->tenantContext->id();
        $q = $request->string('q')->value();
        $onlyActive = $request->boolean('only_active', true);

        $query = DB::table('resorts')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at');

        if ($onlyActive) {
            $query->where('active', true);
        }

        if ($q !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';
            $query->where(function ($qq) use ($like): void {
                $qq->where('name', 'like', $like)
                    ->orWhere('brand', 'like', $like)
                    ->orWhere('city', 'like', $like);
            });
        }

        $rows = $query->orderBy('name')
            ->limit(50)
            ->get(['id', 'name', 'brand', 'city', 'state', 'country', 'timezone']);

        return response()->json([
            'data' => $rows->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'brand' => $r->brand,
                'city' => $r->city,
                'state' => $r->state,
                'country' => $r->country,
                'timezone' => $r->timezone,
            ])->values(),
        ]);
    }

    public function unitsPicker(Request $request): JsonResponse
    {
        $request->validate([
            'resort_id' => ['required', 'uuid'],
            'only_active' => ['nullable', 'boolean'],
        ]);

        $tenantId = $this->tenantContext->id();
        $onlyActive = $request->boolean('only_active', true);

        $query = DB::table('inventory_units')
            ->where('tenant_id', $tenantId)
            ->where('resort_id', $request->string('resort_id')->value());

        if ($onlyActive) {
            $query->where('active', true);
        }

        $rows = $query->orderBy('unit_type')
            ->orderBy('sleeps')
            ->get(['id', 'unit_type', 'sleeps', 'features']);

        return response()->json([
            'data' => $rows->map(fn ($r) => [
                'id' => $r->id,
                'unit_type' => $r->unit_type,
                'sleeps' => (int) $r->sleeps,
                'features' => $r->features !== null ? json_decode($r->features, true) : [],
            ])->values(),
        ]);
    }

    /* ----------------------------------------------------------------
     | Bulk: template, preview, import
     | ---------------------------------------------------------------- */

    public function template(): Response
    {
        // The example rows are intentionally diverse — different
        // resorts, brands, unit types — so the operator can see what
        // a real import looks like, not just header names.
        $headers = [
            'resort_name', 'resort_brand', 'city', 'state', 'country',
            'unit_type', 'sleeps', 'features',
            'check_in_date', 'check_out_date', 'base_price', 'currency',
        ];
        $examples = [
            ['Marriott Newport Coast Villas', 'Marriott', 'Newport Beach', 'CA', 'US',
                '2br', '6', 'ocean_view,balcony', '2026-07-04', '2026-07-11', '2450.00', 'USD'],
            ['Westgate Park City Resort', 'Westgate', 'Park City', 'UT', 'US',
                '1br', '4', '', '2026-12-26', '2027-01-02', '3100.00', 'USD'],
            ['Hilton Hawaiian Village', 'Hilton', 'Honolulu', 'HI', 'US',
                'studio', '2', '', '2026-09-15', '2026-09-22', '1800.00', 'USD'],
        ];

        $lines = [implode(',', $headers)];
        foreach ($examples as $row) {
            $lines[] = implode(',', array_map(fn ($v) => $this->csvField($v), $row));
        }

        return response(implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="inventory-template.csv"',
        ]);
    }

    public function bulkPreview(InventoryBulkPreviewRequest $request): JsonResponse
    {
        $tenantId = $this->tenantContext->id();
        $file = $request->file('file');

        $parsed = $this->parser->parse($file);

        // Resolve which rows match existing resorts/units (so we know
        // what counts as "new"). The parser doesn't touch the DB; we
        // do that here against the current tenant.
        $resolved = $this->importer->resolveAgainstTenant($tenantId, $parsed);

        // Stash the parsed+resolved payload under a one-time token
        // so the import endpoint doesn't need a re-upload. 30-min TTL
        // matches the typical operator review window.
        $token = (string) Str::uuid();
        Cache::put(
            $this->previewCacheKey($tenantId, $token),
            $resolved,
            now()->addMinutes(30),
        );

        return response()->json([
            'preview_token' => $token,
            'summary' => $resolved['summary'],
            'rows' => $resolved['rows'],
            'new_resorts' => $resolved['new_resorts'],
            'new_units' => $resolved['new_units'],
        ]);
    }

    public function bulkImport(InventoryBulkImportRequest $request): JsonResponse
    {
        $tenantId = $this->tenantContext->id();
        $token = (string) $request->string('preview_token');

        $payload = Cache::get($this->previewCacheKey($tenantId, $token));
        if ($payload === null) {
            return response()->json([
                'message' => 'Preview has expired. Please re-upload the file.',
                'errors' => ['preview_token' => ['Preview expired.']],
            ], 410);
        }

        /** @var array<string> $approvedResortKeys */
        $approvedResortKeys = (array) $request->input('approved_resort_keys', []);
        /** @var array<string> $approvedUnitKeys */
        $approvedUnitKeys = (array) $request->input('approved_unit_keys', []);

        $result = $this->importer->commit(
            $tenantId,
            $payload,
            $approvedResortKeys,
            $approvedUnitKeys,
        );

        // Burn the token so a duplicate POST doesn't double-import.
        Cache::forget($this->previewCacheKey($tenantId, $token));

        return response()->json([
            'message' => sprintf(
                'Imported %d availability row%s (%d resorts created, %d units created, %d skipped).',
                $result['availability_created'],
                $result['availability_created'] === 1 ? '' : 's',
                $result['resorts_created'],
                $result['units_created'],
                $result['rows_skipped'],
            ),
            'data' => $result,
        ], 201);
    }

    /* ----------------------------------------------------------------
     | Helpers
     | ---------------------------------------------------------------- */

    private function previewCacheKey(string $tenantId, string $token): string
    {
        return "inventory:bulk-preview:{$tenantId}:{$token}";
    }

    private function csvField(string $value): string
    {
        if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }
}
