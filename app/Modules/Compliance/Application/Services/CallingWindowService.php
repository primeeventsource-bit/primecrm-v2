<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Services;

use App\Core\Shared\TenantContext;
use App\Modules\Compliance\Domain\Enums\GuardrailRejectionCode;
use App\Modules\Compliance\Domain\Models\CallingWindow;
use App\Modules\Compliance\Domain\ValueObjects\GuardrailDecision;
use App\Modules\Lead\Domain\Models\Lead;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * TCPA calling-window enforcement.
 *
 * Federal rule: 8am – 9pm local time at the called party's location.
 * Many states layer additional restrictions (Sunday bans, holiday bans).
 *
 * Resolution order for the called party's timezone:
 *   1. lead.timezone if explicitly set
 *   2. AreaCodeTimezoneResolver from phone area code
 *   3. Fallback: tenant default timezone
 *   4. Last resort: UTC (callers should ensure better data)
 *
 * Rule resolution order (most specific wins):
 *   1. tenant + state-specific row (e.g. {tenant_id: X, jurisdiction: 'US-CA'})
 *   2. tenant + 'US-FED' (per-tenant federal default)
 *   3. global + state-specific row
 *   4. global + 'US-FED'
 *   5. config/telephony.php tcpa.* defaults if nothing in DB
 *
 * Rules are cached for 5 minutes — they're rarely edited and reading
 * them on every dial would saturate the DB on a busy dialer.
 */
final class CallingWindowService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AreaCodeTimezoneResolver $tzResolver,
    ) {}

    public function check(Lead $lead, ?CarbonImmutable $now = null): GuardrailDecision
    {
        $now ??= CarbonImmutable::now();
        $tz = $this->resolveTimezone($lead);

        $localNow = $now->setTimezone($tz);
        $rule = $this->resolveRule($lead);

        // Earliest/latest are stored as 'HH:MM:SS' strings; compare on the
        // local-time portion only (date irrelevant).
        $earliest = $rule['earliest_local'];
        $latest = $rule['latest_local'];
        $localTime = $localNow->format('H:i:s');

        if ($localTime < $earliest || $localTime >= $latest) {
            return GuardrailDecision::reject(
                code: GuardrailRejectionCode::OutsideCallingWindow,
                reason: sprintf(
                    'Call attempted at %s local; allowed window is %s–%s.',
                    $localTime,
                    $earliest,
                    $latest,
                ),
                metadata: [
                    'lead_timezone' => $tz,
                    'jurisdiction' => $rule['jurisdiction'],
                    'local_time' => $localTime,
                    'window' => "{$earliest}–{$latest}",
                ],
            );
        }

        // Weekday block (e.g. no Sunday calls in some states).
        $blockedWeekdays = $rule['blocked_weekdays'] ?? [];
        if (! empty($blockedWeekdays) && in_array((int) $localNow->dayOfWeek, $blockedWeekdays, true)) {
            return GuardrailDecision::reject(
                code: GuardrailRejectionCode::BlockedWeekday,
                reason: sprintf(
                    '%s is a blocked weekday in jurisdiction %s.',
                    $localNow->englishDayOfWeek,
                    $rule['jurisdiction'],
                ),
                metadata: [
                    'weekday' => $localNow->englishDayOfWeek,
                    'jurisdiction' => $rule['jurisdiction'],
                ],
            );
        }

        // Holiday block.
        $blockedDates = $rule['blocked_dates'] ?? [];
        $localDate = $localNow->format('Y-m-d');
        $localDateMonthDay = $localNow->format('m-d');

        // Accept either Y-m-d (specific year) or m-d (recurring annual)
        if (in_array($localDate, $blockedDates, true) || in_array($localDateMonthDay, $blockedDates, true)) {
            return GuardrailDecision::reject(
                code: GuardrailRejectionCode::BlockedHoliday,
                reason: 'Today is a blocked holiday for this jurisdiction.',
                metadata: [
                    'date' => $localDate,
                    'jurisdiction' => $rule['jurisdiction'],
                ],
            );
        }

        return GuardrailDecision::allow([
            'lead_timezone' => $tz,
            'jurisdiction' => $rule['jurisdiction'],
        ]);
    }

    private function resolveTimezone(Lead $lead): string
    {
        if (! empty($lead->timezone)) {
            return $lead->timezone;
        }

        $resolved = $this->tzResolver->resolve($lead->phone);
        if ($resolved !== null) {
            return $resolved;
        }

        $tenantTz = config('app.timezone');

        return $tenantTz ?: 'UTC';
    }

    /**
     * @return array{
     *   jurisdiction: string,
     *   earliest_local: string,
     *   latest_local: string,
     *   blocked_weekdays: array<int>,
     *   blocked_dates: array<string>,
     * }
     */
    private function resolveRule(Lead $lead): array
    {
        $tenantId = $this->tenantContext->id();
        $stateCode = $lead->state ? mb_strtoupper($lead->state) : null;
        $cacheKey = "calling_windows:{$tenantId}:".($stateCode ?? 'NA');

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($tenantId, $stateCode): array {
            $jurisdictions = array_filter([
                $stateCode !== null ? "US-{$stateCode}" : null,
                'US-FED',
            ]);

            $candidates = CallingWindow::query()
                ->active()
                ->whereIn('jurisdiction', $jurisdictions)
                ->where(function ($q) use ($tenantId): void {
                    $q->whereNull('tenant_id');
                    if ($tenantId !== null) {
                        $q->orWhere('tenant_id', $tenantId);
                    }
                })
                ->get();

            // Specificity: tenant-specific state > tenant-specific federal >
            // global state > global federal. Use ranking to pick one.
            $ranked = $candidates->sortByDesc(function (CallingWindow $w) use ($tenantId, $stateCode) {
                $tenantSpecific = ($w->tenant_id !== null && $w->tenant_id === $tenantId) ? 2 : 0;
                $stateSpecific = ($stateCode !== null && $w->jurisdiction === "US-{$stateCode}") ? 1 : 0;

                return $tenantSpecific + $stateSpecific;
            });

            $best = $ranked->first();

            if ($best !== null) {
                return [
                    'jurisdiction' => $best->jurisdiction,
                    'earliest_local' => $best->earliest_local,
                    'latest_local' => $best->latest_local,
                    'blocked_weekdays' => (array) ($best->blocked_weekdays ?? []),
                    'blocked_dates' => (array) ($best->blocked_dates ?? []),
                ];
            }

            // Fallback to config defaults.
            $cfg = config('telephony.tcpa');

            return [
                'jurisdiction' => 'US-FED',
                'earliest_local' => sprintf('%02d:00:00', (int) ($cfg['min_call_local_hour'] ?? 8)),
                'latest_local' => sprintf('%02d:00:00', (int) ($cfg['max_call_local_hour'] ?? 21)),
                'blocked_weekdays' => [],
                'blocked_dates' => [],
            ];
        });
    }
}
