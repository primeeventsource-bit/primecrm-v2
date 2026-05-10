<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\Enums;

enum ComplianceStatus: string
{
    case PendingReview = 'pending_review';
    case Passed = 'passed';
    case Failed = 'failed';
    case FlaggedForAudit = 'flagged_for_audit';

    public function isResolved(): bool
    {
        return $this !== self::PendingReview;
    }
}
