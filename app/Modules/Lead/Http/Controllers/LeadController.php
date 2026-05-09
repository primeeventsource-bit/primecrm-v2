<?php

declare(strict_types=1);

namespace App\Modules\Lead\Http\Controllers;

use App\Core\Shared\Services\AuditLogService;
use App\Core\Shared\Services\PhoneNormalizer;
use App\Modules\Lead\Application\Actions\CreateLeadAction;
use App\Modules\Lead\Application\DTOs\LeadInputData;
use App\Modules\Lead\Domain\Events\LeadStatusChanged;
use App\Modules\Lead\Domain\Models\Lead;
use App\Modules\Lead\Http\Requests\StoreLeadRequest;
use App\Modules\Lead\Http\Requests\UpdateLeadRequest;
use App\Modules\Lead\Http\Resources\LeadResource;
use App\Support\Enums\LeadStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;

final class LeadController extends Controller
{
    public function __construct(
        private readonly CreateLeadAction $createLead,
        private readonly PhoneNormalizer $phoneNormalizer,
        private readonly AuditLogService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', 'string'],
            'assigned_agent_id' => ['nullable', 'uuid'],
            'priority' => ['nullable', 'string'],
            'min_score' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'sort' => ['nullable', 'string', 'in:score,created_at,last_contacted_at'],
            'direction' => ['nullable', 'string', 'in:asc,desc'],
        ]);

        $query = Lead::query();

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->value());
        }
        if ($request->filled('assigned_agent_id')) {
            $query->where('assigned_agent_id', $request->string('assigned_agent_id')->value());
        }
        if ($request->filled('priority')) {
            $query->where('priority', $request->string('priority')->value());
        }
        if ($request->filled('min_score')) {
            $query->where('score', '>=', (int) $request->integer('min_score'));
        }
        if ($request->filled('q')) {
            $needle = '%'.mb_strtolower((string) $request->string('q')).'%';
            $query->where(function ($q) use ($needle): void {
                $q->whereRaw('LOWER(first_name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(last_name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$needle])
                    ->orWhere('phone', 'LIKE', $needle);
            });
        }

        $sort = $request->string('sort', 'score')->value();
        $direction = $request->string('direction', 'desc')->value();

        $page = $query
            ->orderBy($sort, $direction)
            ->paginate(min(200, (int) $request->integer('per_page', 25)));

        return LeadResource::collection($page)->response();
    }

    public function store(StoreLeadRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $normalized = $this->phoneNormalizer->normalizeAndHash($validated['phone']);

        if ($normalized === null) {
            throw ValidationException::withMessages([
                'phone' => ['Phone number could not be parsed to a valid format.'],
            ]);
        }

        [$phone, $hash] = $normalized;

        $altPhone = null;
        $altHash = null;
        if (! empty($validated['alternate_phone'])) {
            $altNormalized = $this->phoneNormalizer->normalizeAndHash($validated['alternate_phone']);
            if ($altNormalized !== null) {
                [$altPhone, $altHash] = $altNormalized;
            }
        }

        $input = new LeadInputData(
            phone: $phone,
            phoneHash: $hash,
            firstName: $validated['first_name'] ?? null,
            lastName: $validated['last_name'] ?? null,
            email: $validated['email'] ?? null,
            alternatePhone: $altPhone,
            alternatePhoneHash: $altHash,
            country: $validated['country'] ?? null,
            state: $validated['state'] ?? null,
            city: $validated['city'] ?? null,
            postalCode: $validated['postal_code'] ?? null,
            timezone: $validated['timezone'] ?? null,
            source: $validated['source'],
            sourceCampaign: $validated['source_campaign'] ?? null,
            sourceMedium: $validated['source_medium'] ?? null,
            sourceMetadata: $validated['source_metadata'] ?? null,
            resortInterest: $validated['resort_interest'] ?? null,
            propertyType: $validated['property_type'] ?? null,
            estimatedValue: isset($validated['estimated_value']) ? (float) $validated['estimated_value'] : null,
            priority: $validated['priority'] ?? 'normal',
        );

        $result = $this->createLead->execute($input);

        return (new LeadResource($result['lead']))
            ->additional([
                'meta' => [
                    'was_duplicate' => $result['was_duplicate'],
                    'match_type' => $result['dedup']->matchType,
                ],
            ])
            ->response()
            ->setStatusCode($result['was_duplicate'] ? 200 : 201);
    }

    public function show(string $id): LeadResource
    {
        $lead = Lead::query()->findOrFail($id);

        return new LeadResource($lead);
    }

    public function update(UpdateLeadRequest $request, string $id): LeadResource
    {
        $lead = Lead::query()->findOrFail($id);
        $validated = $request->validated();

        $oldStatus = $lead->status;
        $statusChanging = isset($validated['status']) && $validated['status'] !== $oldStatus?->value;

        $lead->update($validated);

        if ($statusChanging) {
            $this->audit->record(
                action: 'lead.status_changed',
                entityType: 'lead',
                entityId: $lead->id,
                changes: ['status' => ['from' => $oldStatus?->value, 'to' => $validated['status']]],
            );

            LeadStatusChanged::dispatch(
                $lead->fresh(),
                $oldStatus ?? LeadStatus::New,
                LeadStatus::from($validated['status']),
                $request->user()?->id,
            );
        }

        return new LeadResource($lead->fresh());
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $lead = Lead::query()->findOrFail($id);
        $lead->delete();

        $this->audit->record(
            action: 'lead.deleted',
            entityType: 'lead',
            entityId: $lead->id,
        );

        return response()->json(['ok' => true]);
    }
}
