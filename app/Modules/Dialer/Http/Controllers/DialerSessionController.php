<?php

declare(strict_types=1);

namespace App\Modules\Dialer\Http\Controllers;

use App\Modules\CallCenter\Domain\Models\Campaign;
use App\Modules\Compliance\Application\Services\ComplianceGuardrailService;
use App\Modules\Dialer\Application\Jobs\DialLeadJob;
use App\Modules\Dialer\Application\Services\DialerService;
use App\Modules\Dialer\Domain\Models\DialSession;
use App\Modules\Dialer\Http\Requests\StartDialSessionRequest;
use App\Modules\Dialer\Http\Resources\DialSessionResource;
use App\Modules\Lead\Domain\Models\Lead;
use App\Support\Enums\DialerMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The agent's dialer-session lifecycle.
 *
 *   POST   /api/dialer/sessions                start
 *   POST   /api/dialer/sessions/{id}/pause     pause
 *   POST   /api/dialer/sessions/{id}/resume    resume
 *   POST   /api/dialer/sessions/{id}/stop      stop (terminal)
 *   GET    /api/dialer/sessions/active         my current session
 *
 *   POST   /api/dialer/sessions/{id}/dial-now  click-to-call (manual/preview).
 *                                              ALSO routes through the
 *                                              compliance guardrail. The
 *                                              guardrail is non-bypassable
 *                                              even from the manual path.
 */
final class DialerSessionController extends Controller
{
    public function __construct(
        private readonly DialerService $dialer,
        private readonly ComplianceGuardrailService $guardrail,
    ) {}

    public function active(Request $request): JsonResponse
    {
        $session = DialSession::query()
            ->forAgent($request->user()->id)
            ->whereIn('status', [DialSession::STATUS_ACTIVE, DialSession::STATUS_PAUSED])
            ->latest('started_at')
            ->first();

        if ($session === null) {
            return response()->json(null);
        }

        return (new DialSessionResource($session))->response();
    }

    public function start(StartDialSessionRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $campaign = isset($validated['campaign_id'])
            ? Campaign::query()->findOrFail($validated['campaign_id'])
            : null;

        $modeOverride = isset($validated['mode'])
            ? DialerMode::from($validated['mode'])
            : null;

        $session = $this->dialer->start($request->user(), $campaign, $modeOverride);

        return (new DialSessionResource($session))
            ->response()
            ->setStatusCode(201);
    }

    public function pause(Request $request, string $id): DialSessionResource
    {
        $session = $this->ownedSession($request, $id);

        return new DialSessionResource($this->dialer->pause($session));
    }

    public function resume(Request $request, string $id): DialSessionResource
    {
        $session = $this->ownedSession($request, $id);

        return new DialSessionResource($this->dialer->resume($session));
    }

    public function stop(Request $request, string $id): DialSessionResource
    {
        $session = $this->ownedSession($request, $id);

        return new DialSessionResource($this->dialer->stop($session, 'agent_request'));
    }

    /**
     * Click-to-call. Manual/preview modes use this; predictive/progressive
     * never call it (those flow through the pacing engine).
     */
    public function dialNow(Request $request, string $id): JsonResponse
    {
        $session = $this->ownedSession($request, $id);

        $validated = $request->validate([
            'lead_id' => ['required', 'uuid', 'exists:leads,id'],
        ]);

        $lead = Lead::query()->findOrFail($validated['lead_id']);

        // Manual click-to-call STILL goes through the guardrail. The dialer
        // mode determines the consent bar (manual is softer than predictive
        // — no autodialer consent strictly required) but DNC, frequency cap,
        // and calling-window checks all apply.
        $decision = $this->guardrail->mayDial($lead, $session->dialerMode()->value);

        if ($decision->isRejected()) {
            return response()->json([
                'error' => 'Compliance gate blocked the dial.',
                'decision' => $decision->toArray(),
            ], 422);
        }

        DialLeadJob::dispatch(
            leadId: $lead->id,
            sessionId: $session->id,
            campaignId: $session->campaign_id,
            agentIdHint: $request->user()->id,
            dialerMode: $session->dialerMode()->value,
        );

        return response()->json([
            'queued' => true,
            'session_id' => $session->id,
            'lead_id' => $lead->id,
        ], 202);
    }

    private function ownedSession(Request $request, string $sessionId): DialSession
    {
        $session = DialSession::query()->findOrFail($sessionId);

        if ($session->agent_id !== $request->user()->id && ! $request->user()->role->canSupervise()) {
            abort(403, 'You do not own this dial session.');
        }

        return $session;
    }
}
