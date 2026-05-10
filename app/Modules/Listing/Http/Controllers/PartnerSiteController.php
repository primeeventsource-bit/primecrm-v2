<?php

declare(strict_types=1);

namespace App\Modules\Listing\Http\Controllers;

use App\Core\Shared\TenantContext;
use App\Modules\Listing\Domain\Models\PartnerSite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * Partner-site config endpoints.
 *
 *   GET   /api/partner-sites              list with summary stats
 *   GET   /api/partner-sites/{id}         single site + 30d performance
 *   PATCH /api/partner-sites/{id}         update name / cost / active
 *
 * Tenant-scoped via the PartnerSite model's TenantScoped trait.
 *
 * Performance series is derived from partner_site_listings — view +
 * inquiry counters bucketed by partner_site over the last 30 days.
 * For now we return a single rollup; per-day bucketing waits on a
 * dedicated audit table (D9 hardening sprint).
 */
final class PartnerSiteController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->id();

        // Summary stats per site — push counts, sites_live, total
        // views/inquiries — used to render the cards on the index.
        $stats = DB::table('partner_site_listings')
            ->where('tenant_id', $tenantId)
            ->groupBy('partner_site_id')
            ->selectRaw('
                partner_site_id,
                COUNT(*) AS pushes_total,
                SUM(CASE WHEN status = \'live\' THEN 1 ELSE 0 END) AS pushes_live,
                SUM(CASE WHEN status = \'pending\' THEN 1 ELSE 0 END) AS pushes_pending,
                SUM(CASE WHEN status = \'rejected\' THEN 1 ELSE 0 END) AS pushes_rejected,
                SUM(CASE WHEN status = \'paused\' THEN 1 ELSE 0 END) AS pushes_paused,
                COALESCE(SUM(view_count), 0) AS total_views,
                COALESCE(SUM(inquiry_count), 0) AS total_inquiries
            ')
            ->get()
            ->keyBy('partner_site_id');

        // Pull the sites themselves through the Eloquent model so the
        // encrypted-cast on `config` is decrypted before we hide it.
        $sites = PartnerSite::query()
            ->orderBy('name')
            ->get();

        $data = $sites->map(function (PartnerSite $s) use ($stats) {
            $row = $stats->get($s->id);

            return [
                'id' => $s->id,
                'name' => $s->name,
                'slug' => $s->slug,
                'is_active' => $s->is_active,
                'api_endpoint' => $s->api_endpoint,
                'our_cost_per_listing' => $s->our_cost_per_listing !== null
                    ? (float) $s->our_cost_per_listing : null,
                // Don't return raw config; just whether we have any.
                'has_config' => ! empty($s->config),
                'has_real_driver' => $this->hasRealDriver($s->slug),
                'created_at' => $s->created_at?->toIso8601String(),
                'stats' => $row ? [
                    'pushes_total' => (int) $row->pushes_total,
                    'pushes_live' => (int) $row->pushes_live,
                    'pushes_pending' => (int) $row->pushes_pending,
                    'pushes_rejected' => (int) $row->pushes_rejected,
                    'pushes_paused' => (int) $row->pushes_paused,
                    'total_views' => (int) $row->total_views,
                    'total_inquiries' => (int) $row->total_inquiries,
                ] : [
                    'pushes_total' => 0, 'pushes_live' => 0,
                    'pushes_pending' => 0, 'pushes_rejected' => 0,
                    'pushes_paused' => 0, 'total_views' => 0, 'total_inquiries' => 0,
                ],
            ];
        });

        return response()->json(['data' => $data->values()]);
    }

    public function show(string $id): JsonResponse
    {
        $site = PartnerSite::query()->find($id);

        if ($site === null) {
            return response()->json(['message' => 'Partner site not found'], 404);
        }

        $tenantId = $this->tenantContext->id();

        // Recent push activity — last 50 partner_site_listings rows
        // for this site, with listing + owner name joined so the
        // detail page can render an activity table.
        $recent = DB::table('partner_site_listings as psl')
            ->join('listings as l', 'l.id', '=', 'psl.listing_id')
            ->join('properties as p', 'p.id', '=', 'l.property_id')
            ->join('leads as o', 'o.id', '=', 'p.owner_id')
            ->where('psl.tenant_id', $tenantId)
            ->where('psl.partner_site_id', $id)
            ->orderByDesc('psl.created_at')
            ->limit(50)
            ->get([
                'psl.id', 'psl.listing_id', 'psl.status',
                'psl.view_count', 'psl.inquiry_count',
                'psl.pushed_at', 'psl.went_live_at',
                'l.check_in_date',
                'p.resort_name',
                'o.first_name', 'o.last_name',
            ]);

        return response()->json([
            'id' => $site->id,
            'name' => $site->name,
            'slug' => $site->slug,
            'is_active' => $site->is_active,
            'api_endpoint' => $site->api_endpoint,
            'our_cost_per_listing' => $site->our_cost_per_listing !== null
                ? (float) $site->our_cost_per_listing : null,
            'has_config' => ! empty($site->config),
            'has_real_driver' => $this->hasRealDriver($site->slug),
            'recent_pushes' => $recent->map(fn ($r) => [
                'id' => $r->id,
                'listing_id' => $r->listing_id,
                'resort_name' => $r->resort_name,
                'owner_name' => trim(($r->first_name ?? '').' '.($r->last_name ?? ''))
                    ?: '(unnamed owner)',
                'check_in_date' => $r->check_in_date,
                'status' => $r->status,
                'view_count' => (int) $r->view_count,
                'inquiry_count' => (int) $r->inquiry_count,
                'pushed_at' => $r->pushed_at,
                'went_live_at' => $r->went_live_at,
            ]),
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'name' => ['nullable', 'string', 'max:200'],
            'is_active' => ['nullable', 'boolean'],
            'our_cost_per_listing' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'api_endpoint' => ['nullable', 'string', 'max:500'],
        ]);

        $site = PartnerSite::query()->find($id);

        if ($site === null) {
            return response()->json(['message' => 'Partner site not found'], 404);
        }

        $site->fill($request->only([
            'name', 'is_active', 'our_cost_per_listing', 'api_endpoint',
        ]))->save();

        return response()->json([
            'data' => [
                'id' => $site->id,
                'name' => $site->name,
                'slug' => $site->slug,
                'is_active' => $site->is_active,
                'api_endpoint' => $site->api_endpoint,
                'our_cost_per_listing' => $site->our_cost_per_listing !== null
                    ? (float) $site->our_cost_per_listing : null,
            ],
        ]);
    }

    /**
     * Whether the partner has a real (non-mock) integration registered.
     * Mirrors the slug map in ListingDistributor — kept in sync at the
     * coding level rather than a runtime introspection (we'd prefer to
     * surface "real driver" in the UI explicitly).
     */
    private function hasRealDriver(string $slug): bool
    {
        // Slugs that ListingDistributor::DRIVER_MAP knows about.
        return in_array($slug, ['redweek'], true);
    }
}
