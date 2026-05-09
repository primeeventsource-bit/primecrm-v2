<?php

declare(strict_types=1);

namespace App\Modules\Commission\Application\Services;

use App\Modules\Commission\Domain\Models\CommissionEvent;
use App\Modules\Commission\Domain\Models\CommissionPlanRule;
use App\Modules\Commission\Domain\ValueObjects\RoleRecipient;

/**
 * Pure rule-evaluation math. Given a CommissionEvent, a CommissionPlanRule,
 * and a recipient, returns the calculation to write — or null if the
 * rule doesn't apply (mismatched event type, zero base, etc.).
 *
 * The returned shape is NOT a model — it's an array describing the
 * calculation. The engine writes the row.
 */
final class RuleEvaluator
{
    /**
     * @return array{
     *   user_id: string,
     *   role: string,
     *   commission_plan_rule_id: string,
     *   base_amount: float,
     *   rate: ?float,
     *   amount: float,
     *   currency: string,
     *   explanation: array<string, mixed>,
     * }|null
     */
    public function evaluate(CommissionEvent $event, CommissionPlanRule $rule, RoleRecipient $recipient): ?array
    {
        if ($rule->trigger_event !== $event->event_type) {
            return null;
        }

        $payload = (array) $event->payload;
        $config = (array) $rule->config;

        return match ($rule->rule_type) {
            CommissionPlanRule::TYPE_FLAT => $this->evaluateFlat($rule, $recipient, $config, $payload),
            CommissionPlanRule::TYPE_PERCENTAGE => $this->evaluatePercentage($rule, $recipient, $config, $payload),
            CommissionPlanRule::TYPE_TIERED => $this->evaluateTiered($rule, $recipient, $config, $payload),
            default => null, // bonus + override handled in higher-level services
        };
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function evaluateFlat(CommissionPlanRule $rule, RoleRecipient $recipient, array $config, array $payload): ?array
    {
        $amount = isset($config['amount']) ? (float) $config['amount'] : 0.0;
        if ($amount === 0.0) {
            return null;
        }

        return [
            'user_id' => $recipient->userId,
            'role' => $recipient->role,
            'commission_plan_rule_id' => $rule->id,
            'base_amount' => 0.0,
            'rate' => null,
            'amount' => $amount,
            'currency' => (string) ($payload['currency'] ?? 'USD'),
            'explanation' => [
                'rule_type' => CommissionPlanRule::TYPE_FLAT,
                'amount' => $amount,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function evaluatePercentage(CommissionPlanRule $rule, RoleRecipient $recipient, array $config, array $payload): ?array
    {
        $rate = isset($config['rate']) ? (float) $config['rate'] : 0.0;
        $base = $this->extractBase($config, $payload);

        if ($rate <= 0 || $base <= 0) {
            return null;
        }

        $amount = round($base * $rate, 2);

        return [
            'user_id' => $recipient->userId,
            'role' => $recipient->role,
            'commission_plan_rule_id' => $rule->id,
            'base_amount' => $base,
            'rate' => $rate,
            'amount' => $amount,
            'currency' => (string) ($payload['currency'] ?? 'USD'),
            'explanation' => [
                'rule_type' => CommissionPlanRule::TYPE_PERCENTAGE,
                'rate' => $rate,
                'base' => $base,
                'base_field' => $config['base_field'] ?? 'amount',
            ],
        ];
    }

    /**
     * Tiered bracket evaluation.
     *
     * Two modes via config:
     *   marginal=false (default) — find the bracket the base falls in,
     *                              apply that bracket's rate to the WHOLE base
     *   marginal=true            — split base across brackets (true marginal)
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function evaluateTiered(CommissionPlanRule $rule, RoleRecipient $recipient, array $config, array $payload): ?array
    {
        $brackets = (array) ($config['brackets'] ?? []);
        $base = $this->extractBase($config, $payload);
        $marginal = (bool) ($config['marginal'] ?? false);

        if (empty($brackets) || $base <= 0) {
            return null;
        }

        // Sort brackets ascending by `up_to` (null = unbounded top tier)
        usort($brackets, function ($a, $b) {
            $au = $a['up_to'] ?? PHP_FLOAT_MAX;
            $bu = $b['up_to'] ?? PHP_FLOAT_MAX;

            return $au <=> $bu;
        });

        if ($marginal) {
            $amount = 0.0;
            $remaining = $base;
            $previousCap = 0.0;
            $traceBrackets = [];

            foreach ($brackets as $bracket) {
                $cap = $bracket['up_to'] ?? PHP_FLOAT_MAX;
                $rate = (float) ($bracket['rate'] ?? 0.0);
                $portionCap = $cap - $previousCap;
                $portion = min($remaining, $portionCap);

                if ($portion > 0) {
                    $amount += $portion * $rate;
                    $traceBrackets[] = ['portion' => $portion, 'rate' => $rate];
                    $remaining -= $portion;
                }
                $previousCap = $cap;

                if ($remaining <= 0) {
                    break;
                }
            }

            return [
                'user_id' => $recipient->userId,
                'role' => $recipient->role,
                'commission_plan_rule_id' => $rule->id,
                'base_amount' => $base,
                'rate' => null, // varies per bracket
                'amount' => round($amount, 2),
                'currency' => (string) ($payload['currency'] ?? 'USD'),
                'explanation' => [
                    'rule_type' => CommissionPlanRule::TYPE_TIERED,
                    'mode' => 'marginal',
                    'brackets' => $traceBrackets,
                    'base' => $base,
                ],
            ];
        }

        // Non-marginal: pick the matching bracket; apply rate to whole base.
        $appliedRate = 0.0;
        foreach ($brackets as $bracket) {
            $cap = $bracket['up_to'] ?? PHP_FLOAT_MAX;
            if ($base <= $cap) {
                $appliedRate = (float) ($bracket['rate'] ?? 0.0);
                break;
            }
        }

        if ($appliedRate <= 0) {
            return null;
        }

        return [
            'user_id' => $recipient->userId,
            'role' => $recipient->role,
            'commission_plan_rule_id' => $rule->id,
            'base_amount' => $base,
            'rate' => $appliedRate,
            'amount' => round($base * $appliedRate, 2),
            'currency' => (string) ($payload['currency'] ?? 'USD'),
            'explanation' => [
                'rule_type' => CommissionPlanRule::TYPE_TIERED,
                'mode' => 'flat',
                'rate' => $appliedRate,
                'base' => $base,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $payload
     */
    private function extractBase(array $config, array $payload): float
    {
        $field = (string) ($config['base_field'] ?? 'amount');

        return (float) ($payload[$field] ?? 0);
    }
}
