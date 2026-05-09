<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Actions;

use App\Modules\Compliance\Domain\Models\ContactAttempt;
use App\Modules\Lead\Domain\Models\Lead;
use Illuminate\Support\Facades\DB;

/**
 * Records a contact attempt against a phone hash.
 *
 * Called by the dialer at the moment of dial initiation (not at connect)
 * so even calls that are abandoned by the dialer count toward frequency
 * caps. This is what the FrequencyCapService keys off.
 *
 * The lead model's `last_contacted_at` and `contact_attempts` counter
 * are also bumped here — those drive the lead-staleness reassignment job.
 */
final class RecordContactAttemptAction
{
    public function execute(
        string $phoneHash,
        string $attemptType = ContactAttempt::ATTEMPT_OUTBOUND_CALL,
        ?string $leadId = null,
        ?string $agentId = null,
        ?string $callId = null,
        ?string $outcome = null,
    ): ContactAttempt {
        $attempt = ContactAttempt::query()->create([
            'phone_hash' => $phoneHash,
            'lead_id' => $leadId,
            'agent_id' => $agentId,
            'call_id' => $callId,
            'attempt_type' => $attemptType,
            'outcome' => $outcome,
            'attempted_at' => now(),
        ]);

        if ($leadId !== null) {
            Lead::query()
                ->where('id', $leadId)
                ->update([
                    'last_contacted_at' => now(),
                    'contact_attempts' => DB::raw('contact_attempts + 1'),
                ]);
        }

        return $attempt;
    }
}
