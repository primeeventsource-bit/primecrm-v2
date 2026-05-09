<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Shared\TenantContext;
use App\Modules\Booking\Domain\Models\Resort;
use App\Modules\Commission\Domain\Models\CommissionAssignment;
use App\Modules\Commission\Domain\Models\CommissionPlan;
use App\Modules\Commission\Domain\Models\CommissionPlanRule;
use App\Modules\Lead\Domain\Models\Lead;
use App\Modules\Tenant\Domain\Models\Tenant;
use App\Modules\Tenant\Domain\Models\User;
use App\Support\Enums\UserRole;
use Database\Factories\InventoryAvailabilityFactory;
use Database\Factories\InventoryUnitFactory;
use Database\Factories\LeadFactory;
use Database\Factories\ResortFactory;
use Database\Factories\TenantFactory;
use Database\Factories\UserFactory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Populated demo environment. Spins up:
 *   - 1 tenant (Prime Vacations Demo)
 *   - 1 admin, 1 supervisor, 1 QA, 5 agents (3 closers, 2 fronters)
 *   - 4 resorts (Westgate, Wyndham, Disney Vacation Club, Hilton)
 *     × 5 units each × 8 weeks of availability
 *   - 200 leads spread across statuses
 *   - 1 commission plan: closer 10% + fronter 2% on payment.cleared
 *   - The 5 agents assigned to the plan
 *
 * Run on a fresh database:
 *   php artisan migrate:fresh
 *   php artisan db:seed --class=DemoSeeder
 *
 * Login as admin:   admin@demo.test    / password
 * Login as agent:   closer1@demo.test  / password
 */
final class DemoSeeder extends Seeder
{
    public function run(): void
    {
        // Federal calling window first — without it, the dialer rejects everything.
        $this->call(BaseCallingWindowsSeeder::class);

        $tenant = $this->createTenant();
        app(TenantContext::class)->set($tenant->id);

        $admin = $this->createUsers($tenant);
        $resorts = $this->createResorts();
        $this->createInventory($resorts);
        $this->createLeads($tenant);
        $this->createCommissionPlan($admin);

        $this->command->info('--- Demo data seeded ---');
        $this->command->info("Tenant: {$tenant->id} ({$tenant->name})");
        $this->command->info('Sign in as admin@demo.test / password');
    }

    private function createTenant(): Tenant
    {
        return TenantFactory::new()->create([
            'name' => 'Prime Vacations Demo',
            'slug' => 'prime-vacations-demo',
            'status' => 'active',
            'timezone' => 'America/New_York',
        ]);
    }

    /**
     * @return User the admin (returned for commission plan ownership)
     */
    private function createUsers(Tenant $tenant): User
    {
        $hashed = Hash::make('password');

        $admin = User::query()->create([
            'tenant_id' => $tenant->id,
            'first_name' => 'Demo',
            'last_name' => 'Admin',
            'email' => 'admin@demo.test',
            'password' => $hashed,
            'role' => UserRole::Admin->value,
            'timezone' => 'America/New_York',
            'skills' => [],
            'is_panama_based' => false,
        ]);

        User::query()->create([
            'tenant_id' => $tenant->id,
            'first_name' => 'Demo',
            'last_name' => 'Supervisor',
            'email' => 'supervisor@demo.test',
            'password' => $hashed,
            'role' => UserRole::Supervisor->value,
            'timezone' => 'America/New_York',
            'skills' => [],
            'is_panama_based' => false,
        ]);

        User::query()->create([
            'tenant_id' => $tenant->id,
            'first_name' => 'Demo',
            'last_name' => 'QA',
            'email' => 'qa@demo.test',
            'password' => $hashed,
            'role' => UserRole::QA->value,
            'timezone' => 'America/New_York',
            'skills' => [],
            'is_panama_based' => false,
        ]);

        for ($i = 1; $i <= 3; $i++) {
            User::query()->create([
                'tenant_id' => $tenant->id,
                'first_name' => 'Closer',
                'last_name' => "#{$i}",
                'email' => "closer{$i}@demo.test",
                'password' => $hashed,
                'role' => UserRole::Closer->value,
                'timezone' => 'America/New_York',
                'skills' => ['english'],
                'is_panama_based' => false,
            ]);
        }
        for ($i = 1; $i <= 2; $i++) {
            User::query()->create([
                'tenant_id' => $tenant->id,
                'first_name' => 'Fronter',
                'last_name' => "#{$i}",
                'email' => "fronter{$i}@demo.test",
                'password' => $hashed,
                'role' => UserRole::Fronter->value,
                'timezone' => 'America/New_York',
                'skills' => ['english'],
                'is_panama_based' => false,
            ]);
        }

        return $admin;
    }

    /**
     * @return list<Resort>
     */
    private function createResorts(): array
    {
        $brands = [
            ['name' => 'Westgate Park City', 'brand' => 'Westgate', 'city' => 'Park City', 'state' => 'UT'],
            ['name' => 'Wyndham Destinations Orlando', 'brand' => 'Wyndham', 'city' => 'Orlando', 'state' => 'FL'],
            ['name' => 'Disney Vacation Club Aulani', 'brand' => 'Disney Vacation Club', 'city' => 'Kapolei', 'state' => 'HI'],
            ['name' => 'Hilton Grand Vacations Las Vegas', 'brand' => 'Hilton', 'city' => 'Las Vegas', 'state' => 'NV'],
        ];

        $resorts = [];
        foreach ($brands as $b) {
            $resorts[] = ResortFactory::new()->create([
                'name' => $b['name'],
                'brand' => $b['brand'],
                'slug' => str($b['name'])->slug()->value(),
                'country' => 'US',
                'state' => $b['state'],
                'city' => $b['city'],
            ]);
        }

        return $resorts;
    }

    /**
     * @param  list<Resort>  $resorts
     */
    private function createInventory(array $resorts): void
    {
        $unitTypes = [
            ['type' => 'studio', 'sleeps' => 2, 'price' => 1200],
            ['type' => '1br', 'sleeps' => 4, 'price' => 1800],
            ['type' => '2br', 'sleeps' => 6, 'price' => 2400],
            ['type' => '3br', 'sleeps' => 8, 'price' => 3200],
            ['type' => 'presidential', 'sleeps' => 10, 'price' => 5000],
        ];

        foreach ($resorts as $resort) {
            foreach ($unitTypes as $ut) {
                $unit = InventoryUnitFactory::new()->create([
                    'resort_id' => $resort->id,
                    'unit_type' => $ut['type'],
                    'sleeps' => $ut['sleeps'],
                ]);

                // 8 weeks of availability starting next week
                $checkIn = now()->next('Saturday')->addWeek();
                for ($w = 0; $w < 8; $w++) {
                    $start = $checkIn->copy()->addWeeks($w);
                    InventoryAvailabilityFactory::new()->create([
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
    }

    private function createLeads(Tenant $tenant): void
    {
        // Distribution: 60 new, 50 contacted, 30 qualified, 20 pitched, 20 closed_won, 20 closed_lost
        LeadFactory::new()->count(60)->create(['status' => 'new', 'priority' => 'normal', 'source' => 'web_form']);
        LeadFactory::new()->count(50)->create(['status' => 'contacted', 'priority' => 'normal', 'source' => 'facebook']);
        LeadFactory::new()->count(30)->create(['status' => 'qualified', 'priority' => 'high', 'source' => 'referral']);
        LeadFactory::new()->count(20)->create(['status' => 'pitch_presented', 'priority' => 'high', 'source' => 'inbound_call']);
        LeadFactory::new()->count(20)->create(['status' => 'closed_won', 'priority' => 'normal', 'source' => 'referral']);
        LeadFactory::new()->count(20)->create(['status' => 'closed_lost', 'priority' => 'low', 'source' => 'cold_list']);
    }

    private function createCommissionPlan(User $admin): void
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

        // Assign all closers and fronters
        User::query()
            ->whereIn('role', [UserRole::Closer->value, UserRole::Fronter->value])
            ->each(function (User $u) use ($plan): void {
                CommissionAssignment::query()->create([
                    'user_id' => $u->id,
                    'commission_plan_id' => $plan->id,
                    'effective_from' => now()->subMonth()->toDateString(),
                ]);
            });
    }
}
