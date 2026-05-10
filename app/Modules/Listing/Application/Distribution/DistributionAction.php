<?php

declare(strict_types=1);

namespace App\Modules\Listing\Application\Distribution;

/**
 * The four operations a partner driver must support.
 *
 * push    initial publish to the partner site (or re-publish after
 *         removed). End state on success: live.
 * pause   keep the listing on the partner site but hide it from
 *         renters. End state on success: paused.
 * resume  unpause a previously paused listing. End state on success:
 *         live.
 * sync    refresh status + counters from the partner site. The driver
 *         does not change the listing's lifecycle — it just reads
 *         current external state and updates our row to match.
 */
enum DistributionAction: string
{
    case Push = 'push';
    case Pause = 'pause';
    case Resume = 'resume';
    case Sync = 'sync';
}
