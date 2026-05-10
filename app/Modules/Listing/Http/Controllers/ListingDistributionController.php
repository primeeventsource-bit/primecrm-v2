<?php

declare(strict_types=1);

namespace App\Modules\Listing\Http\Controllers;

use App\Core\Shared\TenantContext;
use App\Modules\Listing\Application\Distribution\PartnerDistributionResult;
use App\Modules\Listing\Application\Services\ListingDistributor;
use App\Modules\Listing\Domain\Enums\PartnerSiteListingStatus;
use App\Modules\Listing\Domain\Models\Listing;
use App\Modules\Listing\Domain\Models\PartnerSite;
use App\Modules\Listing\Domain\Models\PartnerSiteListing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Partner-site distribution actions on listings.
 *
 *   POST /api/listings/{listingId}/distributions             add to site
 *   POST /api/listings/{listingId}/distributions/{rowId}/repush
 *   POST /api/listings/{listingId}/distributions/{rowId}/pause
 *   POST /api/listings/{listingId}/distributions/{rowId}/resume
 *   POST /api/listings/{listingId}/distributions/{rowId}/sync
 *
 * Each action delegates to ListingDistributor which dispatches to the
 * right driver (real or mock). The HTTP layer is just shape + auth.
 */
final class ListingDistributionController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ListingDistributor $distributor,
    ) {}

    /**
     * Add a listing to a partner site it isn't yet on.
     *
     *   POST /api/listings/{listingId}/distributions
     *   { "partner_site_id": "<uuid>" }
     */
    public function store(Request $request, string $listingId): JsonResponse
    {
        $request->validate([
            'partner_site_id' => ['required', 'uuid'],
        ]);

        [$listing, $site] = $this->resolveListingAndSite(
            $listingId,
            (string) $request->string('partner_site_id'),
        );

        if (! $listing) {
            return response()->json(['message' => 'Listing not found'], 404);
        }
        if (! $site) {
            return response()->json(['message' => 'Partner site not found'], 404);
        }
        if (! $site->is_active) {
            return response()->json(['message' => 'Partner site is inactive'], 422);
        }

        // If we've already pushed this listing to this site, no-op-bail.
        // Re-pushing an existing row is a separate endpoint.
        $existing = PartnerSiteListing::query()
            ->where('listing_id', $listing->id)
            ->where('partner_site_id', $site->id)
            ->first();

        if ($existing !== null) {
            return response()->json([
                'message' => 'Listing is already pushed to this site',
                'partner_site_listing_id' => $existing->id,
            ], 409);
        }

        // Create the placeholder row first; the distributor will push
        // it through the driver and populate external_listing_id /
        // status / went_live_at on success.
        $row = PartnerSiteListing::query()->create([
            'listing_id' => $listing->id,
            'partner_site_id' => $site->id,
            'status' => PartnerSiteListingStatus::Pending->value,
        ]);

        $result = $this->distributor->push($listing, $site, $row->refresh());

        return $this->respond($result, $row->refresh());
    }

    public function repush(string $listingId, string $rowId): JsonResponse
    {
        [$listing, $row, $site] = $this->resolveTriad($listingId, $rowId);

        if (! $listing || ! $row || ! $site) {
            return response()->json(['message' => 'Distribution row not found'], 404);
        }

        $result = $this->distributor->push($listing, $site, $row);

        return $this->respond($result, $row->refresh());
    }

    public function pause(string $listingId, string $rowId): JsonResponse
    {
        [$listing, $row, $site] = $this->resolveTriad($listingId, $rowId);

        if (! $listing || ! $row || ! $site) {
            return response()->json(['message' => 'Distribution row not found'], 404);
        }

        $result = $this->distributor->pause($listing, $site, $row);

        return $this->respond($result, $row->refresh());
    }

    public function resume(string $listingId, string $rowId): JsonResponse
    {
        [$listing, $row, $site] = $this->resolveTriad($listingId, $rowId);

        if (! $listing || ! $row || ! $site) {
            return response()->json(['message' => 'Distribution row not found'], 404);
        }

        $result = $this->distributor->resume($listing, $site, $row);

        return $this->respond($result, $row->refresh());
    }

    public function sync(string $listingId, string $rowId): JsonResponse
    {
        [$listing, $row, $site] = $this->resolveTriad($listingId, $rowId);

        if (! $listing || ! $row || ! $site) {
            return response()->json(['message' => 'Distribution row not found'], 404);
        }

        $result = $this->distributor->sync($listing, $site, $row);

        return $this->respond($result, $row->refresh());
    }

    /**
     * @return array{0: Listing|null, 1: PartnerSite|null}
     */
    private function resolveListingAndSite(string $listingId, string $siteId): array
    {
        return [
            Listing::query()->find($listingId),
            PartnerSite::query()->find($siteId),
        ];
    }

    /**
     * @return array{0: Listing|null, 1: PartnerSiteListing|null, 2: PartnerSite|null}
     */
    private function resolveTriad(string $listingId, string $rowId): array
    {
        $row = PartnerSiteListing::query()
            ->where('id', $rowId)
            ->where('listing_id', $listingId)
            ->first();

        if ($row === null) {
            return [null, null, null];
        }

        return [
            Listing::query()->find($listingId),
            $row,
            PartnerSite::query()->find($row->partner_site_id),
        ];
    }

    private function respond(PartnerDistributionResult $result, PartnerSiteListing $row): JsonResponse
    {
        if (! $result->ok) {
            return response()->json([
                'message' => $result->errorMessage ?? 'Distribution action failed',
                'partner_site_listing' => $this->reshape($row),
            ], 422);
        }

        return response()->json([
            'message' => match ($row->status) {
                PartnerSiteListingStatus::Live => 'Listing is live on this partner site.',
                PartnerSiteListingStatus::Pending => 'Push accepted; partner is reviewing.',
                PartnerSiteListingStatus::Rejected => 'Partner rejected the listing.',
                PartnerSiteListingStatus::Paused => 'Listing paused on this partner site.',
                default => 'Distribution updated.',
            },
            'partner_site_listing' => $this->reshape($row),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function reshape(PartnerSiteListing $row): array
    {
        return [
            'id' => $row->id,
            'listing_id' => $row->listing_id,
            'partner_site_id' => $row->partner_site_id,
            'status' => $row->status?->value,
            'external_listing_id' => $row->external_listing_id,
            'external_url' => $row->external_url,
            'rejection_reason' => $row->rejection_reason,
            'view_count' => (int) $row->view_count,
            'inquiry_count' => (int) $row->inquiry_count,
            'pushed_at' => $row->pushed_at?->toIso8601String(),
            'went_live_at' => $row->went_live_at?->toIso8601String(),
            'last_synced_at' => $row->last_synced_at?->toIso8601String(),
        ];
    }
}
