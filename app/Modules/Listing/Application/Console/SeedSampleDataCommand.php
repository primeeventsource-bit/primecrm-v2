<?php

declare(strict_types=1);

namespace App\Modules\Listing\Application\Console;

use App\Core\Shared\TenantContext;
use App\Modules\Booking\Domain\Models\Booking;
use App\Modules\Lead\Domain\Models\Lead;
use App\Modules\Listing\Domain\Enums\ListingStatus;
use App\Modules\Listing\Domain\Enums\PropertyOwnershipType;
use App\Modules\Listing\Domain\Enums\PropertySeason;
use App\Modules\Listing\Domain\Models\Listing;
use App\Modules\Listing\Domain\Models\Property;
use App\Modules\Sales\Domain\Models\Deal;
use App\Modules\Tenant\Domain\Models\Tenant;
use App\Modules\Tenant\Domain\Models\User;
use App\Support\Enums\DealStage;
use App\Support\Enums\LeadStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Operator-facing seeder for sample listings + bookings.
 *
 * Distinct from DemoSeeder (which builds a complete demo tenant from
 * scratch and is destructive). This command runs against an EXISTING
 * tenant + agent and ADDS a small set of sample rows that exercise
 * every listing status the UI knows about, plus one confirmed booking
 * so the bookings ledger has at least one row to render.
 *
 * Idempotency: every sample row is tagged with `metadata.seeded_by =
 * 'crm:sample-data'` (on Leads + Deals) or a recognisable resort name
 * prefix ('Sample • …') on Properties. Re-running upserts by that
 * key rather than duplicating.
 *
 * Usage:
 *   php artisan crm:sample-data
 *     → pick the first tenant, first supervisor as agent
 *   php artisan crm:sample-data --tenant=<uuid> --agent=<uuid>
 *   php artisan crm:sample-data --fresh
 *     → delete existing samples first
 */
final class SeedSampleDataCommand extends Command
{
    protected $signature = 'crm:sample-data
                            {--tenant= : Tenant UUID (defaults to the first tenant)}
                            {--agent= : Agent user UUID (defaults to the first supervisor)}
                            {--fresh : Delete existing samples before seeding}';

    protected $description = 'Seed sample listings + bookings on an existing tenant.';

    /** Marker stored on every row this command creates so re-runs upsert idempotently. */
    private const MARKER = 'crm:sample-data';

    private const RESORT_PREFIX = 'Sample • ';

    public function handle(TenantContext $tenants): int
    {
        $tenant = $this->resolveTenant();
        if ($tenant === null) {
            $this->error('No tenant found. Run the base seeder first or pass --tenant=<uuid>.');
            return self::FAILURE;
        }

        $agent = $this->resolveAgent($tenant);
        if ($agent === null) {
            $this->error('No agent user found on this tenant. Pass --agent=<uuid>.');
            return self::FAILURE;
        }

        // Tenant context must be set for any TenantScoped query to see
        // its own data. The command runs outside an HTTP request so the
        // middleware never fired.
        $tenants->set($tenant->id, $agent->id);

        $this->info("Tenant: {$tenant->name} ({$tenant->id})");
        $this->info("Agent:  {$agent->first_name} {$agent->last_name} ({$agent->id})");

        if ($this->option('fresh')) {
            $this->warn('--fresh: deleting existing sample rows first…');
            $this->deleteSamples();
        }

        DB::transaction(function () use ($tenant, $agent): void {
            // Three owner leads → three properties → three deals → three
            // listings, one per status the operator most needs to see:
            // live, pending_distribution, and booked.
            //
            // Phone numbers use the +1-555-01xx reserved-for-fiction
            // range so they cannot ring real subscribers in any North
            // American area — safe on a CRM with a real Twilio account
            // attached.
            $samples = [
                [
                    'first' => 'Sample-Marcus', 'last' => 'Patel',
                    'email' => 'marcus.sample@example.com',
                    'phone' => '+15555550101',
                    'resort' => 'Marriott Aruba Surf Club',
                    'brand' => 'Marriott',
                    'city' => 'Palm Beach', 'state' => 'AW',
                    'bedrooms' => 2, 'sleeps' => 6,
                    'ownership_type' => PropertyOwnershipType::FixedWeek,
                    'fixed_week' => 14, 'season' => PropertySeason::Platinum,
                    'asking' => 4_850.00, 'reserve' => 4_200.00,
                    'commission_pct' => 15.00,
                    'check_in_offset_days' => 60,
                    'check_out_offset_days' => 67,
                    'listing_status' => ListingStatus::Live,
                ],
                [
                    'first' => 'Sample-Anita', 'last' => 'Cole',
                    'email' => 'anita.sample@example.com',
                    'phone' => '+15555550102',
                    'resort' => 'Wyndham Bonnet Creek',
                    'brand' => 'Wyndham',
                    'city' => 'Orlando', 'state' => 'FL',
                    'bedrooms' => 1, 'sleeps' => 4,
                    'ownership_type' => PropertyOwnershipType::Points,
                    'fixed_week' => null, 'season' => PropertySeason::Gold,
                    'asking' => 2_400.00, 'reserve' => 2_100.00,
                    'commission_pct' => 18.00,
                    'check_in_offset_days' => 21,
                    'check_out_offset_days' => 28,
                    'listing_status' => ListingStatus::PendingDistribution,
                ],
                [
                    'first' => 'Sample-Priya', 'last' => 'Shah',
                    'email' => 'priya.sample@example.com',
                    'phone' => '+15555550103',
                    'resort' => 'Hyatt Maui Ka\'anapali',
                    'brand' => 'Hyatt',
                    'city' => 'Lahaina', 'state' => 'HI',
                    'bedrooms' => 3, 'sleeps' => 8,
                    'ownership_type' => PropertyOwnershipType::FloatingWeek,
                    'fixed_week' => null, 'season' => PropertySeason::Platinum,
                    'asking' => 7_900.00, 'reserve' => 7_000.00,
                    'commission_pct' => 12.00,
                    'check_in_offset_days' => 95,
                    'check_out_offset_days' => 102,
                    'listing_status' => ListingStatus::Booked,
                ],
            ];

            $created = ['leads' => 0, 'properties' => 0, 'deals' => 0, 'listings' => 0, 'bookings' => 0];

            foreach ($samples as $s) {
                $lead = $this->upsertOwnerLead($tenant->id, $s, $created);
                $property = $this->upsertProperty($tenant->id, $lead->id, $s, $created);
                $deal = $this->upsertDeal($tenant->id, $lead->id, $agent->id, $s, $created);
                $listing = $this->upsertListing($tenant->id, $property->id, $deal->id, $s, $created);

                // One sample booking, on the listing flagged as Booked.
                if ($s['listing_status'] === ListingStatus::Booked) {
                    $this->upsertBooking($tenant->id, $lead->id, $agent->id, $listing, $s, $created);
                }
            }

            $this->newLine();
            $this->info('Sample data created (or refreshed):');
            $this->table(
                ['Type', 'Count'],
                collect($created)->map(fn ($v, $k) => [$k, $v])->values()->toArray(),
            );
        });

        return self::SUCCESS;
    }

    private function resolveTenant(): ?Tenant
    {
        $explicit = $this->option('tenant');
        if (is_string($explicit) && $explicit !== '') {
            return Tenant::query()->find($explicit);
        }

        // First tenant wins. Multi-tenant setups should pass --tenant
        // explicitly to avoid surprises.
        return Tenant::query()->orderBy('created_at')->first();
    }

    private function resolveAgent(Tenant $tenant): ?User
    {
        $explicit = $this->option('agent');
        if (is_string($explicit) && $explicit !== '') {
            return User::query()->withoutGlobalScopes()->find($explicit);
        }

        // Prefer a supervisor for attribution — they're the most likely
        // to be on screen and we want the sample deals to look like real
        // closer work.
        return User::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->orderByRaw("CASE WHEN role IN ('master_admin','admin','supervisor','manager') THEN 0 ELSE 1 END")
            ->orderBy('created_at')
            ->first();
    }

    private function upsertOwnerLead(string $tenantId, array $s, array &$tally): Lead
    {
        $existing = Lead::query()
            ->where('email', $s['email'])
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $lead = new Lead();
        $lead->tenant_id = $tenantId;
        $lead->first_name = $s['first'];
        $lead->last_name = $s['last'];
        $lead->email = $s['email'];
        // leads.phone + leads.phone_hash are both NOT NULL on the
        // production MySQL schema (no defaults). The hash is
        // sha256(E.164) — see Core\Shared\Services\PhoneNormalizer.
        // Inlined here to avoid a service dependency in a one-off
        // command. Numbers use the +1-555-01xx reserved-for-fiction
        // range so they can never ring a real subscriber.
        $lead->phone = $s['phone'];
        $lead->phone_hash = hash('sha256', $s['phone']);
        // Sample owners represent leads that converted to a listing-fee
        // deal — ClosedWon is the matching status. No "Customer" enum
        // value exists; that domain concept lives on the Customer model.
        $lead->status = LeadStatus::ClosedWon->value;
        $lead->source = 'sample_data';
        $lead->source_metadata = ['seeded_by' => self::MARKER];
        $lead->has_express_consent = true;
        $lead->consent_at = Carbon::now()->subDays(30);
        $lead->save();

        $tally['leads']++;
        return $lead;
    }

    private function upsertProperty(string $tenantId, string $ownerId, array $s, array &$tally): Property
    {
        $resortName = self::RESORT_PREFIX.$s['resort'];

        $existing = Property::query()
            ->where('owner_id', $ownerId)
            ->where('resort_name', $resortName)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $property = new Property();
        $property->tenant_id = $tenantId;
        $property->owner_id = $ownerId;
        $property->resort_name = $resortName;
        $property->resort_brand = $s['brand'];
        $property->location_city = $s['city'];
        $property->location_state = $s['state'];
        $property->location_country = 'USA';
        $property->bedrooms = $s['bedrooms'];
        $property->sleeps = $s['sleeps'];
        $property->ownership_type = $s['ownership_type']->value;
        $property->fixed_week_number = $s['fixed_week'];
        $property->season = $s['season']->value;
        $property->ownership_verified = true;
        $property->ownership_verified_at = Carbon::now()->subDays(20);
        $property->rental_allowed_by_resort = true;
        $property->save();

        $tally['properties']++;
        return $property;
    }

    private function upsertDeal(string $tenantId, string $leadId, string $agentId, array $s, array &$tally): Deal
    {
        // Identify the sample deal by lead + a known notes prefix so a
        // genuine deal for this lead (if one ever exists alongside) is
        // never overwritten.
        $existing = Deal::query()
            ->where('lead_id', $leadId)
            ->where('notes', 'like', '%[crm:sample-data]%')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $listingFee = round($s['asking'] * 0.08, 2);

        $deal = new Deal();
        $deal->tenant_id = $tenantId;
        $deal->lead_id = $leadId;
        $deal->agent_id = $agentId;
        $deal->stage = DealStage::ClosedWon->value;
        $deal->stage_changed_at = Carbon::now()->subDays(15);
        $deal->total_value = $listingFee;
        $deal->payable_amount = $listingFee;
        $deal->currency = 'USD';
        $deal->closed_at = Carbon::now()->subDays(15);
        $deal->notes = "[crm:sample-data] Sample listing-fee agreement for {$s['resort']}.";
        $deal->listing_fee = $listingFee;
        // listing_fee_collected is the AMOUNT collected (decimal:2),
        // not a boolean flag. Full payment for samples.
        $deal->listing_fee_collected = $listingFee;
        $deal->payment_status = 'paid_in_full';
        // AgreementStatus 'Live' = listing-fee paid + verification done
        // + listing actively distributed. Closest to the sample state
        // we're modeling (a real listing that's gone live).
        $deal->agreement_status = 'live';
        $deal->listing_term_months = 12;
        $deal->term_expires_at = Carbon::now()->addMonths(12);
        $deal->tcpa_disclosure_completed = true;
        $deal->tcpa_disclosure_completed_at = Carbon::now()->subDays(15);
        $deal->verification_call_completed = true;
        $deal->verification_call_completed_at = Carbon::now()->subDays(14);
        $deal->agreement_signed_at = Carbon::now()->subDays(15);
        $deal->save();

        $tally['deals']++;
        return $deal;
    }

    private function upsertListing(
        string $tenantId,
        string $propertyId,
        string $dealId,
        array $s,
        array &$tally,
    ): Listing {
        $existing = Listing::query()
            ->where('property_id', $propertyId)
            ->where('deal_id', $dealId)
            ->first();

        $checkIn = Carbon::now()->addDays($s['check_in_offset_days']);
        $checkOut = Carbon::now()->addDays($s['check_out_offset_days']);
        $ownerPayout = round($s['asking'] * (1 - $s['commission_pct'] / 100), 2);

        if ($existing !== null) {
            $existing->fill([
                'status' => $s['listing_status']->value,
                'check_in_date' => $checkIn,
                'check_out_date' => $checkOut,
                'asking_price' => $s['asking'],
                'reserve_price' => $s['reserve'],
                'owner_payout' => $ownerPayout,
                'our_commission_pct' => $s['commission_pct'],
            ])->save();
            return $existing;
        }

        $listing = new Listing();
        $listing->tenant_id = $tenantId;
        $listing->property_id = $propertyId;
        $listing->deal_id = $dealId;
        $listing->check_in_date = $checkIn;
        $listing->check_out_date = $checkOut;
        $listing->asking_price = $s['asking'];
        $listing->reserve_price = $s['reserve'];
        $listing->owner_payout = $ownerPayout;
        $listing->our_commission_pct = $s['commission_pct'];
        $listing->status = $s['listing_status']->value;
        $listing->marketing_description = 'Spacious '.$s['bedrooms'].'BR at '.$s['resort']
            .' in '.$s['city'].'. Sleeps '.$s['sleeps'].'. Generated by crm:sample-data.';
        $listing->went_live_at = $s['listing_status'] !== ListingStatus::PendingDistribution
            ? Carbon::now()->subDays(10)
            : null;
        $listing->expires_at = Carbon::now()->addMonths(12);
        $listing->save();

        $tally['listings']++;
        return $listing;
    }

    private function upsertBooking(
        string $tenantId,
        string $ownerLeadId,
        string $agentId,
        Listing $listing,
        array $s,
        array &$tally,
    ): Booking {
        $existing = Booking::query()
            ->where('listing_id', $listing->id)
            ->where('confirmation_number', 'like', 'SAMPLE-%')
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $total = (float) $s['asking'];
        $ownerPayout = round($total * (1 - $s['commission_pct'] / 100), 2);
        $commission = round($total - $ownerPayout, 2);

        $booking = Booking::query()->create([
            'tenant_id' => $tenantId,
            'lead_id' => $ownerLeadId,
            'deal_id' => $listing->deal_id,
            'agent_id' => $agentId,
            'listing_id' => $listing->id,
            'renter_name' => 'Sample Renter',
            'renter_email' => 'renter.sample@example.com',
            'renter_phone' => '+15555550100',
            'check_in_date' => $listing->check_in_date,
            'check_out_date' => $listing->check_out_date,
            'total_price' => $total,
            'paid_amount' => $total,
            'currency' => 'USD',
            'owner_payout' => $ownerPayout,
            'our_commission' => $commission,
            'status' => Booking::STATUS_PAID,
            'payment_status' => 'paid_in_full',
            'confirmation_number' => 'SAMPLE-'.strtoupper(Str::random(6)),
            'confirmed_at' => Carbon::now()->subDays(5),
            'owner_notified_at' => Carbon::now()->subDays(5),
        ]);

        $tally['bookings']++;
        return $booking;
    }

    /**
     * Drop every row this command has previously created. Identifies
     * samples by their marker — the same marker re-used by the upserts
     * above so the two halves stay in lockstep.
     */
    private function deleteSamples(): void
    {
        // Walk the graph backwards: bookings → listings → deals →
        // properties → leads. Each lower layer cascades when its parent
        // goes, but we want explicit deletion in case FKs are non-cascade.
        Booking::query()
            ->withoutGlobalScopes()
            ->where('confirmation_number', 'like', 'SAMPLE-%')
            ->delete();

        $sampleResortNames = Property::query()
            ->withoutGlobalScopes()
            ->where('resort_name', 'like', self::RESORT_PREFIX.'%')
            ->pluck('id');
        if ($sampleResortNames->isNotEmpty()) {
            Listing::query()
                ->withoutGlobalScopes()
                ->whereIn('property_id', $sampleResortNames)
                ->forceDelete();
            Property::query()
                ->withoutGlobalScopes()
                ->whereIn('id', $sampleResortNames)
                ->forceDelete();
        }

        Deal::query()
            ->withoutGlobalScopes()
            ->where('notes', 'like', '%[crm:sample-data]%')
            ->delete();

        Lead::query()
            ->withoutGlobalScopes()
            ->where('source', 'sample_data')
            ->delete();
    }
}
