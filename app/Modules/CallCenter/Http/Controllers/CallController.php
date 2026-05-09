<?php

declare(strict_types=1);

namespace App\Modules\CallCenter\Http\Controllers;

use App\Modules\CallCenter\Application\Actions\EndCallAction;
use App\Modules\CallCenter\Application\Services\CallStateService;
use App\Modules\CallCenter\Domain\Models\Call;
use App\Modules\CallCenter\Http\Requests\DispositionCallRequest;
use App\Modules\CallCenter\Http\Resources\CallResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Read-side and disposition endpoints for calls.
 *
 * Initiation goes through the dialer (manual/preview/predictive). The
 * "click-to-call" agent action lives at POST /api/dialer/sessions/{id}/dial-now
 * because it has to flow through pacing/queue logic.
 */
final class CallController extends Controller
{
    public function __construct(
        private readonly CallStateService $callState,
        private readonly EndCallAction $endCall,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'agent_id' => ['nullable', 'uuid'],
            'lead_id' => ['nullable', 'uuid'],
            'live' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = Call::query()->orderByDesc('created_at');

        if ($request->filled('agent_id')) {
            $query->where('agent_id', $request->string('agent_id')->value());
        }
        if ($request->filled('lead_id')) {
            $query->where('lead_id', $request->string('lead_id')->value());
        }
        if ($request->boolean('live')) {
            $query->live();
        }

        $page = $query->paginate(min(200, (int) $request->integer('per_page', 25)));

        return CallResource::collection($page)->response();
    }

    public function show(string $id): CallResource
    {
        return new CallResource(Call::query()->findOrFail($id));
    }

    public function disposition(DispositionCallRequest $request, string $id): CallResource
    {
        $call = Call::query()->findOrFail($id);
        $validated = $request->validated();

        $updated = $this->callState->setDisposition(
            $call,
            $validated['disposition'],
            $validated['notes'] ?? null,
        );

        return new CallResource($updated);
    }

    public function end(string $id): CallResource
    {
        $call = Call::query()->findOrFail($id);
        $updated = $this->endCall->execute($call);

        return new CallResource($updated);
    }
}
