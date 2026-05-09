<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Controllers;

use App\Modules\Compliance\Application\Services\ComplianceGuardrailService;
use App\Modules\Lead\Domain\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Diagnostic endpoint surfaced for the agent UI: "can I dial this lead
 * right now?". Reports the same guardrail decision the dialer would see
 * but DOES NOT fire LeadRejectedByCompliance or write to audit log
 * (the request is pure read).
 */
final class GuardrailController extends Controller
{
    public function __construct(
        private readonly ComplianceGuardrailService $guardrail,
    ) {}

    public function check(Request $request, string $leadId): JsonResponse
    {
        $request->validate([
            'mode' => ['nullable', 'in:manual,preview,progressive,predictive'],
        ]);

        $lead = Lead::query()->findOrFail($leadId);

        $decision = $this->guardrail->explainFor(
            $lead,
            $request->string('mode', 'predictive')->value(),
        );

        return response()->json([
            'lead_id' => $lead->id,
            'decision' => $decision->toArray(),
        ]);
    }
}
