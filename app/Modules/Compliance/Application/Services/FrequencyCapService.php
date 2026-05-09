<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Services;

use App\Modules\Compliance\Domain\Enums\GuardrailRejectionCode;
use App\Modules\Compliance\Domain\Models\ContactAttempt;
use App\Modules\Compliance\Domain\ValueObjects\GuardrailDecision;
use Carbon\CarbonImmutable;

/**
 * Per-number contact frequency caps.
 *
 * Three checks, all configurable in config/telephony.php (tcpa.*):
 *
 *   1. Cooldown — minimum seconds since last attempt (default 4 hours).
 *      Prevents the same number being dialed back-to-back when an agent
 *      hangs up and immediately recycles the lead.
 *
 *   2. Daily cap — max attempts in the calling day (local time, default 3).
 *      Defined as the number of outbound contact_attempts since 00:00 in
 *      the lead's timezone.
 *
 *   3. Rolling 30-day cap (default 7).
 *
 * All three are queried in one SQL statement — separate trips to the DB
 * for each would multiply the guardrail's tail latency. The query keys
 * off (tenant_id, phone_hash, attempted_at) which is indexed.
 */
final class FrequencyCapService
{
    public function check(string $phoneHash, ?string $leadTimezone = null): GuardrailDecision
    {
        $config = config('telephony.tcpa');

        $minSeconds = (int) ($config['min_seconds_between_attempts_same_number'] ?? 14400);
        $dailyCap = (int) ($config['max_attempts_per_day_same_number'] ?? 3);
        $monthlyCap = (int) ($config['max_attempts_per_30days_same_number'] ?? 7);

        $tz = $leadTimezone !== null && $this->isValidTimezone($leadTimezone)
            ? $leadTimezone
            : 'UTC';

        $now = CarbonImmutable::now($tz);
        $startOfDay = $now->startOfDay()->utc();
        $thirtyDaysAgo = $now->subDays(30)->utc();
        $cooldownThreshold = $now->subSeconds($minSeconds)->utc();

        // Single aggregate query covering all three windows.
        // Tenant scope IS applied — the daily/30-day TCPA caps are per-caller,
        // not per-number, so each tenant tracks independently.
        $attempts = ContactAttempt::query()
            ->forPhone($phoneHash)
            ->outboundCalls()
            ->where('attempted_at', '>=', $thirtyDaysAgo)
            ->selectRaw('
                MAX(attempted_at) AS last_attempt_at,
                COUNT(*) FILTER (WHERE attempted_at >= ?) AS today_count,
                COUNT(*) FILTER (WHERE attempted_at >= ?) AS recent_count,
                COUNT(*) AS month_count
            ', [$startOfDay, $cooldownThreshold])
            ->first();

        if ($attempts === null || $attempts->last_attempt_at === null) {
            return GuardrailDecision::allow();
        }

        $lastAt = CarbonImmutable::parse($attempts->last_attempt_at);
        $secondsSinceLast = $now->utc()->diffInSeconds($lastAt);

        if ($secondsSinceLast < $minSeconds) {
            return GuardrailDecision::reject(
                code: GuardrailRejectionCode::FrequencyTooSoon,
                reason: sprintf(
                    'Last attempt was %d seconds ago; minimum gap is %d seconds.',
                    $secondsSinceLast,
                    $minSeconds,
                ),
                metadata: [
                    'last_attempt_at' => $lastAt->toIso8601String(),
                    'min_seconds_required' => $minSeconds,
                ],
            );
        }

        if ((int) $attempts->today_count >= $dailyCap) {
            return GuardrailDecision::reject(
                code: GuardrailRejectionCode::FrequencyDailyCap,
                reason: sprintf('Daily cap reached (%d attempts today).', $dailyCap),
                metadata: [
                    'today_count' => (int) $attempts->today_count,
                    'daily_cap' => $dailyCap,
                    'lead_timezone' => $tz,
                ],
            );
        }

        if ((int) $attempts->month_count >= $monthlyCap) {
            return GuardrailDecision::reject(
                code: GuardrailRejectionCode::FrequencyMonthlyCap,
                reason: sprintf('30-day cap reached (%d attempts).', $monthlyCap),
                metadata: [
                    'month_count' => (int) $attempts->month_count,
                    'monthly_cap' => $monthlyCap,
                ],
            );
        }

        return GuardrailDecision::allow([
            'today_count' => (int) $attempts->today_count,
            'month_count' => (int) $attempts->month_count,
            'last_attempt_at' => $lastAt->toIso8601String(),
        ]);
    }

    private function isValidTimezone(string $tz): bool
    {
        try {
            new \DateTimeZone($tz);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
