<?php

declare(strict_types=1);

use App\Modules\Lead\Application\Services\LeadAssignmentService;
use App\Modules\Lead\Domain\Models\Lead;
use App\Support\Enums\LeadPriority;
use App\Support\Enums\UserRole;
use Database\Factories\LeadFactory;
use Database\Factories\UserFactory;

beforeEach(function () {
    $this->actingAsTenant();
});

it('returns null when no eligible agents exist', function () {
    $lead = LeadFactory::new()->create();

    $service = app(LeadAssignmentService::class);
    $result = $service->assign($lead, 'round_robin');

    expect($result)->toBeNull();
    expect($lead->fresh()->assigned_agent_id)->toBeNull();
});

it('assigns via round_robin when one agent is eligible', function () {
    $agent = UserFactory::new()->agent()->create();
    $lead = LeadFactory::new()->create();

    $service = app(LeadAssignmentService::class);
    $result = $service->assign($lead, 'round_robin');

    expect($result?->id)->toBe($agent->id);
    expect($lead->fresh()->assigned_agent_id)->toBe($agent->id);
    expect($lead->fresh()->assigned_at)->not->toBeNull();
});

it('cycles round_robin across multiple agents', function () {
    $a = UserFactory::new()->agent()->create();
    $b = UserFactory::new()->agent()->create();

    $service = app(LeadAssignmentService::class);
    $assignments = [];

    for ($i = 0; $i < 4; $i++) {
        $lead = LeadFactory::new()->create();
        $service->assign($lead, 'round_robin');
        $assignments[] = $lead->fresh()->assigned_agent_id;
    }

    // Both agents got at least one lead.
    expect(array_unique($assignments))->toHaveCount(2);
});

it('skips agents who are over their open-leads cap', function () {
    config(['leads.assignment.max_open_leads_per_agent' => 2]);

    $busy = UserFactory::new()->agent()->create();
    $available = UserFactory::new()->agent()->create();

    // Saturate the busy agent
    LeadFactory::new()->count(3)->create(['assigned_agent_id' => $busy->id]);

    $lead = LeadFactory::new()->create();
    $service = app(LeadAssignmentService::class);
    $result = $service->assign($lead, 'round_robin');

    expect($result?->id)->toBe($available->id);
});

it('hot leads short-circuit the pool to the top performer when configured', function () {
    config([
        'leads.assignment.hot_lead_skip_pool' => true,
        'leads.assignment.metrics_cache_ttl_seconds' => 0,
    ]);

    $lowPerf = UserFactory::new()->agent()->create();
    $highPerf = UserFactory::new()->agent()->create();

    // Give highPerf a closed_won deal in the window so its score is non-zero.
    \Illuminate\Support\Facades\DB::table('deals')->insert([
        'id' => \Ramsey\Uuid\Uuid::uuid7()->toString(),
        'tenant_id' => app(\App\Core\Shared\TenantContext::class)->id(),
        'agent_id' => $highPerf->id,
        'stage' => 'closed_won',
        'amount' => 10000,
        'currency' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    \Illuminate\Support\Facades\DB::table('leads')->insert([
        'id' => \Ramsey\Uuid\Uuid::uuid7()->toString(),
        'tenant_id' => app(\App\Core\Shared\TenantContext::class)->id(),
        'agent_id' => $highPerf->id,
        'assigned_agent_id' => $highPerf->id,
        'phone' => '+19999999999',
        'phone_hash' => hash('sha256', '+19999999999'),
        'status' => 'closed_won',
        'priority' => 'normal',
        'source' => 'referral',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $hot = LeadFactory::new()->hot()->create();
    $service = app(LeadAssignmentService::class);
    $result = $service->assign($hot);

    expect($result?->id)->toBe($highPerf->id);
});

it('refuses to assign to non-call-taking roles', function () {
    UserFactory::new()->state(['role' => UserRole::QA->value])->create();
    UserFactory::new()->state(['role' => UserRole::Manager->value])->create();
    UserFactory::new()->state(['role' => UserRole::MasterAdmin->value])->create();

    $lead = LeadFactory::new()->create();
    $service = app(LeadAssignmentService::class);

    expect($service->assign($lead, 'round_robin'))->toBeNull();
});

it('manual reassign skips the routing engine', function () {
    UserFactory::new()->agent()->create(); // ineligible by absence of routing
    $target = UserFactory::new()->closer()->create();

    $lead = LeadFactory::new()->create();
    $service = app(LeadAssignmentService::class);

    $result = $service->reassign($lead, $target->id, 'supervisor_pick');

    expect($result?->id)->toBe($target->id);
    expect($lead->fresh()->assigned_agent_id)->toBe($target->id);
});
