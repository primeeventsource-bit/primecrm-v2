<?php

declare(strict_types=1);

namespace App\Modules\Dialer\Domain\ValueObjects;

/**
 * Output of one PacingEngine tick.
 *
 * Carries the arithmetic so it can be:
 *   - logged for ops debugging ("why did the dialer fire 47 calls just now?")
 *   - displayed on the supervisor war-room ("dial rate is 1.8×, abandons are 2.1%")
 *   - asserted on in tests (the math has to be right; the math is reviewable)
 */
final class PacingDecision
{
    public function __construct(
        public readonly int $dialsToFire,
        public readonly int $agentsAvailable,
        public readonly int $agentsOnCall,
        public readonly float $connectionRate,
        public readonly float $abandonRate,
        public readonly float $safetyFactor,
        public readonly float $rawRate,
        public readonly string $reason,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'dials_to_fire' => $this->dialsToFire,
            'agents_available' => $this->agentsAvailable,
            'agents_on_call' => $this->agentsOnCall,
            'connection_rate' => $this->connectionRate,
            'abandon_rate' => $this->abandonRate,
            'safety_factor' => $this->safetyFactor,
            'raw_rate' => $this->rawRate,
            'reason' => $this->reason,
        ];
    }
}
