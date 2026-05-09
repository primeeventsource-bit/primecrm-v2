<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Controllers;

use App\Modules\Compliance\Application\Actions\RecordConsentAction;
use App\Modules\Compliance\Domain\Enums\ConsentType;
use App\Modules\Compliance\Domain\Models\ConsentRecord;
use App\Modules\Compliance\Http\Requests\StoreConsentRequest;
use App\Modules\Compliance\Http\Resources\ConsentRecordResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class ConsentController extends Controller
{
    public function __construct(
        private readonly RecordConsentAction $recordConsent,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'lead_id' => ['nullable', 'uuid'],
            'phone_hash' => ['nullable', 'string', 'size:64'],
            'consent_type' => ['nullable', 'string'],
            'active_only' => ['nullable', 'boolean'],
        ]);

        $query = ConsentRecord::query()->orderByDesc('consented_at');

        if ($request->filled('lead_id')) {
            $query->where('lead_id', $request->string('lead_id')->value());
        }
        if ($request->filled('phone_hash')) {
            $query->where('phone_hash', $request->string('phone_hash')->value());
        }
        if ($request->filled('consent_type')) {
            $query->where('consent_type', $request->string('consent_type')->value());
        }
        if ($request->boolean('active_only')) {
            $query->whereNull('revoked_at');
        }

        $page = $query->paginate(min(200, (int) $request->integer('per_page', 50)));

        return ConsentRecordResource::collection($page)->response();
    }

    public function store(StoreConsentRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $consent = $this->recordConsent->execute(
            rawPhone: $validated['phone'],
            consentType: ConsentType::from($validated['consent_type']),
            source: $validated['source'],
            leadId: $validated['lead_id'] ?? null,
            sourceUrl: $validated['source_url'] ?? null,
            sourceIp: $validated['source_ip'] ?? $request->ip(),
            userAgent: $validated['user_agent'] ?? $request->userAgent(),
            recordingUrl: $validated['recording_url'] ?? null,
            consentTextSnapshot: $validated['consent_text_snapshot'] ?? null,
            consentedAt: isset($validated['consented_at'])
                ? new \DateTimeImmutable($validated['consented_at'])
                : null,
        );

        if ($consent === null) {
            return response()->json([
                'error' => 'Phone number could not be parsed to a valid format.',
            ], 422);
        }

        return (new ConsentRecordResource($consent))
            ->response()
            ->setStatusCode(201);
    }

    public function revoke(Request $request, string $id): JsonResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];

        $consent = $this->recordConsent->revoke($id, $reason);

        if ($consent === null) {
            return response()->json(['error' => 'Consent record not found.'], 404);
        }

        return (new ConsentRecordResource($consent))->response();
    }
}
