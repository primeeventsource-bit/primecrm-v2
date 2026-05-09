<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Modules\Sales\Application\Services\DealService;
use App\Modules\Sales\Domain\Events\DealCreated;
use App\Modules\Sales\Domain\Models\Deal;
use App\Modules\Sales\Http\Requests\AdvanceStageRequest;
use App\Modules\Sales\Http\Requests\StoreDealRequest;
use App\Modules\Sales\Http\Resources\DealResource;
use App\Support\Enums\DealStage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

final class DealController extends Controller
{
    public function __construct(private readonly DealService $service) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'stage' => ['nullable', 'string'],
            'agent_id' => ['nullable', 'uuid'],
            'lead_id' => ['nullable', 'uuid'],
            'open' => ['nullable', 'boolean'],
        ]);

        $query = Deal::query()->orderByDesc('created_at');

        if ($request->boolean('open')) {
            $query->open();
        }
        if ($request->filled('stage')) {
            $query->where('stage', $request->string('stage')->value());
        }
        if ($request->filled('agent_id')) {
            $query->where('agent_id', $request->string('agent_id')->value());
        }
        if ($request->filled('lead_id')) {
            $query->where('lead_id', $request->string('lead_id')->value());
        }

        $page = $query->paginate(min(200, (int) $request->integer('per_page', 25)));

        return DealResource::collection($page)->response();
    }

    public function store(StoreDealRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $deal = DB::transaction(function () use ($validated): Deal {
            $totalValue = (float) $validated['total_value'];
            $snr = (float) ($validated['snr_amount'] ?? 0);
            $vd = (float) ($validated['vd_amount'] ?? 0);

            return Deal::query()->create([
                'lead_id' => $validated['lead_id'],
                'agent_id' => $validated['agent_id'],
                'fronter_id' => $validated['fronter_id'] ?? null,
                'additional_closer_ids' => $validated['additional_closer_ids'] ?? null,
                'stage' => $validated['stage'] ?? DealStage::New->value,
                'stage_changed_at' => now(),
                'total_value' => $totalValue,
                'snr_amount' => $snr,
                'vd_amount' => $vd,
                'payable_amount' => max(0, $totalValue - $snr - $vd),
                'currency' => $validated['currency'] ?? 'USD',
                'pitch_data' => $validated['pitch_data'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'expected_close_at' => $validated['expected_close_at'] ?? null,
            ]);
        });

        DealCreated::dispatch($deal);

        return (new DealResource($deal))->response()->setStatusCode(201);
    }

    public function show(string $id): DealResource
    {
        return new DealResource(Deal::query()->findOrFail($id));
    }

    public function advanceStage(AdvanceStageRequest $request, string $id): DealResource
    {
        $deal = Deal::query()->findOrFail($id);
        $validated = $request->validated();

        $updated = $this->service->advanceStage(
            $deal,
            DealStage::from($validated['stage']),
            $validated['reason'] ?? null,
            $request->user()?->id,
        );

        return new DealResource($updated);
    }
}
