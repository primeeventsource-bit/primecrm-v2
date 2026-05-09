<?php

declare(strict_types=1);

namespace App\Modules\Commission\Application\Services;

use App\Modules\Commission\Domain\Models\CommissionEvent;
use App\Modules\Commission\Domain\Models\CommissionPlanRule;
use App\Modules\Commission\Domain\ValueObjects\RoleRecipient;
use App\Modules\Sales\Domain\Models\Deal;

/**
 * Resolves which user(s) should receive commission for a given role on
 * a given commission event.
 *
 * The mapping is event-type-specific:
 *
 *   payment.cleared  → look up the originating deal via payload.deal_id;
 *                      closer = deal.agent_id, fronter = deal.fronter_id,
 *                      override = deal.agent_id's manager (TODO until
 *                      manager hierarchy lands; for now override is
 *                      explicit per-rule).
 *
 *   deal.closed_won  → same as above, off the deal directly.
 *
 *   payment.refunded / chargeback → reuse the original calculation's
 *                      user_id; the engine handles this path differently
 *                      (see CommissionEngine::reverseFromEvent).
 *
 * Multi-closer split scenarios (deal.additional_closer_ids non-null)
 * produce one RoleRecipient per closer with the role tagged accordingly.
 * The rule's config can specify split_among='all_closers' to fan out the
 * percentage; otherwise the primary closer takes 100%.
 */
final class RoleResolver
{
    /**
     * @return list<RoleRecipient>
     */
    public function recipientsFor(CommissionEvent $event, CommissionPlanRule $rule): array
    {
        $payload = (array) $event->payload;
        $dealId = $payload['deal_id'] ?? null;

        if ($dealId === null) {
            return [];
        }

        $deal = Deal::query()->find($dealId);
        if ($deal === null) {
            return [];
        }

        return match ($rule->role) {
            CommissionPlanRule::ROLE_CLOSER => $this->closers($deal, (array) $rule->config),
            CommissionPlanRule::ROLE_FRONTER => $deal->fronter_id !== null
                ? [new RoleRecipient(CommissionPlanRule::ROLE_FRONTER, $deal->fronter_id)]
                : [],
            CommissionPlanRule::ROLE_SUPERVISOR, CommissionPlanRule::ROLE_QA, CommissionPlanRule::ROLE_OVERRIDE
                => $this->fromRuleConfig($rule),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<RoleRecipient>
     */
    private function closers(Deal $deal, array $config): array
    {
        $primary = new RoleRecipient(CommissionPlanRule::ROLE_CLOSER, $deal->agent_id);

        if (($config['split_among'] ?? null) !== 'all_closers') {
            return [$primary];
        }

        $extras = is_array($deal->additional_closer_ids) ? $deal->additional_closer_ids : [];
        $out = [$primary];
        foreach ($extras as $id) {
            $out[] = new RoleRecipient(CommissionPlanRule::ROLE_CLOSER, (string) $id);
        }

        return $out;
    }

    /**
     * Supervisor/QA/override rules name explicit user ids in their config
     * (e.g. `config.user_id` or `config.user_ids: []`). This isn't a
     * hierarchy lookup yet — operators wire it explicitly. When the
     * org-chart module ships, this method becomes the swap-in point.
     *
     * @return list<RoleRecipient>
     */
    private function fromRuleConfig(CommissionPlanRule $rule): array
    {
        $config = (array) $rule->config;
        $ids = (array) ($config['user_ids'] ?? array_filter([$config['user_id'] ?? null]));

        return array_map(
            static fn (string $id) => new RoleRecipient($rule->role, $id),
            array_values(array_unique(array_map(fn ($id) => (string) $id, $ids))),
        );
    }
}
