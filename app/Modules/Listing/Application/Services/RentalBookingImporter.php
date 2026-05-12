<?php

declare(strict_types=1);

namespace App\Modules\Listing\Application\Services;

use App\Modules\Booking\Domain\Models\Booking;
use App\Modules\Listing\Application\Services\BookingNotifier;
use App\Modules\Listing\Domain\Enums\ListingStatus;
use App\Modules\Listing\Domain\Models\Listing;
use App\Modules\Tenant\Domain\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Resolves parsed booking rows against existing listings, then
 * commits the bookings (and optionally notifies owners).
 *
 * Unlike inventory and listing imports, there's no "new entity"
 * approval step — bookings are STRICT: the listing must already
 * exist. Rows that can't find a listing are flagged as invalid in
 * the preview and skipped at commit.
 *
 * The match algorithm:
 *   1. If the row has a direct listing_id, look it up; done.
 *   2. Else, find leads (owners) by email or phone within tenant.
 *   3. Find that owner's properties matching resort+city+state.
 *   4. Find a non-terminal listing on that property whose
 *      check_in_date equals the row's listing_check_in_date.
 *
 * Any failure at any step → row.valid = false with the specific
 * reason. The preview UI surfaces this so the operator can fix the
 * source data before re-uploading.
 */
final class RentalBookingImporter
{
    public function __construct(private readonly BookingNotifier $notifier) {}

    /**
     * @param  array{rows: array<int, array<string, mixed>>, summary: array<string, mixed>}  $parsed
     * @return array{
     *   rows: array<int, array<string, mixed>>,
     *   summary: array<string, mixed>,
     * }
     */
    public function resolveAgainstTenant(string $tenantId, array $parsed): array
    {
        $rows = $parsed['rows'];
        $summary = $parsed['summary'];

        // Phase 1 — collect direct listing_id values we can batch
        // resolve.
        $directIds = [];
        foreach ($rows as $r) {
            if (! empty($r['data']['listing_id'])) {
                $directIds[] = $r['data']['listing_id'];
            }
        }
        $directIds = array_values(array_unique($directIds));

        $listingsById = [];
        if (! empty($directIds)) {
            $found = Listing::query()
                ->whereIn('id', $directIds)
                ->whereNull('deleted_at')
                ->get(['id', 'property_id', 'check_in_date', 'check_out_date',
                    'asking_price', 'our_commission_pct', 'status', 'deal_id']);
            foreach ($found as $l) {
                $listingsById[$l->id] = $l;
            }
        }

        // Phase 2 — collect (email, phone) we'll need for owner-based
        // matching, batch resolve to owner ids.
        $emails = [];
        $phones = [];
        foreach ($rows as $r) {
            if (empty($r['data']['listing_id'])) {
                if (! empty($r['data']['owner_email'])) {
                    $emails[] = strtolower($r['data']['owner_email']);
                }
                if (! empty($r['data']['owner_phone'])) {
                    $phones[] = (string) $r['data']['owner_phone'];
                }
            }
        }
        $emails = array_values(array_unique($emails));
        $phones = array_values(array_unique(array_filter($phones)));

        $ownersByEmail = [];
        $ownersByPhone = [];
        if (! empty($emails) || ! empty($phones)) {
            $leads = DB::table('leads')
                ->where('tenant_id', $tenantId)
                ->whereNull('deleted_at')
                ->where(function ($q) use ($emails, $phones): void {
                    if (! empty($emails)) {
                        $q->orWhereIn(DB::raw('LOWER(email)'), $emails);
                    }
                    if (! empty($phones)) {
                        $q->orWhereIn('phone', $phones);
                    }
                })
                ->get(['id', 'email', 'phone']);
            foreach ($leads as $l) {
                if ($l->email !== null) {
                    $ownersByEmail[strtolower($l->email)] = $l->id;
                }
                if ($l->phone !== null) {
                    $ownersByPhone[(string) $l->phone] = $l->id;
                }
            }
        }

        // Phase 3 — walk rows, match each to a listing.
        $resolvedRows = [];
        $listingCacheByOwnerPropDate = [];

        foreach ($rows as $row) {
            $matchedListingId = null;
            $matchedListing = null;
            $reason = null;

            if (! empty($row['data']['listing_id'])) {
                // Listing::query() applies TenantScoped automatically,
                // so anything in $listingsById already passed the
                // tenant check.
                $l = $listingsById[$row['data']['listing_id']] ?? null;
                if ($l !== null) {
                    $matchedListingId = $l->id;
                    $matchedListing = $l;
                } else {
                    $reason = 'listing_id not found in this workspace';
                }
            } elseif ($row['valid']) {
                // Owner lookup.
                $ownerId = null;
                if (! empty($row['data']['owner_email'])) {
                    $ownerId = $ownersByEmail[strtolower($row['data']['owner_email'])] ?? null;
                }
                if ($ownerId === null && ! empty($row['data']['owner_phone'])) {
                    $ownerId = $ownersByPhone[(string) $row['data']['owner_phone']] ?? null;
                }

                if ($ownerId === null) {
                    $reason = 'owner not found by email or phone';
                } else {
                    $cacheKey = sprintf(
                        '%s|%s|%s|%s|%s',
                        $ownerId,
                        strtolower((string) ($row['data']['resort_name'] ?? '')),
                        strtolower((string) ($row['data']['city'] ?? '')),
                        strtoupper((string) ($row['data']['state'] ?? '')),
                        (string) ($row['data']['listing_check_in_date'] ?? ''),
                    );

                    if (! array_key_exists($cacheKey, $listingCacheByOwnerPropDate)) {
                        $query = DB::table('listings as l')
                            ->join('properties as p', 'p.id', '=', 'l.property_id')
                            ->where('p.tenant_id', $tenantId)
                            ->where('p.owner_id', $ownerId)
                            ->whereNull('l.deleted_at')
                            ->where('l.check_in_date', $row['data']['listing_check_in_date'])
                            ->whereRaw('LOWER(p.resort_name) = ?', [strtolower((string) $row['data']['resort_name'])])
                            ->whereNotIn('l.status', [
                                ListingStatus::Cancelled->value,
                                ListingStatus::UnrentedExpired->value,
                            ]);

                        if (! empty($row['data']['city'])) {
                            $query->whereRaw('LOWER(p.location_city) = ?', [strtolower((string) $row['data']['city'])]);
                        }
                        if (! empty($row['data']['state'])) {
                            $query->where('p.location_state', strtoupper((string) $row['data']['state']));
                        }

                        $listingCacheByOwnerPropDate[$cacheKey] = $query
                            ->first(['l.id', 'l.property_id', 'l.check_in_date',
                                'l.check_out_date', 'l.asking_price',
                                'l.our_commission_pct', 'l.deal_id']);
                    }

                    $cached = $listingCacheByOwnerPropDate[$cacheKey];
                    if ($cached !== null) {
                        $matchedListingId = $cached->id;
                        $matchedListing = $cached;
                    } else {
                        $reason = 'no active listing matched owner + resort + check-in date';
                    }
                }
            }

            $newRow = $row;
            $newRow['matched_listing_id'] = $matchedListingId;
            // Stash the listing's window/price so the importer doesn't
            // need to re-query at commit time.
            $newRow['matched_listing_check_in'] = $matchedListing?->check_in_date ?? null;
            $newRow['matched_listing_check_out'] = $matchedListing?->check_out_date ?? null;
            $newRow['matched_listing_property_id'] = $matchedListing?->property_id ?? null;
            $newRow['matched_listing_deal_id'] = $matchedListing?->deal_id ?? null;
            $newRow['matched_listing_commission_pct'] = $matchedListing?->our_commission_pct ?? null;

            if ($matchedListingId === null && $row['valid']) {
                $newRow['valid'] = false;
                $newRow['errors'] = array_merge($row['errors'], [$reason ?? 'listing could not be matched']);
            }

            $resolvedRows[] = $newRow;
        }

        // Recompute valid count after we marked unmatched rows as
        // invalid above.
        $validCount = 0;
        foreach ($resolvedRows as $r) {
            if ($r['valid']) {
                $validCount++;
            }
        }
        $summary['total_rows'] = count($resolvedRows);
        $summary['valid_rows'] = $validCount;
        $summary['invalid_rows'] = count($resolvedRows) - $validCount;

        return [
            'rows' => $resolvedRows,
            'summary' => $summary,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *   bookings_created: int,
     *   rows_skipped: int,
     *   owners_notified: int,
     *   skip_reasons: array<int, array{row_num: int, reason: string}>,
     * }
     */
    public function commit(
        string $tenantId,
        array $payload,
        bool $notifyOwners,
        ?User $actor,
    ): array {
        $created = 0;
        $skipped = 0;
        $ownersNotified = 0;
        $skipReasons = [];

        // We collect the bookings we created so we can fire owner
        // notifications outside the transaction (and tolerate notify
        // failures without losing the booking writes).
        $createdBookingIds = [];

        DB::transaction(function () use (
            $tenantId, $payload,
            &$created, &$skipped, &$skipReasons, &$createdBookingIds,
        ): void {
            foreach ($payload['rows'] as $row) {
                if (! $row['valid'] || empty($row['matched_listing_id'])) {
                    $skipped++;
                    $skipReasons[] = [
                        'row_num' => $row['row_num'],
                        'reason' => $row['valid']
                            ? 'no listing matched'
                            : ($row['errors'][0] ?? 'row has validation errors'),
                    ];

                    continue;
                }

                // Booking dedup — same listing + renter_email +
                // check-in. Skip rather than fail; operator can fix
                // upstream if they really meant to import a dupe.
                $checkIn = $row['data']['check_in_date']
                    ?? $row['matched_listing_check_in'];
                $checkOut = $row['data']['check_out_date']
                    ?? $row['matched_listing_check_out'];

                $existing = Booking::query()
                    ->where('listing_id', $row['matched_listing_id'])
                    ->where('check_in_date', $checkIn)
                    ->when(! empty($row['data']['renter_email']), function ($q) use ($row): void {
                        $q->where('renter_email', $row['data']['renter_email']);
                    })
                    ->whereNull('deleted_at')
                    ->first();

                if ($existing !== null) {
                    $skipped++;
                    $skipReasons[] = [
                        'row_num' => $row['row_num'],
                        'reason' => 'duplicate booking (same listing + check-in + renter email)',
                    ];

                    continue;
                }

                $total = (float) $row['data']['total_price'];
                $pct = $row['data']['commission_pct']
                    ?? ($row['matched_listing_commission_pct'] !== null
                        ? (float) $row['matched_listing_commission_pct']
                        : 15.0);
                $ourCommission = round($total * ((float) $pct / 100), 2);
                $ownerPayout = round($total - $ourCommission, 2);

                // Resolve owner lead_id off the property for the
                // booking's lead_id column (and notifier downstream).
                $ownerId = DB::table('properties')
                    ->where('id', $row['matched_listing_property_id'])
                    ->value('owner_id');

                $booking = Booking::query()->create([
                    'tenant_id' => $tenantId,
                    'lead_id' => $ownerId,
                    'deal_id' => $row['matched_listing_deal_id'] ?? null,
                    'agent_id' => null, // CSV imports aren't agent-attributed
                    'listing_id' => $row['matched_listing_id'],
                    'inquiry_id' => null,
                    'renter_name' => $row['data']['renter_name'],
                    'renter_email' => $row['data']['renter_email'] ?? null,
                    'renter_phone' => $row['data']['renter_phone'] ?? null,
                    'check_in_date' => $checkIn,
                    'check_out_date' => $checkOut,
                    'total_price' => $total,
                    'paid_amount' => 0,
                    'currency' => 'USD',
                    'owner_payout' => $ownerPayout,
                    'our_commission' => $ourCommission,
                    'status' => 'confirmed',
                    'payment_status' => $row['data']['payment_status'] ?? 'pending',
                    'confirmation_number' => 'PV-'.strtoupper(Str::random(8)),
                    'confirmed_at' => CarbonImmutable::now(),
                ]);

                // Flip the listing to Booked so the listings hub
                // reflects reality. (Imports of historical bookings
                // may move the listing past where it would otherwise
                // be; that's the intended outcome.)
                Listing::query()
                    ->where('id', $row['matched_listing_id'])
                    ->update(['status' => ListingStatus::Booked->value]);

                $createdBookingIds[] = $booking->id;
                $created++;
            }
        });

        // Owner notifications fire AFTER the transaction so a notify
        // failure doesn't roll back the import. Operator can opt out
        // via notify_owners=false (sensible default when importing
        // historical bookings — the owners already know).
        if ($notifyOwners && ! empty($createdBookingIds)) {
            $bookings = Booking::query()
                ->whereIn('id', $createdBookingIds)
                ->with(['listing.property'])
                ->get();
            foreach ($bookings as $b) {
                if ($b->listing === null) {
                    continue;
                }
                $this->notifier->notifyOwnerOfBooking($b, $b->listing, $actor);
                $ownersNotified++;
            }
        }

        return [
            'bookings_created' => $created,
            'rows_skipped' => $skipped,
            'owners_notified' => $ownersNotified,
            'skip_reasons' => $skipReasons,
        ];
    }
}
