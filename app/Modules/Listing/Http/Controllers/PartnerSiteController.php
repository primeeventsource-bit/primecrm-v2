<?php

declare(strict_types=1);

namespace App\Modules\Listing\Http\Controllers;

use App\Core\Shared\TenantContext;
use App\Modules\Listing\Domain\Models\PartnerSite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Partner-site config endpoints.
 *
 *   GET    /api/partner-sites                       list with summary stats
 *   POST   /api/partner-sites                       create a new site (supervisor)
 *   GET    /api/partner-sites/{id}                  single site + recent activity
 *   PATCH  /api/partner-sites/{id}                  update name / cost / active
 *   DELETE /api/partner-sites/{id}                  archive (soft delete; supervisor)
 *   POST   /api/partner-sites/{id}/rotate-secret    mint a new webhook secret
 *
 * Tenant-scoped via the PartnerSite model's TenantScoped trait.
 *
 * The webhook secret is included in the response ONCE — on create and
 * on rotate. It is otherwise omitted (the model's $hidden array drops
 * it). Operators are expected to copy it into the partner's integration
 * config on first surface; if they lose it, rotate.
 */
final class PartnerSiteController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * Only supervisors can mint/destroy partner sites — credentials live
     * here and partner relationships have billing implications.
     */
    private function assertSupervisor(Request $request): void
    {
        if (! $request->user()?->role?->canSupervise()) {
            abort(403, 'Only supervisors can configure partner sites.');
        }
    }

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
                'has_webhook_secret' => $s->webhook_secret !== null && $s->webhook_secret !== '',
                'webhook_inquiry_url' => url('/api/partner-webhooks/'.$s->slug.'/inquiries'),
                'webhook_last_received_at' => $s->webhook_last_received_at?->toIso8601String(),
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
            'has_webhook_secret' => $site->webhook_secret !== null && $site->webhook_secret !== '',
            'webhook_inquiry_url' => url('/api/partner-webhooks/'.$site->slug.'/inquiries'),
            'webhook_last_received_at' => $site->webhook_last_received_at?->toIso8601String(),
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
     * Create a partner site. The slug is the stable handle the
     * ListingDistributor uses to look up a driver — letters / numbers /
     * dashes only, unique per tenant. We auto-derive it from the name
     * if the caller doesn't supply one, but expose the field so
     * operators can pick a slug that matches a future real driver
     * (e.g., create the site with slug='airbnb' today so when the
     * AirbnbDriver lands tomorrow it auto-binds).
     *
     * A webhook signing secret is generated at create time and returned
     * exactly once. It's the only response that ever shows the secret
     * in plaintext — the index/show responses scrub it.
     */
    public function store(Request $request): JsonResponse
    {
        $this->assertSupervisor($request);

        $tenantId = $this->tenantContext->id();
        if ($tenantId === null) {
            return response()->json(['message' => 'Tenant context missing.'], 400);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'slug' => [
                'nullable', 'string', 'max:50',
                'regex:/^[a-z0-9][a-z0-9-]*[a-z0-9]$/',
                Rule::unique('partner_sites', 'slug')->where('tenant_id', $tenantId)->whereNull('deleted_at'),
            ],
            'api_endpoint' => ['nullable', 'string', 'max:500', 'url'],
            'our_cost_per_listing' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        // Auto-derive slug from name when omitted. Re-validate
        // uniqueness because the auto-derived value might collide.
        $slug = $validated['slug'] ?? $this->slugify($validated['name']);
        if ($slug === '') {
            throw ValidationException::withMessages([
                'name' => ['Name must contain at least one letter or number to derive a slug.'],
            ]);
        }
        $exists = PartnerSite::query()
            ->where('slug', $slug)
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages([
                'slug' => ['A partner site with this slug already exists in this tenant.'],
            ]);
        }

        $site = new PartnerSite();
        $site->tenant_id = $tenantId;
        $site->name = $validated['name'];
        $site->slug = $slug;
        $site->api_endpoint = $validated['api_endpoint'] ?? null;
        $site->our_cost_per_listing = $validated['our_cost_per_listing'] ?? null;
        $site->is_active = $request->boolean('is_active', true);
        $site->save();

        // Mint the webhook secret AFTER save so we have an id for the
        // URL we hand back to the operator. rotateWebhookSecret persists
        // for us.
        $secret = $site->rotateWebhookSecret();

        return response()->json([
            'data' => [
                'id' => $site->id,
                'name' => $site->name,
                'slug' => $site->slug,
                'is_active' => $site->is_active,
                'api_endpoint' => $site->api_endpoint,
                'our_cost_per_listing' => $site->our_cost_per_listing !== null
                    ? (float) $site->our_cost_per_listing : null,
                'has_config' => false,
                'has_real_driver' => $this->hasRealDriver($site->slug),
                'created_at' => $site->created_at?->toIso8601String(),
                'stats' => [
                    'pushes_total' => 0, 'pushes_live' => 0,
                    'pushes_pending' => 0, 'pushes_rejected' => 0,
                    'pushes_paused' => 0, 'total_views' => 0, 'total_inquiries' => 0,
                ],
            ],
            // Plaintext secret — shown ONCE, never again. The frontend
            // must surface this prominently and prompt the operator to
            // copy it before navigating away.
            'webhook' => [
                'secret' => $secret,
                'inquiry_url' => url('/api/partner-webhooks/'.$site->slug.'/inquiries'),
            ],
        ], 201);
    }

    /**
     * Archive a partner site. Soft-deletes the row (the SoftDeletes
     * trait on the model). The unique (tenant_id, slug) index excludes
     * trashed rows so the operator can re-create with the same slug if
     * they later change their mind.
     *
     * Existing partner_site_listings + rental_inquiries rows are kept
     * intact — they're history we want to preserve for reporting even
     * if the operator stops working with that partner.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->assertSupervisor($request);

        $site = PartnerSite::query()->find($id);
        if ($site === null) {
            return response()->json(['message' => 'Partner site not found'], 404);
        }

        $site->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Mint a fresh webhook secret. Used when the previous one leaked
     * (or when the operator just wants to rotate for hygiene). The
     * previous secret is overwritten in place — any in-flight webhooks
     * still using the old one will start failing HMAC verification
     * immediately, which is the desired behavior for a leak response.
     */
    public function rotateSecret(Request $request, string $id): JsonResponse
    {
        $this->assertSupervisor($request);

        $site = PartnerSite::query()->find($id);
        if ($site === null) {
            return response()->json(['message' => 'Partner site not found'], 404);
        }

        $secret = $site->rotateWebhookSecret();

        return response()->json([
            'webhook' => [
                'secret' => $secret,
                'inquiry_url' => url('/api/partner-webhooks/'.$site->slug.'/inquiries'),
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

    /**
     * "Big Bend Adventures, LLC!" → "big-bend-adventures-llc".
     * Lower-case, alphanumeric + dashes, no leading/trailing dash.
     */
    private function slugify(string $name): string
    {
        $slug = strtolower($name);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        return trim($slug, '-');
    }
}
