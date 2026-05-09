<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Controllers;

use App\Core\Shared\TenantContext;
use App\Modules\Compliance\Application\Actions\AddDncEntryAction;
use App\Modules\Compliance\Domain\Enums\DncSource;
use App\Modules\Compliance\Domain\Models\DncEntry;
use App\Modules\Compliance\Http\Requests\StoreDncRequest;
use App\Modules\Compliance\Http\Resources\DncEntryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * DNC management endpoints.
 *
 * Note: DncEntry is NOT tenant-scoped at the model level (federal lists
 * are global). The list view filters explicitly to "this tenant + global
 * lists" so an operator sees what their dialer would actually be checking.
 */
final class DncController extends Controller
{
    public function __construct(
        private readonly AddDncEntryAction $addEntry,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->id();

        $query = DncEntry::query()
            ->where(function ($q) use ($tenantId): void {
                $q->whereNull('tenant_id'); // global lists
                if ($tenantId !== null) {
                    $q->orWhere('tenant_id', $tenantId);
                }
            })
            ->orderByDesc('created_at');

        if ($request->filled('source')) {
            $query->where('source', $request->string('source')->value());
        }

        $page = $query->paginate(min(200, (int) $request->integer('per_page', 50)));

        return DncEntryResource::collection($page)->response();
    }

    public function store(StoreDncRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $entry = $this->addEntry->execute(
            rawPhone: $validated['phone'],
            source: DncSource::from($validated['source']),
            reason: $validated['reason'] ?? null,
            addedBy: $request->user()?->id,
            effectiveDate: isset($validated['effective_date'])
                ? new \DateTimeImmutable($validated['effective_date'])
                : null,
            expiresAt: isset($validated['expires_at'])
                ? new \DateTimeImmutable($validated['expires_at'])
                : null,
        );

        if ($entry === null) {
            return response()->json([
                'error' => 'Phone number could not be parsed to a valid format.',
            ], 422);
        }

        return (new DncEntryResource($entry))
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(string $id): JsonResponse
    {
        $tenantId = $this->tenantContext->id();

        $entry = DncEntry::query()
            ->where('id', $id)
            ->where('tenant_id', $tenantId) // tenant-scoped entries only
            ->first();

        if ($entry === null) {
            return response()->json(['error' => 'Entry not found or is a global list entry.'], 404);
        }

        if ($entry->source instanceof DncSource && ! $entry->source->isUserEditable()) {
            return response()->json([
                'error' => 'This DNC source cannot be removed via the API.',
            ], 422);
        }

        $entry->delete();

        return response()->json(['ok' => true]);
    }
}
