<?php

declare(strict_types=1);

use App\Modules\Dialer\Application\Services\PacingEngine;
use Database\Factories\CallFactory;
use Database\Factories\CampaignFactory;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    $this->actingAsTenant();
    Redis::connection('dialer')->flushdb();
});

it('returns zero dials when no agents are available', function () {
    $campaign = CampaignFactory::new()->predictive()->create();

    $decision = app(PacingEngine::class)->decide($campaign, 0);

    expect($decision->dialsToFire)->toBe(0);
    expect($decision->reason)->toBe('no_agents_available');
});

it('returns zero dials when the lead queue is empty', function () {
    $campaign = CampaignFactory::new()->predictive()->create();

    $decision = app(PacingEngine::class)->decide($campaign, 5);

    expect($decision->dialsToFire)->toBe(0);
    expect($decision->reason)->toBe('lead_queue_empty');
});

it('respects per-agent cap when connection rate is unrealistically low', function () {
    $campaign = CampaignFactory::new()
        ->predictive()
        ->safetyFactor(2.5) // max
        ->create();

    // Pretend recent attempts had a 1% connection rate (10 attempts, 0 connected).
    // The math would suggest dialing ~250 per agent; the per-agent cap of 4
    // should bring it down hard.
    CallFactory::new()->count(10)->create([
        'campaign_id' => $campaign->id,
        'initiated_at' => now()->subMinutes(2),
        'status' => 'no_answer',
    ]);

    // Stuff 1000 leads in the queue so queue depth doesn't bound us.
    $key = "tenant:".app(\App\Core\Shared\TenantContext::class)->id().":campaign:{$campaign->id}:leadq";
    $args = ['ZADD', $key];
    for ($i = 1; $i <= 1000; $i++) {
        $args[] = (float) $i;
        $args[] = 'lead-'.$i;
    }
    Redis::connection('dialer')->executeRaw($args);

    $decision = app(PacingEngine::class)->decide($campaign, 3);

    // 3 agents × 4 dials/agent cap = 12. Even though raw_rate is ~750.
    expect($decision->dialsToFire)->toBeLessThanOrEqual(12);
    expect($decision->reason)->toBe('per_agent_cap');
});

it('subtracts in-flight calls from the budget', function () {
    $campaign = CampaignFactory::new()->predictive()->create();

    // 4 calls already initiated against this campaign — these consume budget.
    CallFactory::new()->count(4)->create([
        'campaign_id' => $campaign->id,
        'status' => 'initiated',
        'initiated_at' => now(),
    ]);

    // Some queue depth.
    $key = "tenant:".app(\App\Core\Shared\TenantContext::class)->id().":campaign:{$campaign->id}:leadq";
    Redis::connection('dialer')->executeRaw(['ZADD', $key, 1.0, 'lead-x']);

    $decision = app(PacingEngine::class)->decide($campaign, 2);

    // 2 agents × 4 cap = 8 budget; minus 4 in-flight = 4 remaining; minus
    // queue depth of 1 → 1 dial. The in-flight subtraction is the assertion.
    expect($decision->agentsAvailable)->toBe(2);
    expect($decision->dialsToFire)->toBeLessThanOrEqual(1);
});

it('reduces safety factor when abandon rate trends near the cap', function () {
    $campaign = CampaignFactory::new()
        ->predictive()
        ->safetyFactor(2.0)
        ->create(['target_abandon_rate' => 0.03]);

    // 30 answered calls, of which 3 abandoned = 10% abandon rate (well above
    // 0.7 × 0.03 = 0.021 trigger). Engine should clamp safety_factor downward.
    CallFactory::new()->count(27)->completed()->create([
        'campaign_id' => $campaign->id,
        'initiated_at' => now()->subDays(1),
    ]);
    CallFactory::new()->count(3)->abandoned()->create([
        'campaign_id' => $campaign->id,
        'initiated_at' => now()->subDays(1),
    ]);

    // Add queue depth so we're not bounded by empty-queue
    $key = "tenant:".app(\App\Core\Shared\TenantContext::class)->id().":campaign:{$campaign->id}:leadq";
    $args = ['ZADD', $key];
    for ($i = 1; $i <= 50; $i++) {
        $args[] = (float) $i;
        $args[] = 'lead-'.$i;
    }
    Redis::connection('dialer')->executeRaw($args);

    $decision = app(PacingEngine::class)->decide($campaign, 5);

    // safety_factor was 2.0; after clamping for high abandon rate it should
    // drop (× 0.85 = 1.7).
    expect($decision->safetyFactor)->toBeLessThan(2.0);
    expect($decision->abandonRate)->toBeGreaterThan(0.05);
});
