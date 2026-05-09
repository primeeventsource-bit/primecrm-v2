<?php

declare(strict_types=1);

namespace App\Modules\Commission\Domain\ValueObjects;

/**
 * Resolved (role → user_id) tuple for a commission event.
 *
 * Produced by RoleResolver from the source entity (a Deal, a Payment).
 * Consumed by RuleEvaluator to decide who gets paid for which rule.
 */
final class RoleRecipient
{
    public function __construct(
        public readonly string $role,
        public readonly string $userId,
    ) {}
}
