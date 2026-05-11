<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Shared\TenantContext;
use App\Modules\Booking\Domain\Models\Booking;
use App\Modules\Booking\Domain\Models\InventoryAvailability;
use App\Modules\Booking\Domain\Models\InventoryHold;
use App\Modules\Booking\Domain\Models\Resort;
use App\Modules\CallCenter\Domain\Models\AgentStatusRecord;
use App\Modules\CallCenter\Domain\Models\Call;
use App\Modules\CallCenter\Domain\Models\CallEvent;
use App\Modules\CallCenter\Domain\Models\Campaign;
use App\Modules\CallCenter\Domain\Models\WebhookEvent;
use App\Modules\Commission\Domain\Models\CommissionAssignment;
use App\Modules\Commission\Domain\Models\CommissionCalculation;
use App\Modules\Commission\Domain\Models\CommissionEvent;
use App\Modules\Commission\Domain\Models\CommissionPayout;
use App\Modules\Commission\Domain\Models\CommissionPlan;
use App\Modules\Commission\Domain\Models\CommissionPlanRule;
use App\Modules\Compliance\Domain\Models\ConsentRecord;
use App\Modules\Compliance\Domain\Models\ContactAttempt;
use App\Modules\Compliance\Domain\Enums\ChargebackCaseStatus;
use App\Modules\Compliance\Domain\Enums\ComplianceStatus;
use App\Modules\Compliance\Domain\Enums\RefundCaseStatus;
use App\Modules\Compliance\Domain\Enums\RefundReason;
use App\Modules\Compliance\Domain\Models\ChargebackCase;
use App\Modules\Compliance\Domain\Models\ComplianceRecording;
use App\Modules\Compliance\Domain\Models\DncEntry;
use App\Modules\Compliance\Domain\Models\RefundCase;
use App\Modules\Customer\Domain\Models\Customer;
use App\Modules\Dialer\Domain\Models\DialSession;
use App\Modules\Lead\Domain\Models\Lead;
use App\Modules\Listing\Domain\Enums\ListingStatus;
use App\Modules\Listing\Domain\Enums\PartnerSiteListingStatus;
use App\Modules\Listing\Domain\Enums\PropertyOwnershipType;
use App\Modules\Listing\Domain\Enums\PropertySeason;
use App\Modules\Listing\Domain\Enums\RentalInquiryStatus;
use App\Modules\Listing\Domain\Models\Listing;
use App\Modules\Listing\Domain\Models\PartnerSite;
use App\Modules\Listing\Domain\Models\PartnerSiteListing;
use App\Modules\Listing\Domain\Models\Property;
use App\Modules\Listing\Domain\Models\RentalInquiry;
use App\Modules\Payment\Domain\Models\Payment;
use App\Modules\Sales\Domain\Enums\AgreementStatus;
use App\Modules\Sales\Domain\Models\Deal;
use App\Modules\Sales\Domain\Models\DealStageTransition;
use App\Modules\Tenant\Domain\Models\Tenant;
use App\Modules\Tenant\Domain\Models\User;
use App\Support\Enums\AgentStatus;
use App\Support\Enums\CallDirection;
use App\Support\Enums\CallDisposition;
use App\Support\Enums\CallStatus;
use App\Support\Enums\DealStage;
use App\Support\Enums\DialerMode;
use App\Support\Enums\LeadPriority;
use App\Support\Enums\LeadStatus;
use App\Support\Enums\UserRole;
use Database\Factories\InventoryAvailabilityFactory;
use Database\Factories\InventoryUnitFactory;
use Database\Factories\LeadFactory;
use Database\Factories\ResortFactory;
use Database\Factories\TenantFactory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Lifecycle-consistent demo data covering every screen in the app.
 *
 * The data is shaped so that screens read coherently against each other:
 *   - Closer "Sofia Cruz" appears as the agent on her own deals,
 *     bookings, payments, calls, and commission rows.
 *   - Lead phone area codes are distributed across the four resort
 *     regions (FL, UT, HI, NV), so consent + DNC + calling-window logic
 *     all behave realistically.
 *   - Every cleared payment has a matching `payment.cleared` commission
 *     event and per-role calculations; the Payouts screen reflects them.
 *   - Five calls are in_progress and five dial sessions are active so
 *     the supervisor War Room shows real-time state.
 *
 * Run on a fresh database:
 *   php artisan migrate:fresh
 *   php artisan db:seed --class=DemoSeeder
 *
 * Login:
 *   admin@demo.test       / password    (full access)
 *   supervisor@demo.test  / password    (war room, dnc)
 *   sofia@demo.test       / password    (top closer — has deals/payments)
 *   marcus@demo.test      / password    (closer)
 *   jamie@demo.test       / password    (closer)
 *   devon@demo.test       / password    (fronter)
 *   alex@demo.test        / password    (fronter)
 *   qa@demo.test          / password    (qa role)
 */
final class DemoSeeder extends Seeder
{
    /** @var array<int, string> Florida area codes — matches Wyndham Orlando */
    private const AREA_FL = ['305', '786', '407', '561', '954'];

    /** @var array<int, string> Utah area codes — matches Westgate Park City */
    private const AREA_UT = ['435', '801', '385'];

    /** @var array<int, string> Hawaii — matches DVC Aulani */
    private const AREA_HI = ['808'];

    /** @var array<int, string> Nevada — matches Hilton Las Vegas */
    private const AREA_NV = ['702', '725'];

    public function run(): void
    {
        // Federal calling window first — without it the dialer rejects everything.
        $this->call(BaseCallingWindowsSeeder::class);

        $tenant = $this->createTenant();
        app(TenantContext::class)->set($tenant->id);

        $users = $this->createUsers($tenant);
        $resorts = $this->createResorts();
        [$units, $availability] = $this->createInventory($resorts);
        $campaigns = $this->createCampaigns($users['admin']);

        $leads = $this->createLeads($tenant);
        $this->createConsentRecords($leads);
        $this->createDncEntries($tenant, $leads);

        $customers = $this->createCustomersFromWonLeads($leads, $users);

        $dialSessions = $this->createDialSessions($users, $campaigns);
        $calls = $this->createCalls($leads, $users, $dialSessions, $campaigns);
        $this->createCallEvents($calls);
        $this->createContactAttempts($calls);
        $this->createAgentStatuses($users, $dialSessions, $calls);

        $deals = $this->createDeals($leads, $users, $resorts);
        $this->createDealStageTransitions($deals, $users);
        $this->createInventoryHolds($deals, $availability, $users);
        $bookings = $this->createBookings($deals, $availability, $users);
        $payments = $this->createPayments($bookings, $users);
        $this->createWebhookEvents($payments, $calls);

        $plan = $this->createCommissionPlan($tenant);
        $this->createCommissionEventsAndCalculations($payments, $deals, $plan, $users);
        $this->createCommissionPayouts($users, $tenant);

        // Timeshare-listing domain layered on top: every closed_won deal
        // gets a property + a live listing distributed to several
        // partner sites; recent calls get compliance recordings; a
        // handful of refund / chargeback cases populate the compliance
        // queue. Augments the existing deals + bookings rows with
        // listing-agreement fields rather than introducing parallel
        // tables.
        $listingStats = $this->createTimeshareDomain($leads, $deals, $bookings, $calls, $users);

        // The last touch: scatter a handful of transitions and payments
        // into the past hour so the dashboard's sparkline, activity
        // ticker, and "deals today" counters render with real numbers
        // instead of voiced empty states. Without this, the seeded
        // narrative dates everything 1+ days back and the live panels
        // sit dark on a freshly-seeded tenant.
        $this->scatterLiveActivity($deals, $payments, $users);

        $this->command->info('--- Demo data seeded ---');
        $this->command->info("Tenant:          {$tenant->name}");
        $this->command->info("Leads:           {$leads->count()}");
        $this->command->info("Calls:           {$calls->count()} (5 in-progress for War Room)");
        $this->command->info("Deals:           {$deals->count()}");
        $this->command->info("Bookings:        {$bookings->count()}");
        $this->command->info("Payments:        {$payments->count()}");
        $this->command->info("Properties:      {$listingStats['properties']}");
        $this->command->info("Listings:        {$listingStats['listings']} ({$listingStats['live_listings']} live)");
        $this->command->info("Partner sites:   {$listingStats['partner_sites']} ({$listingStats['partner_pushes']} site pushes)");
        $this->command->info("Inquiries:       {$listingStats['inquiries']}");
        $this->command->info("Compliance:      {$listingStats['compliance_recordings']} recordings, {$listingStats['compliance_failed']} failed");
        $this->command->info("Cases:           {$listingStats['refund_cases']} refund / {$listingStats['chargeback_cases']} chargeback");
        $this->command->info('Sign in:         admin@demo.test / password');
    }

    private function createTenant(): Tenant
    {
        // Idempotent: nuke ANY prior demo tenant — by slug AND by the
        // tenant of any prior admin@demo.test user — so multiple parallel
        // seed runs converge on the same fresh state. Without the email
        // path, an earlier seed using a slightly different slug would
        // leave its tenant intact and login would match THAT one,
        // leaving the new tenant orphaned.
        //
        // The cascadeOnDelete() on every tenant_id FK clears all
        // downstream rows in one shot. Production tenants are filtered
        // out because we only delete tenants whose admin email is
        // the demo address.
        $slug = 'prime-vacations-demo';
        $demoEmails = ['admin@demo.test', 'supervisor@demo.test', 'sofia@demo.test'];

        $priorTenantIds = User::withoutTenantScope()
            ->whereIn('email', $demoEmails)
            ->pluck('tenant_id')
            ->unique()
            ->all();

        $tenantIdsToDelete = array_unique(array_merge(
            $priorTenantIds,
            Tenant::query()->withTrashed()->where('slug', $slug)->pluck('id')->all(),
        ));

        if (! empty($tenantIdsToDelete)) {
            Tenant::query()->withTrashed()->whereIn('id', $tenantIdsToDelete)->forceDelete();
        }

        return TenantFactory::new()->create([
            'name' => 'Prime Vacations Demo',
            'slug' => $slug,
            'status' => 'active',
            'timezone' => 'America/New_York',
        ]);
    }

    /**
     * @return array{admin: User, supervisor: User, qa: User, closers: list<User>, fronters: list<User>}
     */
    private function createUsers(Tenant $tenant): array
    {
        $hashed = Hash::make('password');

        $base = [
            'tenant_id' => $tenant->id,
            'password' => $hashed,
            'timezone' => 'America/New_York',
            'skills' => ['english'],
            'is_panama_based' => false,
        ];

        $admin = User::query()->create($base + [
            'first_name' => 'Robert', 'last_name' => 'Hayes',
            'email' => 'admin@demo.test',
            'role' => UserRole::Admin->value,
        ]);

        $supervisor = User::query()->create($base + [
            'first_name' => 'Priya', 'last_name' => 'Anand',
            'email' => 'supervisor@demo.test',
            'role' => UserRole::Supervisor->value,
        ]);

        $qa = User::query()->create($base + [
            'first_name' => 'Lin', 'last_name' => 'Wei',
            'email' => 'qa@demo.test',
            'role' => UserRole::QA->value,
        ]);

        // Closers (sales reps who close deals + take payments)
        $closerData = [
            ['Sofia',  'Cruz',   'sofia@demo.test'],
            ['Marcus', 'Webb',   'marcus@demo.test'],
            ['Jamie',  'Rivera', 'jamie@demo.test'],
        ];
        $closers = [];
        foreach ($closerData as [$first, $last, $email]) {
            $closers[] = User::query()->create($base + [
                'first_name' => $first, 'last_name' => $last,
                'email' => $email,
                'role' => UserRole::Closer->value,
            ]);
        }

        // Fronters (qualify leads, hand off to closers)
        $fronterData = [
            ['Devon', 'Park', 'devon@demo.test'],
            ['Alex',  'Chen', 'alex@demo.test'],
        ];
        $fronters = [];
        foreach ($fronterData as [$first, $last, $email]) {
            $fronters[] = User::query()->create($base + [
                'first_name' => $first, 'last_name' => $last,
                'email' => $email,
                'role' => UserRole::Fronter->value,
            ]);
        }

        return compact('admin', 'supervisor', 'qa', 'closers', 'fronters');
    }

    /**
     * @return list<Resort>
     */
    private function createResorts(): array
    {
        $brands = [
            ['name' => 'Westgate Park City',                'brand' => 'Westgate',              'city' => 'Park City',  'state' => 'UT', 'tz' => 'America/Denver'],
            ['name' => 'Wyndham Destinations Orlando',      'brand' => 'Wyndham',               'city' => 'Orlando',    'state' => 'FL', 'tz' => 'America/New_York'],
            ['name' => 'Disney Vacation Club Aulani',       'brand' => 'Disney Vacation Club',  'city' => 'Kapolei',    'state' => 'HI', 'tz' => 'Pacific/Honolulu'],
            ['name' => 'Hilton Grand Vacations Las Vegas',  'brand' => 'Hilton',                'city' => 'Las Vegas',  'state' => 'NV', 'tz' => 'America/Los_Angeles'],
        ];

        $resorts = [];
        foreach ($brands as $b) {
            $resorts[] = ResortFactory::new()->create([
                'name' => $b['name'],
                'brand' => $b['brand'],
                'slug' => Str::slug($b['name']),
                'country' => 'US',
                'state' => $b['state'],
                'city' => $b['city'],
                'timezone' => $b['tz'],
            ]);
        }

        return $resorts;
    }

    /**
     * @param  list<Resort>  $resorts
     * @return array{0: list<\App\Modules\Booking\Domain\Models\InventoryUnit>, 1: list<InventoryAvailability>}
     */
    private function createInventory(array $resorts): array
    {
        $unitTypes = [
            ['type' => 'studio',       'sleeps' => 2,  'price' => 1200],
            ['type' => '1br',          'sleeps' => 4,  'price' => 1800],
            ['type' => '2br',          'sleeps' => 6,  'price' => 2400],
            ['type' => '3br',          'sleeps' => 8,  'price' => 3200],
            ['type' => 'presidential', 'sleeps' => 10, 'price' => 5000],
        ];

        $units = [];
        $availability = [];
        foreach ($resorts as $resort) {
            foreach ($unitTypes as $ut) {
                $unit = InventoryUnitFactory::new()->create([
                    'resort_id' => $resort->id,
                    'unit_type' => $ut['type'],
                    'sleeps' => $ut['sleeps'],
                ]);
                $units[] = $unit;

                $checkIn = now()->next('Saturday')->addWeek();
                for ($w = 0; $w < 8; $w++) {
                    $start = $checkIn->copy()->addWeeks($w);
                    $availability[] = InventoryAvailabilityFactory::new()->create([
                        'resort_id' => $resort->id,
                        'inventory_unit_id' => $unit->id,
                        'check_in_date' => $start->toDateString(),
                        'check_out_date' => $start->copy()->addDays(7)->toDateString(),
                        'nights' => 7,
                        'base_price' => $ut['price'],
                        'current_price' => $ut['price'],
                    ]);
                }
            }
        }

        return [$units, $availability];
    }

    /**
     * @return list<Campaign>
     */
    private function createCampaigns(User $admin): array
    {
        $now = now();

        return [
            Campaign::query()->create([
                'name' => 'Q2 Florida Buyers',
                'status' => Campaign::STATUS_ACTIVE,
                'dialer_mode' => DialerMode::Predictive->value,
                'target_abandon_rate' => 0.03,
                'safety_factor' => 1.2,
                'max_attempts_per_lead' => 6,
                'min_hours_between_attempts' => 4,
                'earliest_call_local' => '09:00:00',
                'latest_call_local' => '20:00:00',
                'starts_at' => $now->copy()->subWeeks(2),
                'ends_at' => $now->copy()->addWeeks(4),
                'metadata' => ['target_state' => 'FL', 'min_score' => 40],
            ]),
            Campaign::query()->create([
                'name' => 'Hot Inbound — All Regions',
                'status' => Campaign::STATUS_ACTIVE,
                'dialer_mode' => DialerMode::Progressive->value,
                'target_abandon_rate' => 0.02,
                'safety_factor' => 1.0,
                'max_attempts_per_lead' => 8,
                'min_hours_between_attempts' => 2,
                'earliest_call_local' => '08:00:00',
                'latest_call_local' => '21:00:00',
                'starts_at' => $now->copy()->subWeek(),
                'metadata' => ['priority' => 'hot'],
            ]),
            Campaign::query()->create([
                'name' => 'Manual Callback Queue',
                'status' => Campaign::STATUS_ACTIVE,
                'dialer_mode' => DialerMode::Manual->value,
                'target_abandon_rate' => 0.0,
                'safety_factor' => 1.0,
                'max_attempts_per_lead' => 10,
                'min_hours_between_attempts' => 24,
                'earliest_call_local' => '08:00:00',
                'latest_call_local' => '21:00:00',
                'starts_at' => $now->copy()->subMonth(),
            ]),
        ];
    }

    /**
     * Leads with regional phones so they line up with the four resorts.
     *
     * Distribution across statuses:
     *   60 new, 50 contacted, 30 qualified, 20 pitched, 20 closed_won, 20 closed_lost
     */
    private function createLeads(Tenant $tenant): \Illuminate\Support\Collection
    {
        $regions = [
            ['areas' => self::AREA_FL, 'state' => 'FL', 'city' => 'Orlando',   'tz' => 'America/New_York'],
            ['areas' => self::AREA_UT, 'state' => 'UT', 'city' => 'Salt Lake City', 'tz' => 'America/Denver'],
            ['areas' => self::AREA_HI, 'state' => 'HI', 'city' => 'Honolulu',  'tz' => 'Pacific/Honolulu'],
            ['areas' => self::AREA_NV, 'state' => 'NV', 'city' => 'Las Vegas', 'tz' => 'America/Los_Angeles'],
        ];

        $cohorts = [
            ['count' => 60, 'status' => LeadStatus::New->value,            'priority' => LeadPriority::Normal->value, 'source' => 'web_form'],
            ['count' => 50, 'status' => LeadStatus::Contacted->value,      'priority' => LeadPriority::Normal->value, 'source' => 'facebook'],
            ['count' => 30, 'status' => LeadStatus::Qualified->value,      'priority' => LeadPriority::High->value,   'source' => 'referral'],
            ['count' => 20, 'status' => LeadStatus::PitchPresented->value, 'priority' => LeadPriority::Hot->value,    'source' => 'inbound_call'],
            ['count' => 20, 'status' => LeadStatus::ClosedWon->value,      'priority' => LeadPriority::Normal->value, 'source' => 'referral'],
            ['count' => 20, 'status' => LeadStatus::ClosedLost->value,     'priority' => LeadPriority::Low->value,    'source' => 'cold_list'],
        ];

        $leads = collect();
        foreach ($cohorts as $cohort) {
            for ($i = 0; $i < $cohort['count']; $i++) {
                $region = $regions[array_rand($regions)];
                $area = $region['areas'][array_rand($region['areas'])];
                $phone = '+1'.$area.str_pad((string) random_int(1000000, 9999999), 7, '0', STR_PAD_LEFT);

                $lead = LeadFactory::new()->create([
                    'tenant_id' => $tenant->id,
                    'phone' => $phone,
                    'phone_hash' => hash('sha256', $phone),
                    'state' => $region['state'],
                    'city' => $region['city'],
                    'timezone' => $region['tz'],
                    'status' => $cohort['status'],
                    'priority' => $cohort['priority'],
                    'source' => $cohort['source'],
                    'score' => $cohort['status'] === LeadStatus::New->value ? random_int(0, 30) : random_int(40, 95),
                    'last_contacted_at' => $cohort['status'] === LeadStatus::New->value
                        ? null : now()->subDays(random_int(1, 14)),
                ]);

                $leads->push($lead);
            }
        }

        return $leads;
    }

    /**
     * 60% of contacted+qualified+pitched+won leads get an express-consent record
     * (web form opt-in, with IP + user-agent + consent-text snapshot).
     */
    private function createConsentRecords(\Illuminate\Support\Collection $leads): void
    {
        $eligibleStatuses = [
            LeadStatus::Contacted->value,
            LeadStatus::Qualified->value,
            LeadStatus::PitchPresented->value,
            LeadStatus::ClosedWon->value,
        ];

        $leads
            ->filter(fn (Lead $l) => in_array($l->status->value, $eligibleStatuses, true))
            ->each(function (Lead $lead): void {
                if (random_int(1, 100) > 60) {
                    return;
                }
                ConsentRecord::query()->create([
                    'phone' => $lead->phone,
                    'phone_hash' => $lead->phone_hash,
                    'consent_type' => 'autodialer',
                    'source' => 'web_form',
                    'source_url' => 'https://primevacations.test/quote',
                    'source_ip' => long2ip(random_int(0, 0xFFFFFFFF)),
                    'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                    'consent_text_snapshot' => [
                        'version' => '1.0',
                        'text' => 'I consent to receive autodialed marketing calls and texts from Prime Vacations.',
                    ],
                    'consented_at' => now()->subDays(random_int(1, 60)),
                ]);

                $lead->update([
                    'has_express_consent' => true,
                    'consent_at' => now()->subDays(random_int(1, 60)),
                ]);
            });
    }

    /**
     * 30 DNC entries — 15 federal (random phones), 10 internal (matching specific
     * leads, which we then mark on_dnc=true), 5 wireless.
     */
    private function createDncEntries(Tenant $tenant, \Illuminate\Support\Collection $leads): void
    {
        for ($i = 0; $i < 15; $i++) {
            $phone = '+1'.str_pad((string) random_int(2000000000, 9999999999), 10, '0', STR_PAD_LEFT);
            DncEntry::query()->create([
                'tenant_id' => null,
                'phone' => $phone,
                'phone_hash' => hash('sha256', $phone),
                'source' => 'federal_dnc',
                'reason' => null,
                'added_by' => 'federal_delta_import',
                'effective_date' => now()->subDays(random_int(30, 365))->toDateString(),
            ]);
        }

        // Internal DNC entries linked to actual leads — pick from contacted/qualified/lost
        $internalCandidates = $leads
            ->filter(fn (Lead $l) => in_array($l->status->value, [
                LeadStatus::Contacted->value,
                LeadStatus::Qualified->value,
                LeadStatus::ClosedLost->value,
            ], true))
            ->take(10);

        foreach ($internalCandidates as $lead) {
            DncEntry::query()->create([
                'tenant_id' => $tenant->id,
                'phone' => $lead->phone,
                'phone_hash' => $lead->phone_hash,
                'source' => 'internal_dnc',
                'reason' => 'Customer request via call',
                'added_by' => 'agent',
                'effective_date' => now()->subDays(random_int(1, 30))->toDateString(),
            ]);
            $lead->update(['is_on_dnc' => true]);
        }

        for ($i = 0; $i < 5; $i++) {
            $phone = '+1'.str_pad((string) random_int(2000000000, 9999999999), 10, '0', STR_PAD_LEFT);
            DncEntry::query()->create([
                'tenant_id' => null,
                'phone' => $phone,
                'phone_hash' => hash('sha256', $phone),
                'source' => 'wireless_dnc',
                'reason' => 'Wireless number — TCPA',
                'added_by' => 'wireless_block_list',
                'effective_date' => now()->subDays(random_int(60, 200))->toDateString(),
            ]);
        }
    }

    /**
     * Every closed_won lead gets a Customer record. The Customers/Index screen
     * reads from this table — leads alone don't show there.
     *
     * @param  array{admin: User, supervisor: User, qa: User, closers: list<User>, fronters: list<User>}  $users
     */
    private function createCustomersFromWonLeads(\Illuminate\Support\Collection $leads, array $users): \Illuminate\Support\Collection
    {
        $closers = $users['closers'];
        $customers = collect();

        $leads
            ->filter(fn (Lead $l) => $l->status === LeadStatus::ClosedWon)
            ->each(function (Lead $lead) use ($closers, $customers): void {
                $closer = $closers[array_rand($closers)];
                $ltv = random_int(8000, 45000);
                $deals = random_int(1, 3);

                $customer = Customer::query()->create([
                    'lead_id' => $lead->id,
                    'user_id' => $closer->id,
                    'first_name' => $lead->first_name,
                    'last_name' => $lead->last_name,
                    'email' => $lead->email,
                    'phone' => $lead->phone,
                    'phone_hash' => $lead->phone_hash,
                    'country' => 'US',
                    'state' => $lead->state,
                    'city' => $lead->city,
                    'timezone' => $lead->timezone,
                    'status' => $ltv >= 25000 ? Customer::STATUS_VIP : Customer::STATUS_ACTIVE,
                    'source' => $lead->source,
                    'lifetime_value' => $ltv,
                    'total_deals' => $deals,
                    'total_bookings' => $deals,
                    'first_purchase_at' => now()->subMonths(random_int(2, 12)),
                    'last_purchase_at' => now()->subDays(random_int(1, 60)),
                ]);
                $customers->push($customer);
            });

        return $customers;
    }

    /**
     * One active dial session per agent (closers + fronters). Realistic counters
     * so the supervisor War Room shows non-zero metrics.
     *
     * @param  array{admin: User, supervisor: User, qa: User, closers: list<User>, fronters: list<User>}  $users
     * @param  list<Campaign>  $campaigns
     * @return list<DialSession>
     */
    private function createDialSessions(array $users, array $campaigns): array
    {
        $agents = array_merge($users['closers'], $users['fronters']);
        $sessions = [];

        foreach ($agents as $i => $agent) {
            $campaign = $campaigns[$i % count($campaigns)];
            $initiated = random_int(40, 120);
            $connected = (int) ($initiated * 0.42);
            $abandoned = (int) ($initiated * 0.025);

            $sessions[] = DialSession::query()->create([
                'agent_id' => $agent->id,
                'campaign_id' => $campaign->id,
                'mode' => $campaign->dialer_mode,
                'status' => DialSession::STATUS_ACTIVE,
                'started_at' => now()->subHours(random_int(1, 4))->subMinutes(random_int(0, 59)),
                'leads_processed' => $initiated,
                'calls_initiated' => $initiated,
                'calls_connected' => $connected,
                'calls_abandoned' => $abandoned,
                'total_talk_seconds' => $connected * random_int(120, 240),
                'total_wrap_seconds' => $connected * random_int(20, 45),
                'settings' => ['safety_factor' => 1.2],
            ]);
        }

        return $sessions;
    }

    /**
     * Calls have to look real on the supervisor war room AND on the agent's call
     * history. We make:
     *   - 5 currently in_progress (one per agent — drives war-room live feed)
     *   - ~120 historical calls spread across contacted/qualified/pitched/won leads
     *   - mix of dispositions (interested, no_answer, voicemail, sale_closed, dnc_request)
     *
     * @param  array{admin: User, supervisor: User, qa: User, closers: list<User>, fronters: list<User>}  $users
     * @param  list<DialSession>  $sessions
     * @param  list<Campaign>  $campaigns
     */
    private function createCalls(
        \Illuminate\Support\Collection $leads,
        array $users,
        array $sessions,
        array $campaigns,
    ): \Illuminate\Support\Collection {
        $agents = array_merge($users['closers'], $users['fronters']);
        $sessionsByAgent = collect($sessions)->keyBy('agent_id');
        $calls = collect();

        // Live calls — one per agent currently in_progress. War room reads these.
        foreach ($agents as $idx => $agent) {
            // Pick a hot/contacted lead from the agent's region — fall back to any
            $lead = $leads->filter(fn (Lead $l) => $l->status === LeadStatus::PitchPresented)->random();
            $session = $sessionsByAgent[$agent->id];

            $calls->push(Call::query()->create([
                'lead_id' => $lead->id,
                'agent_id' => $agent->id,
                'dial_session_id' => $session->id,
                'campaign_id' => $session->campaign_id,
                'provider' => 'twilio',
                'provider_call_sid' => 'CA'.Str::lower(Str::random(32)),
                'from_number' => '+18555550100',
                'to_number' => $lead->phone,
                'direction' => CallDirection::Outbound->value,
                'status' => CallStatus::InProgress->value,
                'queued_at' => now()->subMinutes(random_int(2, 8)),
                'initiated_at' => now()->subMinutes(random_int(2, 8)),
                'answered_at' => now()->subSeconds(random_int(30, 240)),
                'ring_seconds' => random_int(3, 9),
                'duration_seconds' => random_int(30, 240),
                'recording_status' => 'recording',
                'transcription_status' => 'in_progress',
            ]));
        }

        // Historical calls — for each contacted+qualified+pitched+won lead, 1–3 calls.
        $historicalStatuses = [
            LeadStatus::Contacted->value,
            LeadStatus::Qualified->value,
            LeadStatus::PitchPresented->value,
            LeadStatus::ClosedWon->value,
        ];

        $dispositionWeights = [
            CallDisposition::NoAnswer->value => 30,
            CallDisposition::Voicemail->value => 20,
            CallDisposition::Interested->value => 15,
            CallDisposition::Callback->value => 10,
            CallDisposition::NotInterested->value => 10,
            CallDisposition::PitchPresented->value => 8,
            CallDisposition::SaleClosed->value => 5,
            CallDisposition::DncRequest->value => 2,
        ];

        $leads
            ->filter(fn (Lead $l) => in_array($l->status->value, $historicalStatuses, true))
            ->each(function (Lead $lead) use ($agents, $sessionsByAgent, $calls, $dispositionWeights): void {
                $count = random_int(1, 3);
                for ($i = 0; $i < $count; $i++) {
                    $agent = $agents[array_rand($agents)];
                    $session = $sessionsByAgent[$agent->id];
                    $disposition = $this->weightedPick($dispositionWeights);
                    $callStatus = $this->callStatusForDisposition($disposition);
                    $duration = $callStatus === CallStatus::Completed->value ? random_int(45, 480) : 0;
                    $minutesAgo = random_int(60, 60 * 24 * 14);
                    $initiated = now()->subMinutes($minutesAgo);

                    $calls->push(Call::query()->create([
                        'lead_id' => $lead->id,
                        'agent_id' => $agent->id,
                        'dial_session_id' => $session->id,
                        'campaign_id' => $session->campaign_id,
                        'provider' => 'twilio',
                        'provider_call_sid' => 'CA'.Str::lower(Str::random(32)),
                        'from_number' => '+18555550100',
                        'to_number' => $lead->phone,
                        'direction' => CallDirection::Outbound->value,
                        'status' => $callStatus,
                        'disposition' => $disposition,
                        'disposition_notes' => $disposition === CallDisposition::Callback->value
                            ? 'Caller asked to be reached after 5pm local'
                            : null,
                        'queued_at' => $initiated->copy()->subSeconds(2),
                        'initiated_at' => $initiated,
                        'answered_at' => in_array($callStatus, [CallStatus::Completed->value, CallStatus::InProgress->value], true)
                            ? $initiated->copy()->addSeconds(random_int(3, 9)) : null,
                        'ended_at' => $callStatus === CallStatus::Completed->value
                            ? $initiated->copy()->addSeconds($duration + 8) : null,
                        'ring_seconds' => random_int(3, 12),
                        'duration_seconds' => $duration,
                        'wrap_up_seconds' => $duration > 0 ? random_int(15, 45) : 0,
                        'recording_status' => $duration > 0 ? 'completed' : 'not_recorded',
                        'transcription_status' => $duration > 0 ? 'completed' : 'not_started',
                        'sentiment' => $duration > 0 ? $this->sentimentFor($disposition) : null,
                        'provider_cost' => $duration > 0 ? round($duration / 60 * 0.013, 4) : null,
                        'provider_cost_currency' => 'USD',
                    ]));
                }
            });

        return $calls;
    }

    /**
     * For each call, write its lifecycle as a chain of call_events
     * (queued → ringing → answered → ended). Idempotency keys must be unique.
     */
    private function createCallEvents(\Illuminate\Support\Collection $calls): void
    {
        foreach ($calls as $call) {
            /** @var Call $call */
            $base = [
                'tenant_id' => $call->tenant_id,
                'call_id' => $call->id,
                'source' => 'twilio_webhook',
            ];

            CallEvent::query()->create($base + [
                'event_type' => 'queued',
                'occurred_at' => $call->queued_at ?? $call->created_at,
                'payload' => ['CallStatus' => 'queued'],
                'idempotency_key' => "demo:{$call->id}:queued",
            ]);
            if ($call->initiated_at) {
                CallEvent::query()->create($base + [
                    'event_type' => 'ringing',
                    'occurred_at' => $call->initiated_at,
                    'payload' => ['CallStatus' => 'ringing'],
                    'idempotency_key' => "demo:{$call->id}:ringing",
                ]);
            }
            if ($call->answered_at) {
                CallEvent::query()->create($base + [
                    'event_type' => 'answered',
                    'occurred_at' => $call->answered_at,
                    'payload' => ['CallStatus' => 'in-progress', 'AnsweredBy' => 'human'],
                    'idempotency_key' => "demo:{$call->id}:answered",
                ]);
            }
            if ($call->ended_at) {
                CallEvent::query()->create($base + [
                    'event_type' => 'ended',
                    'occurred_at' => $call->ended_at,
                    'payload' => [
                        'CallStatus' => $call->status,
                        'CallDuration' => $call->duration_seconds,
                        'Disposition' => $call->disposition,
                    ],
                    'idempotency_key' => "demo:{$call->id}:ended",
                ]);
            }
        }
    }

    /**
     * One contact_attempt row per outbound call — this is what the frequency-cap
     * service reads. The phone_hash is the lead's hash so caps work correctly.
     */
    private function createContactAttempts(\Illuminate\Support\Collection $calls): void
    {
        foreach ($calls as $call) {
            /** @var Call $call */
            if (! $call->lead_id) {
                continue;
            }
            $lead = Lead::withoutTenantScope()->find($call->lead_id);
            if (! $lead) {
                continue;
            }

            ContactAttempt::query()->create([
                'phone_hash' => $lead->phone_hash,
                'attempt_type' => ContactAttempt::ATTEMPT_OUTBOUND_CALL,
                // $call->status / $call->disposition are enum-cast on the Call model;
                // the helper takes strings, so unwrap to the backing values.
                'outcome' => $this->outcomeFromCallStatus($call->status?->value, $call->disposition?->value),
                'attempted_at' => $call->initiated_at ?? $call->created_at,
            ]);
        }
    }

    /**
     * Exactly one row per agent in `agent_statuses`. Distribution chosen so
     * the war room shows variety: 2 available, 2 on_call, 1 wrap_up, 1 on_break,
     * supervisors/admin/qa offline.
     *
     * @param  array{admin: User, supervisor: User, qa: User, closers: list<User>, fronters: list<User>}  $users
     * @param  list<DialSession>  $sessions
     */
    private function createAgentStatuses(array $users, array $sessions, \Illuminate\Support\Collection $calls): void
    {
        $sessionsByAgent = collect($sessions)->keyBy('agent_id');
        $liveCallsByAgent = $calls->where('status', CallStatus::InProgress->value)->keyBy('agent_id');

        $agents = array_merge($users['closers'], $users['fronters']);
        $statusPlan = [
            AgentStatus::OnCall->value,    // closer 0
            AgentStatus::Available->value, // closer 1
            AgentStatus::WrapUp->value,    // closer 2
            AgentStatus::OnCall->value,    // fronter 0
            AgentStatus::OnBreak->value,   // fronter 1
        ];

        foreach ($agents as $i => $agent) {
            $status = $statusPlan[$i] ?? AgentStatus::Available->value;
            $session = $sessionsByAgent[$agent->id] ?? null;
            $liveCall = $liveCallsByAgent[$agent->id] ?? null;

            AgentStatusRecord::query()->create([
                'agent_id' => $agent->id,
                'status' => $status,
                'previous_status' => AgentStatus::Available->value,
                'current_call_id' => $status === AgentStatus::OnCall->value ? $liveCall?->id : null,
                'current_session_id' => $session?->id,
                'status_changed_at' => now()->subMinutes(random_int(1, 30)),
                'last_heartbeat_at' => now()->subSeconds(random_int(2, 25)),
                'metadata' => ['skills' => $agent->skills ?? ['english']],
            ]);
        }

        // Non-call-takers: admin, supervisor, qa → offline (so they're not counted as agents).
        foreach ([$users['admin'], $users['supervisor'], $users['qa']] as $u) {
            AgentStatusRecord::query()->create([
                'agent_id' => $u->id,
                'status' => AgentStatus::Offline->value,
                'status_changed_at' => now()->subHours(random_int(1, 6)),
                'last_heartbeat_at' => now()->subHours(random_int(1, 6)),
                'metadata' => [],
            ]);
        }
    }

    /**
     * Deals from won + pitched leads. Multi-closer split on ~30% of won deals.
     *
     * @param  array{admin: User, supervisor: User, qa: User, closers: list<User>, fronters: list<User>}  $users
     * @param  list<Resort>  $resorts
     */
    private function createDeals(\Illuminate\Support\Collection $leads, array $users, array $resorts): \Illuminate\Support\Collection
    {
        $closers = $users['closers'];
        $fronters = $users['fronters'];
        $deals = collect();

        $stageMap = [
            LeadStatus::ClosedWon->value => DealStage::ClosedWon->value,
            LeadStatus::PitchPresented->value => DealStage::PitchPresented->value,
        ];

        foreach ($leads as $lead) {
            $leadStatus = $lead->status->value;
            if (! isset($stageMap[$leadStatus])) {
                continue;
            }
            $closer = $closers[array_rand($closers)];
            $fronter = $fronters[array_rand($fronters)];
            $resort = $resorts[array_rand($resorts)];

            $total = random_int(3500, 28000);
            $snr = (int) ($total * 0.10);
            $vd = (int) ($total * 0.05);
            $payable = $total - $snr - $vd;

            $stage = $stageMap[$leadStatus];
            $closedAt = $stage === DealStage::ClosedWon->value
                ? now()->subDays(random_int(1, 60))
                : null;

            // 30% of won deals have a co-closer
            $additionalClosers = null;
            if ($stage === DealStage::ClosedWon->value && random_int(1, 100) <= 30) {
                $other = collect($closers)->reject(fn ($c) => $c->id === $closer->id)->random();
                $additionalClosers = [
                    ['user_id' => $other->id, 'split_pct' => 30],
                ];
            }

            $deals->push(Deal::query()->create([
                'lead_id' => $lead->id,
                'agent_id' => $closer->id,
                'fronter_id' => $fronter->id,
                'additional_closer_ids' => $additionalClosers,
                'stage' => $stage,
                'previous_stage' => DealStage::Negotiating->value,
                'stage_changed_at' => $closedAt ?? now()->subHours(random_int(1, 48)),
                'total_value' => $total,
                'snr_amount' => $snr,
                'vd_amount' => $vd,
                'payable_amount' => $payable,
                'currency' => 'USD',
                'pitch_data' => [
                    'resort_id' => $resort->id,
                    'resort_name' => $resort->name,
                    'package' => 'standard_vacation_week',
                ],
                'expected_close_at' => $stage === DealStage::PitchPresented->value
                    ? now()->addDays(random_int(2, 14)) : null,
                'closed_at' => $closedAt,
            ]));
        }

        return $deals;
    }

    /**
     * For each deal, write 3-5 historical stage transitions ending at the
     * deal's current stage. Drives the pipeline screen's deal-history sidebar.
     *
     * @param  array{admin: User, supervisor: User, qa: User, closers: list<User>, fronters: list<User>}  $users
     */
    private function createDealStageTransitions(\Illuminate\Support\Collection $deals, array $users): void
    {
        $journey = [
            DealStage::New->value,
            DealStage::Contacted->value,
            DealStage::Qualified->value,
            DealStage::PitchPresented->value,
            DealStage::Negotiating->value,
        ];

        foreach ($deals as $deal) {
            /** @var Deal $deal */
            $stageStr = $deal->stage->value;
            $endIdx = array_search($stageStr, $journey, true);
            if ($endIdx === false) {
                $endIdx = count($journey) - 1; // closed_won/lost — show full pre-close journey
            }
            $base = $deal->closed_at ?? $deal->stage_changed_at ?? now();
            $base = Carbon::parse($base);

            $previous = null;
            for ($i = 0; $i <= min($endIdx, count($journey) - 1); $i++) {
                $stage = $journey[$i];
                $occurred = $base->copy()->subDays($endIdx - $i + 1);
                DealStageTransition::query()->create([
                    'deal_id' => $deal->id,
                    'changed_by_id' => $deal->agent_id,
                    'from_stage' => $previous,
                    'to_stage' => $stage,
                    'reason' => null,
                    'occurred_at' => $occurred,
                ]);
                $previous = $stage;
            }
            // Final transition into the deal's current stage if it's not already in the journey.
            if (! in_array($stageStr, $journey, true)) {
                DealStageTransition::query()->create([
                    'deal_id' => $deal->id,
                    'changed_by_id' => $deal->agent_id,
                    'from_stage' => $previous,
                    'to_stage' => $stageStr,
                    'reason' => $deal->stage === DealStage::ClosedWon ? 'Contract signed' : null,
                    'occurred_at' => $base,
                ]);
            }
        }
    }

    /**
     * Active inventory holds for ~half the pitch-presented deals so the booking
     * search screen has live conflicts. The availability row's status flips to
     * `held` and `current_hold_id` points at the new hold.
     *
     * @param  list<InventoryAvailability>  $availability
     * @param  array{admin: User, supervisor: User, qa: User, closers: list<User>, fronters: list<User>}  $users
     */
    private function createInventoryHolds(\Illuminate\Support\Collection $deals, array $availability, array $users): void
    {
        $available = collect($availability)
            ->filter(fn (InventoryAvailability $a) => $a->status === InventoryAvailability::STATUS_AVAILABLE)
            ->shuffle();

        $pitchedDeals = $deals
            ->filter(fn (Deal $d) => $d->stage === DealStage::PitchPresented)
            ->take(10);

        foreach ($pitchedDeals as $i => $deal) {
            /** @var Deal $deal */
            if ($available->isEmpty()) {
                break;
            }
            /** @var InventoryAvailability $slot */
            $slot = $available->shift();

            $hold = InventoryHold::query()->create([
                'inventory_availability_id' => $slot->id,
                'lead_id' => $deal->lead_id,
                'deal_id' => $deal->id,
                'held_by_id' => $deal->agent_id,
                'expires_at' => now()->addMinutes(random_int(10, 28)),
            ]);
            $slot->update([
                'status' => InventoryAvailability::STATUS_HELD,
                'current_hold_id' => $hold->id,
            ]);
        }

        // Plus 5 expired holds (released_at set, release_reason='expired') —
        // demonstrates the holds-released history without conflicting on the partial unique.
        $availForExpired = collect($availability)
            ->filter(fn (InventoryAvailability $a) => $a->status === InventoryAvailability::STATUS_AVAILABLE)
            ->shuffle()
            ->take(5);

        foreach ($availForExpired as $slot) {
            InventoryHold::query()->create([
                'inventory_availability_id' => $slot->id,
                'lead_id' => null,
                'deal_id' => null,
                'held_by_id' => $users['closers'][0]->id,
                'expires_at' => now()->subHours(random_int(2, 24)),
                'released_at' => now()->subHours(random_int(1, 23)),
                'release_reason' => 'expired',
            ]);
        }
    }

    /**
     * Create bookings from won deals. 70% confirmed/paid, 20% confirmed-but-unpaid,
     * 10% cancelled. Each booking consumes one availability row (status='booked').
     *
     * @param  list<InventoryAvailability>  $availability
     * @param  array{admin: User, supervisor: User, qa: User, closers: list<User>, fronters: list<User>}  $users
     */
    private function createBookings(\Illuminate\Support\Collection $deals, array $availability, array $users): \Illuminate\Support\Collection
    {
        $bookings = collect();
        $availQueue = collect($availability)
            ->filter(fn (InventoryAvailability $a) => $a->status === InventoryAvailability::STATUS_AVAILABLE)
            ->shuffle()
            ->values();

        $deals
            ->filter(fn (Deal $d) => $d->stage === DealStage::ClosedWon)
            ->each(function (Deal $deal) use (&$availQueue, $bookings): void {
                if ($availQueue->isEmpty()) {
                    return;
                }
                /** @var InventoryAvailability $slot */
                $slot = $availQueue->shift();

                $roll = random_int(1, 100);
                $status = match (true) {
                    $roll <= 70 => Booking::STATUS_PAID,
                    $roll <= 90 => Booking::STATUS_CONFIRMED,
                    default     => Booking::STATUS_CANCELLED,
                };

                $confirmed = Carbon::parse($deal->closed_at ?? now()->subDays(7));

                $booking = Booking::query()->create([
                    'lead_id' => $deal->lead_id,
                    'deal_id' => $deal->id,
                    'inventory_availability_id' => $slot->id,
                    'agent_id' => $deal->agent_id,
                    'status' => $status,
                    'total_price' => $deal->payable_amount,
                    'paid_amount' => $status === Booking::STATUS_PAID ? $deal->payable_amount : 0,
                    'currency' => 'USD',
                    'check_in_date' => $slot->check_in_date,
                    'check_out_date' => $slot->check_out_date,
                    'guest_details' => [
                        'lead_guest' => 'Primary guest',
                        'adults' => 2,
                        'children' => random_int(0, 3),
                    ],
                    'confirmation_number' => 'PV-'.strtoupper(Str::random(8)),
                    'confirmed_at' => $confirmed,
                    'cancelled_at' => $status === Booking::STATUS_CANCELLED
                        ? $confirmed->copy()->addDays(random_int(1, 5)) : null,
                    'cancellation_reason' => $status === Booking::STATUS_CANCELLED
                        ? 'Customer changed travel plans' : null,
                ]);

                $slot->update([
                    'status' => $status === Booking::STATUS_CANCELLED
                        ? InventoryAvailability::STATUS_AVAILABLE
                        : InventoryAvailability::STATUS_BOOKED,
                    'booking_id' => $status === Booking::STATUS_CANCELLED ? null : $booking->id,
                ]);

                // Tie the deal back to its booking
                $deal->update(['booking_id' => $booking->id]);

                $bookings->push($booking);
            });

        return $bookings;
    }

    /**
     * Per-booking payment lifecycle:
     *   - paid bookings → 1 cleared payment
     *   - confirmed (unpaid) bookings → 1 pending OR 1 failed payment
     *   - cancelled bookings → 1 cleared + 1 refund (parent_payment_id linked)
     *
     * @param  array{admin: User, supervisor: User, qa: User, closers: list<User>, fronters: list<User>}  $users
     */
    private function createPayments(\Illuminate\Support\Collection $bookings, array $users): \Illuminate\Support\Collection
    {
        $payments = collect();

        foreach ($bookings as $booking) {
            /** @var Booking $booking */
            $closer = User::withoutTenantScope()->find($booking->agent_id);

            if ($booking->status === Booking::STATUS_PAID) {
                $payments->push($this->createCharge($booking, $closer, 'cleared'));
            } elseif ($booking->status === Booking::STATUS_CONFIRMED) {
                $payments->push($this->createCharge(
                    $booking, $closer, random_int(1, 100) <= 60 ? 'pending' : 'failed',
                ));
            } else {
                // cancelled: charge succeeded, then refunded
                $original = $this->createCharge($booking, $closer, 'cleared');
                $payments->push($original);
                $payments->push(Payment::query()->create([
                    'booking_id' => $booking->id,
                    'deal_id' => $booking->deal_id,
                    'lead_id' => $booking->lead_id,
                    'processed_by_id' => $closer?->id,
                    'provider' => 'stripe',
                    'provider_payment_id' => 're_'.Str::lower(Str::random(24)),
                    'payment_method' => 'card',
                    'card_last_four' => $original->card_last_four,
                    'card_brand' => $original->card_brand,
                    'amount' => $booking->total_price,
                    'currency' => 'USD',
                    'type' => Payment::TYPE_REFUND,
                    'status' => Payment::STATUS_REFUNDED,
                    'parent_payment_id' => $original->id,
                    'refunded_at' => $booking->cancelled_at ?? now()->subDays(2),
                ]));
            }
        }

        return $payments;
    }

    private function createCharge(Booking $booking, ?User $closer, string $kind): Payment
    {
        $cards = ['visa' => '4242', 'mastercard' => '5555', 'amex' => '0005', 'discover' => '4444'];
        $brand = array_rand($cards);
        $last4 = $cards[$brand];

        $base = [
            'booking_id' => $booking->id,
            'deal_id' => $booking->deal_id,
            'lead_id' => $booking->lead_id,
            'processed_by_id' => $closer?->id,
            'provider' => 'stripe',
            'provider_payment_id' => 'pi_'.Str::lower(Str::random(24)),
            'provider_customer_id' => 'cus_'.Str::lower(Str::random(14)),
            'payment_method' => 'card',
            'card_last_four' => $last4,
            'card_brand' => $brand,
            'amount' => $booking->total_price,
            'currency' => 'USD',
            'type' => Payment::TYPE_CHARGE,
        ];

        if ($kind === 'cleared') {
            $clearedAt = Carbon::parse($booking->confirmed_at)->addMinutes(random_int(2, 30));

            return Payment::query()->create($base + [
                'status' => Payment::STATUS_SUCCEEDED,
                'authorized_at' => $clearedAt->copy()->subMinutes(2),
                'captured_at' => $clearedAt->copy()->subMinute(),
                'cleared_at' => $clearedAt,
            ]);
        }
        if ($kind === 'failed') {
            return Payment::query()->create($base + [
                'status' => Payment::STATUS_FAILED,
                'failure_code' => 'card_declined',
                'failure_reason' => 'Your card was declined.',
            ]);
        }

        return Payment::query()->create($base + ['status' => Payment::STATUS_PENDING]);
    }

    /**
     * Stripe `payment_intent.succeeded` webhook event for each cleared payment,
     * Twilio `call-status` for completed calls. The `webhook_events` table is
     * what the webhook-events screen / Horizon dashboard read from.
     */
    private function createWebhookEvents(\Illuminate\Support\Collection $payments, \Illuminate\Support\Collection $calls): void
    {
        foreach ($payments as $payment) {
            /** @var Payment $payment */
            if ($payment->status !== Payment::STATUS_SUCCEEDED) {
                continue;
            }
            WebhookEvent::query()->create([
                'tenant_id' => $payment->tenant_id,
                'provider' => 'stripe',
                'event_type' => 'payment_intent.succeeded',
                'external_id' => 'evt_'.Str::lower(Str::random(28)),
                'payload' => [
                    'id' => $payment->provider_payment_id,
                    'object' => 'payment_intent',
                    'amount' => (int) ((float) $payment->amount * 100),
                    'currency' => 'usd',
                    'status' => 'succeeded',
                ],
                'status' => 'processed',
                'attempts' => 1,
                'processed_at' => $payment->cleared_at,
            ]);
        }

        // A handful of Twilio call-status webhooks for completed calls
        $calls
            ->where('status', CallStatus::Completed->value)
            ->take(20)
            ->each(function (Call $call): void {
                WebhookEvent::query()->create([
                    'tenant_id' => $call->tenant_id,
                    'provider' => 'twilio',
                    'event_type' => 'call.status',
                    'external_id' => $call->provider_call_sid.'-completed',
                    'payload' => [
                        'CallSid' => $call->provider_call_sid,
                        'CallStatus' => 'completed',
                        'CallDuration' => (string) $call->duration_seconds,
                    ],
                    'status' => 'processed',
                    'attempts' => 1,
                    'processed_at' => $call->ended_at,
                ]);
            });
    }

    /**
     * Standard 10/2 plan: closer 10% + fronter 2% on payment.cleared.
     * Reused from the original seeder, but now wires up to actual payments.
     */
    private function createCommissionPlan(Tenant $tenant): CommissionPlan
    {
        $plan = CommissionPlan::query()->create([
            'name' => 'Standard 10/2',
            'description' => 'Closer 10% + Fronter 2% on payment.cleared',
            'active' => true,
            'effective_from' => now()->subYear()->toDateString(),
        ]);

        CommissionPlanRule::query()->create([
            'commission_plan_id' => $plan->id,
            'role' => CommissionPlanRule::ROLE_CLOSER,
            'rule_type' => CommissionPlanRule::TYPE_PERCENTAGE,
            'trigger_event' => 'payment.cleared',
            'config' => ['rate' => 0.10, 'base_field' => 'amount'],
            'priority' => 0,
            'active' => true,
        ]);

        CommissionPlanRule::query()->create([
            'commission_plan_id' => $plan->id,
            'role' => CommissionPlanRule::ROLE_FRONTER,
            'rule_type' => CommissionPlanRule::TYPE_PERCENTAGE,
            'trigger_event' => 'payment.cleared',
            'config' => ['rate' => 0.02, 'base_field' => 'amount'],
            'priority' => 0,
            'active' => true,
        ]);

        User::query()
            ->whereIn('role', [UserRole::Closer->value, UserRole::Fronter->value])
            ->each(function (User $u) use ($plan): void {
                CommissionAssignment::query()->create([
                    'user_id' => $u->id,
                    'commission_plan_id' => $plan->id,
                    'effective_from' => now()->subMonth()->toDateString(),
                ]);
            });

        return $plan;
    }

    /**
     * For each cleared payment, write a `payment.cleared` commission_event and
     * the per-role calculations (closer + fronter, plus split between
     * additional_closers when present). Some calculations roll up into the
     * paid-out previous-month period; the rest into the open this-month period.
     *
     * @param  array{admin: User, supervisor: User, qa: User, closers: list<User>, fronters: list<User>}  $users
     */
    private function createCommissionEventsAndCalculations(
        \Illuminate\Support\Collection $payments,
        \Illuminate\Support\Collection $deals,
        CommissionPlan $plan,
        array $users,
    ): void {
        $closerRule = CommissionPlanRule::query()
            ->where('commission_plan_id', $plan->id)
            ->where('role', CommissionPlanRule::ROLE_CLOSER)
            ->firstOrFail();
        $fronterRule = CommissionPlanRule::query()
            ->where('commission_plan_id', $plan->id)
            ->where('role', CommissionPlanRule::ROLE_FRONTER)
            ->firstOrFail();

        $dealsById = $deals->keyBy('id');

        $payments
            ->where('status', Payment::STATUS_SUCCEEDED)
            ->each(function (Payment $payment) use ($closerRule, $fronterRule, $dealsById): void {
                if (! $payment->deal_id) {
                    return;
                }
                $deal = $dealsById->get($payment->deal_id);
                if (! $deal) {
                    return;
                }

                $event = CommissionEvent::query()->create([
                    'event_type' => 'payment.cleared',
                    'source_entity_type' => Payment::class,
                    'source_entity_id' => $payment->id,
                    'payload' => [
                        'amount' => (string) $payment->amount,
                        'currency' => $payment->currency,
                        'deal_id' => $deal->id,
                        'closer_id' => $deal->agent_id,
                        'fronter_id' => $deal->fronter_id,
                    ],
                    'idempotency_key' => "payment.cleared:{$payment->id}",
                    'occurred_at' => $payment->cleared_at,
                ]);

                $base = (float) $payment->amount;
                $period = Carbon::parse($payment->cleared_at)->startOfMonth()->toDateString();
                $isPrevMonth = Carbon::parse($payment->cleared_at)->isLastMonth();

                // Primary closer split — full 10% if no co-closer, else 70/30.
                $closerSplit = $deal->additional_closer_ids ? 0.7 : 1.0;

                CommissionCalculation::query()->create([
                    'commission_event_id' => $event->id,
                    'user_id' => $deal->agent_id,
                    'commission_plan_rule_id' => $closerRule->id,
                    'role' => 'closer',
                    'base_amount' => $base,
                    'rate' => 0.10 * $closerSplit,
                    'amount' => round($base * 0.10 * $closerSplit, 2),
                    'explanation' => [
                        'rule' => 'Standard 10/2 closer',
                        'base' => $base,
                        'rate' => 0.10 * $closerSplit,
                        'split' => $closerSplit < 1 ? 'co-closed' : 'sole-closer',
                    ],
                    'status' => $isPrevMonth ? 'paid' : 'payable',
                    'payable_period' => $period,
                ]);

                // Co-closer share, if any
                foreach ($deal->additional_closer_ids ?? [] as $extra) {
                    $share = (float) ($extra['split_pct'] ?? 30) / 100;
                    CommissionCalculation::query()->create([
                        'commission_event_id' => $event->id,
                        'user_id' => $extra['user_id'],
                        'commission_plan_rule_id' => $closerRule->id,
                        'role' => 'closer',
                        'base_amount' => $base,
                        'rate' => 0.10 * $share,
                        'amount' => round($base * 0.10 * $share, 2),
                        'explanation' => ['rule' => 'Standard 10/2 closer', 'split' => 'additional'],
                        'status' => $isPrevMonth ? 'paid' : 'payable',
                        'payable_period' => $period,
                    ]);
                }

                if ($deal->fronter_id) {
                    CommissionCalculation::query()->create([
                        'commission_event_id' => $event->id,
                        'user_id' => $deal->fronter_id,
                        'commission_plan_rule_id' => $fronterRule->id,
                        'role' => 'fronter',
                        'base_amount' => $base,
                        'rate' => 0.02,
                        'amount' => round($base * 0.02, 2),
                        'explanation' => [
                            'rule' => 'Standard 10/2 fronter',
                            'base' => $base,
                            'rate' => 0.02,
                        ],
                        'status' => $isPrevMonth ? 'paid' : 'payable',
                        'payable_period' => $period,
                    ]);
                }
            });
    }

    /**
     * Roll calculations into per-period payouts:
     *   - last month: status=paid (already disbursed)
     *   - this month: status=draft (open for review)
     *
     * @param  array{admin: User, supervisor: User, qa: User, closers: list<User>, fronters: list<User>}  $users
     */
    private function createCommissionPayouts(array $users, Tenant $tenant): void
    {
        $eligible = array_merge($users['closers'], $users['fronters']);
        $lastStart = now()->subMonth()->startOfMonth();
        $lastEnd = now()->subMonth()->endOfMonth();
        $currentStart = now()->startOfMonth();
        $currentEnd = now()->endOfMonth();

        foreach ($eligible as $user) {
            $lastCalcs = CommissionCalculation::query()
                ->where('user_id', $user->id)
                ->where('status', 'paid')
                ->where('payable_period', $lastStart->toDateString())
                ->get();

            if ($lastCalcs->isNotEmpty()) {
                $earned = (float) $lastCalcs->sum('amount');
                CommissionPayout::query()->create([
                    'user_id' => $user->id,
                    'period_start' => $lastStart->toDateString(),
                    'period_end' => $lastEnd->toDateString(),
                    'total_earned' => $earned,
                    'total_reversed' => 0,
                    'total_adjustments' => 0,
                    'net_payable' => $earned,
                    'currency' => 'USD',
                    'status' => 'paid',
                    'approved_by_id' => $users['admin']->id,
                    'approved_at' => $lastEnd->copy()->addDays(2),
                    'paid_at' => $lastEnd->copy()->addDays(5),
                    'payment_reference' => 'BATCH-'.$lastEnd->format('Ymd'),
                    'calculation_ids' => $lastCalcs->pluck('id')->all(),
                ]);
            }

            $currentCalcs = CommissionCalculation::query()
                ->where('user_id', $user->id)
                ->where('status', 'payable')
                ->where('payable_period', $currentStart->toDateString())
                ->get();

            if ($currentCalcs->isNotEmpty()) {
                $earned = (float) $currentCalcs->sum('amount');
                CommissionPayout::query()->create([
                    'user_id' => $user->id,
                    'period_start' => $currentStart->toDateString(),
                    'period_end' => $currentEnd->toDateString(),
                    'total_earned' => $earned,
                    'total_reversed' => 0,
                    'total_adjustments' => 0,
                    'net_payable' => $earned,
                    'currency' => 'USD',
                    'status' => 'draft',
                    'calculation_ids' => $currentCalcs->pluck('id')->all(),
                ]);
            }
        }
    }

    /**
     * Sprinkle "now-ish" activity into the seeded narrative so the live
     * dashboard surfaces have something to read.
     *
     * Without this, every seeded transition / payment is dated 1+ days
     * back (because the deal narrative implies a multi-day journey from
     * new → qualified → pitch → close), and:
     *   - /api/dashboard/sparkline (last 24h) shows zero bars
     *   - /api/dashboard/activity   (last 60min) shows no events
     *   - leaderboard's "today's deals" stays at 0 across the floor
     *
     * Strategy:
     *   - Bump 6 random closed_won deals' closed_at into the last 4h so
     *     "deals today" and "today's revenue" rise on the leaderboard.
     *   - Insert 12 fresh `to_stage='qualified'` transitions on
     *     contacted leads, scattered across the last 4h, attributed to
     *     active fronters. These light up the sparkline and the ticker.
     *   - Insert 3 fresh `cleared_at` payments in the last 30m so the
     *     ticker has a "charged" event near the top.
     *
     * @param  array{admin: User, supervisor: User, qa: User, closers: list<User>, fronters: list<User>}  $users
     */
    private function scatterLiveActivity(
        \Illuminate\Support\Collection $deals,
        \Illuminate\Support\Collection $payments,
        array $users,
    ): void {
        $now = now();

        // 0. Bump 5 in-progress deals into negotiating so the dashboard's
        //    "Sent to Verify" card carries a non-zero number. Negotiating
        //    is the seam between pitch and close on this floor.
        $deals
            ->filter(fn (Deal $d) => $d->stage === DealStage::PitchPresented)
            ->shuffle()
            ->take(5)
            ->each(function (Deal $d) use ($now): void {
                $changedAt = $now->copy()->subMinutes(random_int(15, 360));
                $d->update([
                    'stage' => DealStage::Negotiating->value,
                    'previous_stage' => DealStage::PitchPresented->value,
                    'stage_changed_at' => $changedAt,
                ]);
                DealStageTransition::query()->create([
                    'deal_id' => $d->id,
                    'changed_by_id' => $d->agent_id,
                    'from_stage' => DealStage::PitchPresented->value,
                    'to_stage' => DealStage::Negotiating->value,
                    'reason' => 'Sent to verification',
                    'occurred_at' => $changedAt,
                ]);
            });

        // 1. Promote 6 closed_won deals to "closed today"
        $deals
            ->filter(fn (Deal $d) => $d->stage === DealStage::ClosedWon)
            ->shuffle()
            ->take(6)
            ->each(function (Deal $d) use ($now): void {
                $closedAt = $now->copy()->subMinutes(random_int(5, 240));
                $d->update([
                    'closed_at' => $closedAt,
                    'stage_changed_at' => $closedAt,
                ]);

                // Track the close as a fresh terminal transition so
                // /api/dashboard/activity surfaces it as a "closed" event.
                DealStageTransition::query()->create([
                    'deal_id' => $d->id,
                    'changed_by_id' => $d->agent_id,
                    'from_stage' => DealStage::Negotiating->value,
                    'to_stage' => DealStage::ClosedWon->value,
                    'reason' => 'Contract signed (live)',
                    'occurred_at' => $closedAt,
                ]);
            });

        // 2. Live transfers — qualified transitions scattered across last 4h.
        $fronters = $users['fronters'];
        $contactedLeads = Lead::query()
            ->where('status', LeadStatus::Contacted->value)
            ->inRandomOrder()
            ->take(12)
            ->get();

        foreach ($contactedLeads as $lead) {
            $fronter = $fronters[array_rand($fronters)];
            $occurred = $now->copy()->subMinutes(random_int(2, 240));

            // We synthesize a deal-less transition row attributed to a
            // contacted lead by reusing an existing deal id — the
            // sparkline counts rows by to_stage / occurred_at and the
            // ticker only joins the agent. Pick any deal owned by this
            // fronter's adjacent closer for the fk integrity, since the
            // table requires deal_id NOT NULL.
            $hostDeal = $deals->random();

            DealStageTransition::query()->create([
                'deal_id' => $hostDeal->id,
                'changed_by_id' => $fronter->id,
                'from_stage' => DealStage::Contacted->value,
                'to_stage' => DealStage::Qualified->value,
                'reason' => 'Live transfer to closer',
                'occurred_at' => $occurred,
            ]);
        }

        // 3. Three "just cleared" payments — drives a charged event.
        $payments
            ->where('status', Payment::STATUS_SUCCEEDED)
            ->shuffle()
            ->take(3)
            ->each(function (Payment $p) use ($now): void {
                $p->update([
                    'cleared_at' => $now->copy()->subMinutes(random_int(1, 30)),
                ]);
            });
    }

    /**
     * Layer the timeshare-listing domain on top of the existing
     * sales-floor narrative.
     *
     * For every closed_won deal, the seeder creates:
     *   - a Property (the owner's timeshare itself)
     *   - a Listing (the marketed offering of one or more weeks)
     *   - 3-5 PartnerSiteListing rows (Airbnb / Vrbo / RedWeek / etc)
     *   - a ComplianceRecording on the agent's most recent call
     *
     * Plus:
     *   - 5 PartnerSite rows (the channels we push to)
     *   - 6-10 RentalInquiry rows on live listings
     *   - A handful of bookings get listing_id + renter-side fields
     *   - 2 RefundCase rows + 1 ChargebackCase row
     *
     * Existing deals are also augmented in-place with listing-fee +
     * agreement-status + TCPA/verification fields. This is what makes
     * /api/dashboard/* surface "listings live", "time-to-live",
     * "compliance pass rate", etc — the metrics the dashboard
     * rebuild (D7) will read.
     *
     * @param  array{admin: User, supervisor: User, qa: User, closers: list<User>, fronters: list<User>}  $users
     * @return array{
     *     properties: int,
     *     listings: int,
     *     live_listings: int,
     *     partner_sites: int,
     *     partner_pushes: int,
     *     inquiries: int,
     *     compliance_recordings: int,
     *     compliance_failed: int,
     *     refund_cases: int,
     *     chargeback_cases: int,
     * }
     */
    private function createTimeshareDomain(
        \Illuminate\Support\Collection $leads,
        \Illuminate\Support\Collection $deals,
        \Illuminate\Support\Collection $bookings,
        \Illuminate\Support\Collection $calls,
        array $users,
    ): array {
        // 1. Partner sites — fixed catalog, tenant-scoped via TenantScoped.
        $partnerSites = $this->createPartnerSites();

        // 2. Augment closed_won + pitched deals with listing-agreement fields.
        $this->augmentDealsForListingAgreements($deals);

        // 3. Properties — one per closed_won + pitch_presented lead.
        $properties = $this->createPropertiesForOwners($leads, $users);

        // 4. Listings — one per closed_won deal, with random partner pushes.
        [$listings, $liveListings, $partnerPushes] = $this->createListingsAndDistribution(
            $deals, $properties, $partnerSites,
        );

        // 5. Rental inquiries on a subset of live listings.
        $inquiries = $this->createRentalInquiries($liveListings, $partnerSites, $users);

        // 6. Renter-side bookings: graft listing/renter info onto a few
        //    existing bookings so the renter pipeline shows progress.
        $this->graftRenterFieldsOntoBookings($bookings, $listings);

        // 7. Compliance recordings on closed_won deals' calls.
        [$recordings, $failedRecordings] = $this->createComplianceRecordings(
            $deals, $calls,
        );

        // 8. Refund + chargeback cases.
        [$refundCases, $chargebackCases] = $this->createComplianceCases(
            $deals, $users,
        );

        return [
            'properties' => $properties->count(),
            'listings' => $listings->count(),
            'live_listings' => $liveListings->count(),
            'partner_sites' => $partnerSites->count(),
            'partner_pushes' => $partnerPushes,
            'inquiries' => $inquiries,
            'compliance_recordings' => $recordings,
            'compliance_failed' => $failedRecordings,
            'refund_cases' => $refundCases,
            'chargeback_cases' => $chargebackCases,
        ];
    }

    /** @return \Illuminate\Support\Collection<int, PartnerSite> */
    private function createPartnerSites(): \Illuminate\Support\Collection
    {
        // Catalog of the major timeshare-rental marketing channels. Real
        // operators would have credentials and an API endpoint per
        // site; for the demo we leave api_endpoint null and config
        // empty so the frontend renders "not configured".
        $catalog = [
            ['name' => 'Airbnb',                'slug' => 'airbnb',     'cost' => 0.00],
            ['name' => 'Vrbo',                  'slug' => 'vrbo',       'cost' => 0.00],
            ['name' => 'RedWeek',               'slug' => 'redweek',    'cost' => 59.95],
            ['name' => 'SellMyTimeshareNow',    'slug' => 'smtn',       'cost' => 79.00],
            ['name' => 'Timeshares.com',        'slug' => 'timeshares', 'cost' => 49.95],
        ];

        $sites = collect();
        foreach ($catalog as $row) {
            $sites->push(PartnerSite::query()->create([
                'name' => $row['name'],
                'slug' => $row['slug'],
                'is_active' => true,
                'our_cost_per_listing' => $row['cost'],
                'config' => null,
            ]));
        }

        return $sites;
    }

    /**
     * Update existing deals in-place with listing-agreement metadata.
     *
     * Maps DealStage → AgreementStatus and fills in listing_fee,
     * payment_status, TCPA + verification markers, and the refund
     * cooling-off window. Idempotent: re-running just overwrites.
     */
    private function augmentDealsForListingAgreements(\Illuminate\Support\Collection $deals): void
    {
        foreach ($deals as $deal) {
            /** @var Deal $deal */
            $stage = $deal->stage->value;

            // Fee — anchored to the existing total_value so the dashboard
            // pipeline numbers stay consistent with the per-deal money.
            $listingFee = (float) $deal->total_value > 0
                ? (float) $deal->total_value
                : random_int(2400, 8500);

            // Sales-side stage → fulfillment-side status.
            $agreementStatus = match ($stage) {
                'closed_won' => AgreementStatus::Live->value,
                'closed_lost' => AgreementStatus::Cancelled->value,
                'negotiating' => AgreementStatus::PaidPendingVerification->value,
                'pitch_presented' => AgreementStatus::VerbalYes->value,
                'qualified' => AgreementStatus::Pitched->value,
                default => AgreementStatus::Pitched->value,
            };

            $isPaid = in_array($stage, ['closed_won', 'negotiating'], true);
            $paymentStatus = $isPaid ? 'paid' : 'pending';

            // TCPA + verification: ~95% of paid agreements have full
            // disclosures; 5% are flagged for review.
            $tcpaCaptured = $isPaid && random_int(1, 100) <= 95;
            $verified = $stage === 'closed_won' && random_int(1, 100) <= 90;

            // Refund window: 3-day cooling-off from agreement_signed_at.
            $signedAt = $stage === 'closed_won' ? $deal->closed_at : null;
            $refundExpires = $signedAt
                ? Carbon::parse($signedAt)->addDays(3)
                : null;

            $deal->update([
                'listing_fee' => $listingFee,
                'listing_fee_collected' => $isPaid ? $listingFee : 0,
                'payment_status' => $paymentStatus,
                'agreement_status' => $agreementStatus,
                'listing_term_months' => 12,
                'term_expires_at' => $signedAt
                    ? Carbon::parse($signedAt)->addYear()
                    : null,
                'refund_window_expires_at' => $refundExpires,
                'tcpa_disclosure_completed' => $tcpaCaptured,
                'tcpa_disclosure_completed_at' => $tcpaCaptured && $signedAt
                    ? Carbon::parse($signedAt)
                    : null,
                'verification_call_completed' => $verified,
                'verification_call_completed_at' => $verified && $signedAt
                    ? Carbon::parse($signedAt)->addHours(random_int(2, 48))
                    : null,
                'agreement_signed_at' => $signedAt?->toDateString(),
            ]);
        }
    }

    /**
     * One Property per pitch_presented + closed_won lead. Resort + week
     * details come from the lead's region so consent/DNC/calling-window
     * narrative stays internally consistent.
     *
     * @param  array{admin: User, supervisor: User, qa: User, closers: list<User>, fronters: list<User>}  $users
     * @return \Illuminate\Support\Collection<int, Property>
     */
    private function createPropertiesForOwners(\Illuminate\Support\Collection $leads, array $users): \Illuminate\Support\Collection
    {
        $eligibleStatuses = [
            LeadStatus::PitchPresented->value,
            LeadStatus::ClosedWon->value,
        ];

        $resortByState = [
            'FL' => ['Wyndham Bonnet Creek', 'Marriott Grande Vista', 'Hilton Tuscany Village'],
            'UT' => ['Westgate Park City', 'Marriott MountainSide'],
            'HI' => ['Disney Aulani', 'Marriott Ko Olina', 'Hilton Hawaiian Village'],
            'NV' => ['Hilton Grand Vacations on the Strip', 'Westgate Las Vegas', 'Marriott Grand Chateau'],
        ];

        $brandFromName = static function (string $name): string {
            return match (true) {
                str_contains($name, 'Wyndham') => 'Wyndham',
                str_contains($name, 'Marriott') => 'Marriott',
                str_contains($name, 'Hilton') => 'Hilton',
                str_contains($name, 'Westgate') => 'Westgate',
                str_contains($name, 'Disney') => 'Disney Vacation Club',
                default => 'Independent',
            };
        };

        $verifier = $users['supervisor'];
        $properties = collect();

        $leads
            ->filter(fn (Lead $l) => in_array($l->status->value, $eligibleStatuses, true))
            ->each(function (Lead $lead) use (&$properties, $resortByState, $brandFromName, $verifier): void {
                $resortName = $resortByState[$lead->state ?? 'FL'][array_rand($resortByState[$lead->state ?? 'FL'])]
                    ?? 'Unknown Resort';

                $ownershipType = collect([
                    PropertyOwnershipType::FixedWeek,
                    PropertyOwnershipType::FloatingWeek,
                    PropertyOwnershipType::Points,
                ])->random();

                // ~85% of pitched/won leads have verified ownership;
                // the rest are still in verification limbo.
                $verified = random_int(1, 100) <= 85;

                $properties->push(Property::query()->create([
                    'owner_id' => $lead->id,
                    'resort_name' => $resortName,
                    'resort_brand' => $brandFromName($resortName),
                    'location_city' => $lead->city ?? 'Orlando',
                    'location_state' => $lead->state ?? 'FL',
                    'location_country' => 'USA',
                    'unit_number' => 'U-'.random_int(100, 999),
                    'bedrooms' => random_int(1, 3),
                    'sleeps' => random_int(2, 8),
                    'view_type' => collect(['ocean', 'garden', 'mountain', 'pool'])->random(),
                    'ownership_type' => $ownershipType->value,
                    'points_balance' => $ownershipType === PropertyOwnershipType::Points
                        ? random_int(50_000, 250_000)
                        : null,
                    'fixed_week_number' => $ownershipType === PropertyOwnershipType::FixedWeek
                        ? random_int(1, 52)
                        : null,
                    'season' => collect([
                        PropertySeason::Platinum, PropertySeason::Gold,
                        PropertySeason::Silver, PropertySeason::Red,
                    ])->random()->value,
                    'ownership_verified' => $verified,
                    'ownership_verified_at' => $verified
                        ? now()->subDays(random_int(2, 60))
                        : null,
                    'ownership_verified_by' => $verified ? $verifier->id : null,
                    'rental_allowed_by_resort' => $verified && random_int(1, 100) <= 92,
                ]));
            });

        return $properties;
    }

    /**
     * Listings — one per closed_won deal, plus per-site distribution.
     *
     * @return array{0: \Illuminate\Support\Collection<int, Listing>, 1: \Illuminate\Support\Collection<int, Listing>, 2: int}
     */
    private function createListingsAndDistribution(
        \Illuminate\Support\Collection $deals,
        \Illuminate\Support\Collection $properties,
        \Illuminate\Support\Collection $partnerSites,
    ): array {
        $propsByOwner = $properties->keyBy('owner_id');
        $listings = collect();
        $liveListings = collect();
        $partnerPushes = 0;

        foreach ($deals as $deal) {
            /** @var Deal $deal */
            if ($deal->stage !== DealStage::ClosedWon) {
                continue;
            }
            $property = $propsByOwner[$deal->lead_id] ?? null;
            if (! $property) {
                continue;
            }

            // Asking price + payouts — anchored to the deal's total
            // for narrative consistency.
            $asking = (float) $deal->total_value > 0 ? (float) $deal->total_value : random_int(1500, 6500);
            $ownerPayout = round($asking * 0.65, 2);

            // Most listings are Live; a few are still Pending; a couple
            // booked already; one or two unrented expired.
            $statusRoll = random_int(1, 100);
            $status = match (true) {
                $statusRoll <= 65 => ListingStatus::Live,
                $statusRoll <= 78 => ListingStatus::InquiryReceived,
                $statusRoll <= 85 => ListingStatus::PendingDistribution,
                $statusRoll <= 92 => ListingStatus::Booked,
                default => ListingStatus::UnrentedExpired,
            };

            // Listing window: check-in is somewhere in the next 4-12
            // months; the listing window expires the day before check-in.
            $checkIn = now()->addMonths(random_int(2, 10))->addDays(random_int(0, 6));
            $checkOut = $checkIn->copy()->addDays(7);
            $expiresAt = $checkIn->copy()->subDay();

            $wentLive = $status->isLive() || $status === ListingStatus::Booked
                ? Carbon::parse($deal->closed_at ?? now())->addHours(random_int(2, 96))
                : null;

            $listing = Listing::query()->create([
                'property_id' => $property->id,
                'deal_id' => $deal->id,
                'check_in_date' => $checkIn->toDateString(),
                'check_out_date' => $checkOut->toDateString(),
                'asking_price' => $asking,
                'reserve_price' => round($asking * 0.85, 2),
                'owner_payout' => $ownerPayout,
                'our_commission_pct' => 15.00,
                'status' => $status->value,
                'went_live_at' => $wentLive,
                'expires_at' => $expiresAt,
                'marketing_description' => "Beautiful {$property->bedrooms}-bedroom unit at {$property->resort_name}. Sleeps {$property->sleeps}. Prime week — book early.",
                'photos' => [
                    'https://picsum.photos/seed/'.$property->id.'/800/600',
                    'https://picsum.photos/seed/'.$property->id.'-2/800/600',
                ],
            ]);
            $listings->push($listing);
            if ($status->isLive() || $status === ListingStatus::Booked) {
                $liveListings->push($listing);
            }

            // Distribute to 3-5 random partner sites.
            $distSites = $partnerSites->shuffle()->take(random_int(3, 5));
            foreach ($distSites as $site) {
                /** @var PartnerSite $site */
                // Per-site status mostly matches the listing's status,
                // but we sprinkle some site-level rejections / pauses.
                $siteRoll = random_int(1, 100);
                $siteStatus = match (true) {
                    $status === ListingStatus::PendingDistribution => PartnerSiteListingStatus::Pending,
                    $siteRoll <= 78 => PartnerSiteListingStatus::Live,
                    $siteRoll <= 88 => PartnerSiteListingStatus::Pending,
                    $siteRoll <= 96 => PartnerSiteListingStatus::Paused,
                    default => PartnerSiteListingStatus::Rejected,
                };

                $pushedAt = $wentLive
                    ? $wentLive->copy()->subHours(random_int(1, 24))
                    : now()->subHours(random_int(1, 48));
                $siteWentLive = $siteStatus === PartnerSiteListingStatus::Live
                    ? $pushedAt->copy()->addMinutes(random_int(15, 720))
                    : null;

                PartnerSiteListing::query()->create([
                    'listing_id' => $listing->id,
                    'partner_site_id' => $site->id,
                    'external_listing_id' => $site->slug.'-'.Str::lower(Str::random(10)),
                    'external_url' => 'https://'.$site->slug.'.example/listings/'.Str::lower(Str::random(8)),
                    'status' => $siteStatus->value,
                    'rejection_reason' => $siteStatus === PartnerSiteListingStatus::Rejected
                        ? 'Photos do not meet quality requirements'
                        : null,
                    'view_count' => $siteStatus->isHealthy() ? random_int(15, 320) : 0,
                    'inquiry_count' => $siteStatus->isHealthy() ? random_int(0, 8) : 0,
                    'pushed_at' => $pushedAt,
                    'went_live_at' => $siteWentLive,
                    'last_synced_at' => now()->subMinutes(random_int(5, 240)),
                ]);
                $partnerPushes++;
            }
        }

        return [$listings, $liveListings, $partnerPushes];
    }

    /**
     * @param  array{admin: User, supervisor: User, qa: User, closers: list<User>, fronters: list<User>}  $users
     */
    private function createRentalInquiries(
        \Illuminate\Support\Collection $liveListings,
        \Illuminate\Support\Collection $partnerSites,
        array $users,
    ): int {
        if ($liveListings->isEmpty()) {
            return 0;
        }

        $renterNames = [
            'Jenna Patel', 'Mike OConnor', 'Lara Schmidt', 'Tomás Reyes',
            'Aisha Khan', 'Daniel Wu', 'Eva Petrov', 'Brian Cole',
            'Riya Sharma', 'Carlos Mendez',
        ];
        $handlers = array_merge($users['closers'], $users['fronters']);
        $count = 0;

        // Inquiries on roughly half the live listings; a few listings
        // get multiple inquiries.
        $inquiryListings = $liveListings->shuffle()->take((int) ceil($liveListings->count() * 0.5));

        foreach ($inquiryListings as $listing) {
            /** @var Listing $listing */
            $perListing = random_int(1, 3);
            for ($i = 0; $i < $perListing; $i++) {
                $name = $renterNames[array_rand($renterNames)];
                $statusRoll = random_int(1, 100);
                $status = match (true) {
                    $statusRoll <= 25 => RentalInquiryStatus::New,
                    $statusRoll <= 55 => RentalInquiryStatus::Responded,
                    $statusRoll <= 75 => RentalInquiryStatus::Negotiating,
                    $statusRoll <= 92 => RentalInquiryStatus::Booked,
                    default => RentalInquiryStatus::Lost,
                };

                $createdMinutesAgo = random_int(15, 60 * 24 * 7);
                $respondedAt = $status === RentalInquiryStatus::New
                    ? null
                    : now()->subMinutes(max(1, $createdMinutesAgo - random_int(5, 120)));

                RentalInquiry::query()->create([
                    'listing_id' => $listing->id,
                    'partner_site_id' => $partnerSites->random()->id,
                    'renter_name' => $name,
                    'renter_email' => Str::lower(str_replace(' ', '.', $name)).'@example.com',
                    'renter_phone' => '+1'.random_int(2000000000, 9999999999),
                    'requested_check_in' => $listing->check_in_date,
                    'requested_check_out' => $listing->check_out_date,
                    'offered_amount' => round((float) $listing->asking_price * (random_int(80, 105) / 100), 2),
                    'message' => 'Interested in your listing for our family vacation. Are these dates flexible?',
                    'status' => $status->value,
                    'handled_by' => $status !== RentalInquiryStatus::New
                        ? $handlers[array_rand($handlers)]->id
                        : null,
                    'responded_at' => $respondedAt,
                    'created_at' => now()->subMinutes($createdMinutesAgo),
                    'updated_at' => $respondedAt ?? now()->subMinutes($createdMinutesAgo),
                ]);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Graft listing-side fields onto ~half the existing bookings so the
     * new "renter rented an owner's week" view has data while leaving
     * the legacy bookings intact.
     */
    private function graftRenterFieldsOntoBookings(
        \Illuminate\Support\Collection $bookings,
        \Illuminate\Support\Collection $listings,
    ): void {
        if ($listings->isEmpty()) {
            return;
        }

        $renterNames = [
            'Olivia Bennett', 'Noah Tanaka', 'Sophia Hassan', 'Liam Park',
            'Mia Rodriguez', 'Ethan Becker', 'Amara Singh', 'Jacob Liu',
        ];

        $listingsByDealId = $listings->keyBy('deal_id');

        $bookings->each(function (Booking $b) use ($listingsByDealId, $renterNames): void {
            $listing = $listingsByDealId->get($b->deal_id);
            if (! $listing) {
                return;
            }

            $name = $renterNames[array_rand($renterNames)];
            $totalPrice = (float) $b->total_price;
            $ourCommission = round($totalPrice * 0.15, 2);
            $ownerPayout = round($totalPrice - $ourCommission, 2);

            // Owner gets notified within an hour of booking — almost
            // always. Sets up the dashboard's "owner_notified Y/N"
            // service-quality column.
            $notified = random_int(1, 100) <= 90;

            $b->update([
                'listing_id' => $listing->id,
                'renter_name' => $name,
                'renter_email' => Str::lower(str_replace(' ', '.', $name)).'@example.com',
                'renter_phone' => '+1'.random_int(2000000000, 9999999999),
                'owner_payout' => $ownerPayout,
                'our_commission' => $ourCommission,
                'owner_notified_at' => $notified
                    ? Carbon::parse($b->confirmed_at)->addMinutes(random_int(5, 90))
                    : null,
                'payment_status' => match ($b->status) {
                    Booking::STATUS_PAID => 'paid_in_full',
                    Booking::STATUS_CONFIRMED => 'deposit_paid',
                    Booking::STATUS_CANCELLED => 'refunded',
                    default => 'pending',
                },
            ]);
        });
    }

    /**
     * @return array{0: int, 1: int} count of recordings, count of failed
     */
    private function createComplianceRecordings(
        \Illuminate\Support\Collection $deals,
        \Illuminate\Support\Collection $calls,
    ): array {
        $callsByLead = $calls->groupBy('lead_id');
        $count = 0;
        $failed = 0;

        foreach ($deals as $deal) {
            /** @var Deal $deal */
            if ($deal->stage !== DealStage::ClosedWon) {
                continue;
            }
            $leadCalls = $callsByLead->get($deal->lead_id, collect());
            if ($leadCalls->isEmpty()) {
                continue;
            }
            // Pick the most recent completed call as "the closing call".
            $call = $leadCalls
                ->filter(fn (Call $c) => $c->ended_at !== null)
                ->sortByDesc('ended_at')
                ->first();
            if (! $call) {
                continue;
            }

            // Disclosure capture distribution — most pass, some pending,
            // a few fail. Tied to whether the deal has TCPA + verification
            // already marked complete (set in augmentDealsForListingAgreements).
            $tcpa = $deal->tcpa_disclosure_completed ?? true;
            $verified = $deal->verification_call_completed ?? true;

            // 5 disclosures total. If TCPA passed and verified, all
            // five are usually true. If TCPA passed but not verified,
            // 1-2 are missing. If TCPA missing entirely, 3+ missing.
            $missCount = match (true) {
                ! $tcpa => random_int(3, 5),
                ! $verified => random_int(1, 2),
                default => random_int(0, 1),
            };

            $captures = [
                'tcpa_consent_captured' => true,
                'recording_disclosure_made' => true,
                'no_guarantee_disclosure_made' => true,
                'refund_policy_disclosure_made' => true,
                'total_fee_stated_clearly' => true,
            ];
            $keys = array_keys($captures);
            shuffle($keys);
            for ($i = 0; $i < $missCount; $i++) {
                $captures[$keys[$i]] = false;
            }

            $allCaptured = ! in_array(false, $captures, true);
            $statusRoll = random_int(1, 100);
            $status = match (true) {
                $allCaptured && $statusRoll <= 90 => ComplianceStatus::Passed,
                $allCaptured => ComplianceStatus::PendingReview,
                $missCount >= 3 => ComplianceStatus::Failed,
                $statusRoll <= 60 => ComplianceStatus::PendingReview,
                $statusRoll <= 85 => ComplianceStatus::Failed,
                default => ComplianceStatus::FlaggedForAudit,
            };

            ComplianceRecording::query()->create([
                'call_id' => $call->id,
                'deal_id' => $deal->id,
                'user_id' => $deal->agent_id,
                'tcpa_consent_captured' => $captures['tcpa_consent_captured'],
                'recording_disclosure_made' => $captures['recording_disclosure_made'],
                'no_guarantee_disclosure_made' => $captures['no_guarantee_disclosure_made'],
                'refund_policy_disclosure_made' => $captures['refund_policy_disclosure_made'],
                'total_fee_stated_clearly' => $captures['total_fee_stated_clearly'],
                'disclosure_timestamps' => null,
                'compliance_status' => $status->value,
                'reviewed_by' => $status->isResolved() ? $deal->fronter_id : null,
                'reviewed_at' => $status->isResolved() ? now()->subDays(random_int(0, 30)) : null,
                'review_notes' => $status === ComplianceStatus::Failed
                    ? 'Closer skipped no-guarantee disclosure during pitch.'
                    : null,
            ]);
            $count++;
            if ($status === ComplianceStatus::Failed || $status === ComplianceStatus::FlaggedForAudit) {
                $failed++;
            }
        }

        return [$count, $failed];
    }

    /**
     * @param  array{admin: User, supervisor: User, qa: User, closers: list<User>, fronters: list<User>}  $users
     * @return array{0: int, 1: int} refund cases, chargeback cases
     */
    private function createComplianceCases(
        \Illuminate\Support\Collection $deals,
        array $users,
    ): array {
        $closedDeals = $deals
            ->filter(fn (Deal $d) => $d->stage === DealStage::ClosedWon)
            ->shuffle();

        if ($closedDeals->count() < 3) {
            return [0, 0];
        }

        // Two refund cases — one investigating (open), one denied (closed).
        $opener = $users['supervisor'];
        $candidates = $closedDeals->take(3);

        RefundCase::query()->create([
            'deal_id' => $candidates[0]->id,
            'opened_by' => $opener->id,
            'refund_amount' => $candidates[0]->total_value,
            'reason' => RefundReason::NoRenterFound->value,
            'owner_statement' => 'No renter inquiry in 60 days; would like a refund of the listing fee.',
            'status' => RefundCaseStatus::Investigating->value,
            'opened_at' => now()->subDays(random_int(2, 12)),
        ]);

        RefundCase::query()->create([
            'deal_id' => $candidates[1]->id,
            'opened_by' => $opener->id,
            'refund_amount' => $candidates[1]->total_value,
            'reason' => RefundReason::OwnerChangedMind->value,
            'owner_statement' => 'Decided to keep the week and use it personally.',
            'status' => RefundCaseStatus::Denied->value,
            'opened_at' => now()->subDays(random_int(20, 45)),
            'resolved_at' => now()->subDays(random_int(10, 19)),
        ]);

        // One chargeback — evidence_gathering, due in 5 days.
        ChargebackCase::query()->create([
            'deal_id' => $candidates[2]->id,
            'processor_case_id' => 'dp_'.Str::lower(Str::random(20)),
            'disputed_amount' => $candidates[2]->total_value,
            'reason_code' => '4855',                         // Goods/services not provided
            'respond_by_date' => now()->addDays(random_int(3, 7))->toDateString(),
            'status' => ChargebackCaseStatus::EvidenceGathering->value,
            'evidence_attached' => [
                'signed_contract_id' => null,
                'compliance_recording_ids' => [],
                'partner_site_screenshots' => [],
            ],
        ]);

        return [2, 1];
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /** @param array<string, int> $weights */
    private function weightedPick(array $weights): string
    {
        $total = array_sum($weights);
        $roll = random_int(1, $total);
        $cum = 0;
        foreach ($weights as $key => $w) {
            $cum += $w;
            if ($roll <= $cum) {
                return $key;
            }
        }

        return array_key_first($weights);
    }

    private function callStatusForDisposition(string $disposition): string
    {
        return match ($disposition) {
            CallDisposition::NoAnswer->value => CallStatus::NoAnswer->value,
            CallDisposition::Voicemail->value => CallStatus::Completed->value,
            CallDisposition::Busy->value => CallStatus::Busy->value,
            CallDisposition::AbandonedByDialer->value => CallStatus::Canceled->value,
            default => CallStatus::Completed->value,
        };
    }

    private function outcomeFromCallStatus(?string $status, ?string $disposition): string
    {
        $status ??= CallStatus::Completed->value;
        if ($disposition === CallDisposition::DncRequest->value) {
            return 'dnc_requested';
        }
        if ($disposition === CallDisposition::SaleClosed->value) {
            return 'sale';
        }
        if ($disposition === CallDisposition::Interested->value || $disposition === CallDisposition::PitchPresented->value) {
            return 'connected';
        }

        return match ($status) {
            CallStatus::Completed->value => 'connected',
            CallStatus::NoAnswer->value => 'no_answer',
            CallStatus::Busy->value => 'busy',
            CallStatus::Failed->value => 'failed',
            CallStatus::Canceled->value => 'abandoned',
            default => 'no_answer',
        };
    }

    private function sentimentFor(string $disposition): string
    {
        return match ($disposition) {
            CallDisposition::SaleClosed->value, CallDisposition::Interested->value, CallDisposition::PitchPresented->value => 'positive',
            CallDisposition::DncRequest->value, CallDisposition::NotInterested->value => 'negative',
            default => 'neutral',
        };
    }
}
