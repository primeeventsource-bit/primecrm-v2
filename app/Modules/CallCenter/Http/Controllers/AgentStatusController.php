<?php

declare(strict_types=1);

namespace App\Modules\CallCenter\Http\Controllers;

use App\Modules\CallCenter\Application\Services\AgentPresenceService;
use App\Modules\CallCenter\Domain\Models\AgentStatusRecord;
use App\Modules\CallCenter\Http\Requests\ChangeAgentStatusRequest;
use App\Modules\CallCenter\Http\Resources\AgentStatusResource;
use App\Support\Enums\AgentStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Agent presence — the agent's softphone client polls/pushes here.
 *
 *   POST /api/agent-status         change my status (or someone's, if supervisor)
 *   POST /api/agent-status/heartbeat   touch last_heartbeat_at
 *   GET  /api/agent-status/me      current state
 *   GET  /api/agent-status         list all (supervisor war room)
 */
final class AgentStatusController extends Controller
{
    public function __construct(private readonly AgentPresenceService $presence) {}

    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->role->canSupervise()) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $statuses = AgentStatusRecord::query()
            ->with('agent:id,name')
            ->orderBy('status_changed_at', 'desc')
            ->get();

        return AgentStatusResource::collection($statuses)->response();
    }

    public function me(Request $request): JsonResponse
    {
        $record = AgentStatusRecord::query()
            ->where('agent_id', $request->user()->id)
            ->first();

        if ($record === null) {
            return response()->json(['agent_id' => $request->user()->id, 'status' => 'offline']);
        }

        return (new AgentStatusResource($record))->response();
    }

    public function set(ChangeAgentStatusRequest $request): JsonResponse
    {
        $caller = $request->user();
        $validated = $request->validated();

        $targetAgentId = $validated['agent_id'] ?? $caller->id;

        if ($targetAgentId !== $caller->id && ! $caller->role->canSupervise()) {
            return response()->json(['error' => 'Cannot change another agent\'s status.'], 403);
        }

        $record = $this->presence->set(
            agentId: $targetAgentId,
            status: AgentStatus::from($validated['status']),
            sessionId: $validated['session_id'] ?? null,
        );

        return (new AgentStatusResource($record))->response();
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $this->presence->heartbeat($request->user()->id);

        return response()->json(['ok' => true]);
    }
}
