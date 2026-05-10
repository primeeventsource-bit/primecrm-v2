<?php

declare(strict_types=1);

namespace App\Modules\Sales\Domain\Enums;

/**
 * The fulfillment-side state machine on a listing-agreement deal.
 *
 * Lives alongside DealStage (the sales-side state machine). A row can
 * be DealStage::ClosedWon AND AgreementStatus::VerifiedPendingListing
 * at the same time — same deal, two views.
 *
 *   pitched                  Agent pitched the listing fee. No commitment.
 *   verbal_yes               Owner agreed verbally; payment not collected.
 *   pending_payment          Payment authorised but not cleared.
 *   paid_pending_verification Fee cleared; awaiting verification call.
 *   verified_pending_listing Verification call done; ready for distribution.
 *   live                     Listing is live on at least one partner site.
 *   fulfilled                A renter rented the week — service delivered.
 *   cancelled                Owner withdrew before listing went live.
 *   refunded                 Fee was refunded post-purchase.
 *   charged_back             Processor reversed the charge.
 */
enum AgreementStatus: string
{
    case Pitched = 'pitched';
    case VerbalYes = 'verbal_yes';
    case PendingPayment = 'pending_payment';
    case PaidPendingVerification = 'paid_pending_verification';
    case VerifiedPendingListing = 'verified_pending_listing';
    case Live = 'live';
    case Fulfilled = 'fulfilled';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
    case ChargedBack = 'charged_back';

    public function isPostPayment(): bool
    {
        return in_array($this, [
            self::PaidPendingVerification, self::VerifiedPendingListing,
            self::Live, self::Fulfilled,
        ], true);
    }

    public function isTerminalLoss(): bool
    {
        return in_array($this, [self::Cancelled, self::Refunded, self::ChargedBack], true);
    }

    public function readyForDistribution(): bool
    {
        return $this === self::VerifiedPendingListing;
    }
}
