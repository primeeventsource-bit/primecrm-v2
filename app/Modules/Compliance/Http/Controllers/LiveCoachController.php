<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Controllers;

use App\Modules\CallCenter\Domain\Models\Call;
use App\Modules\Compliance\Application\Services\CoachAlertDispatcher;
use App\Modules\Compliance\Application\Services\CoachContextBuilder;
use App\Modules\Compliance\Application\Services\CoachRuleEngine;
use App\Modules\Compliance\Application\Services\ProhibitedPhraseScanner;
use App\Modules\Sales\Domain\Models\Deal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * AI Live Coach endpoint — runs every time the agent's transcript
 * advances (or the agent presses "Coach me" manually for the demo).
 *
 *   POST /api/coach/suggestion
 *   {
 *     "call_id":   "uuid",        optional but recommended
 *     "deal_id":   "uuid",        optional
 *     "utterance": "agent text"
 *   }
 *
 *   GET  /api/coach/system-prompt
 *     Returns the verbatim §6 system prompt + per-call context.
 *     Lets compliance review the assistant's instructions in one
 *     place; lets an LLM integration consume the exact string.
 *
 * Pipeline:
 *   1. Scan the utterance for prohibited phrases.
 *   2. Run the rule engine to choose a priority + hint.
 *   3. If red_zone, dispatch the alert (flag recording + note + log).
 *   4. Return { priority, red_zone, hint, rationale, matches }.
 *
 * Wiring a real LLM (Anthropic / OpenAI):
 *   - Build the prompt via CoachContextBuilder->systemPrompt() +
 *     ->callContext() + the utterance.
 *   - Send to the model with low max_tokens (≤200) and 1.5s timeout.
 *   - REPLACE the rule's hint with the model's response UNLESS the
 *     rule produced a compliance_rescue priority — those always win.
 *   - Cache by (utterance hash) for 30s so the same line during a
 *     pause doesn't burn tokens.
 */
final class LiveCoachController extends Controller
{
    public function __construct(
        private readonly ProhibitedPhraseScanner $scanner,
        private readonly CoachRuleEngine $rules,
        private readonly CoachContextBuilder $context,
        private readonly CoachAlertDispatcher $alerts,
    ) {}

    public function suggestion(Request $request): JsonResponse
    {
        $request->validate([
            'call_id' => ['nullable', 'uuid'],
            'deal_id' => ['nullable', 'uuid'],
            'utterance' => ['required', 'string', 'max:5000'],
        ]);

        $utterance = (string) $request->string('utterance');
        $matches = $this->scanner->scan($utterance);
        $hint = $this->rules->nextHint($utterance, $matches);

        // Red-zone side effects — only when both the scanner found a
        // blocking match AND we have a call to attach the flag to.
        // No call_id → no audit trail to write; rule's hint still
        // returns so the agent gets the course-correction script.
        if ($hint['red_zone'] === true && $request->filled('call_id')) {
            $call = Call::query()->find((string) $request->string('call_id'));
            $agent = $request->user();
            if ($call !== null && $agent !== null) {
                $this->alerts->dispatchRedZone($call, $agent, $utterance, $matches);
            }
        }

        return response()->json([
            'priority' => $hint['priority'],
            'red_zone' => $hint['red_zone'],
            'hint' => $hint['hint'],
            'rationale' => $hint['rationale'],
            'matches' => $matches,
            'summary' => [
                'block_count' => count(array_filter($matches, fn ($m) => $m['severity'] === 'block')),
                'warn_count' => count(array_filter($matches, fn ($m) => $m['severity'] === 'warn')),
            ],
        ]);
    }

    public function systemPrompt(Request $request): JsonResponse
    {
        $request->validate([
            'call_id' => ['nullable', 'uuid'],
            'deal_id' => ['nullable', 'uuid'],
        ]);

        $callContext = null;
        if ($request->filled('call_id')) {
            $call = Call::query()->find((string) $request->string('call_id'));
            $deal = $request->filled('deal_id')
                ? Deal::query()->find((string) $request->string('deal_id'))
                : null;
            if ($call) {
                $callContext = $this->context->callContext($call, $deal);
            }
        }

        return response()->json([
            'system_prompt' => $this->context->systemPrompt(),
            'call_context' => $callContext,
        ]);
    }
}
