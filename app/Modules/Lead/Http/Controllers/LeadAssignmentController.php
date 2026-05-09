<?php

declare(strict_types=1);

namespace App\Modules\Lead\Http\Controllers;

use App\Modules\Lead\Application\Services\LeadAssignmentService;
use App\Modules\Lead\Domain\Models\Lead;
use App\Modules\Lead\Http\Requests\AssignLeadRequest;
use App\Modules\Lead\Http\Resources\LeadResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Lead assignment endpoints.
 *
 *   POST /api/leads/{id}/assign
 *     - With agent_id: direct assignment to that agent (manual override).
 *     - Without agent_id: runs the routing engine in the requested mode.
 */
final class LeadAssignmentController extends Controller
{
    public function __construct(
        private readonly LeadAssignmentService $service,
    ) {}

    public function assign(AssignLeadRequest $request, string $id): JsonResponse
    {
        $lead = Lead::query()->findOrFail($id);
        $validated = $request->validated();

        if (! empty($validated['agent_id'])) {
            $agent = $this->service->reassign(
                $lead,
                $validated['agent_id'],
                $validated['reason'] ?? 'manual_reassignment',
            );
        } else {
            $agent = $this->service->assign($lead, $validated['mode'] ?? null);
        }

        if ($agent === null) {
            return response()->json([
                'error' => 'No eligible agent could be found for this lead.',
            ], 422);
        }

        return (new LeadResource($lead->fresh()))
            ->additional([
                'meta' => [
                    'assigned_agent_id' => $agent->id,
                    'assigned_agent_name' => $agent->fullName(),
                ],
            ])
            ->response();
    }
}
