<?php

declare(strict_types=1);

use App\Modules\CallCenter\Application\Services\AgentPresenceService;
use App\Modules\CallCenter\Domain\Models\AgentStatusRecord;
use App\Support\Enums\AgentStatus;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    $this->actingAsTenant();
    Redis::connection('dialer')->flushdb();
});

it('writes both Postgres and Redis on status change', function () {
    $agent = UserFactory::new()->agent()->create();

    app(AgentPresenceService::class)->set($agent->id, AgentStatus::Available);

    $row = AgentStatusRecord::query()->where('agent_id', $agent->id)->first();
    expect($row->status)->toBe(AgentStatus::Available);

    $tenantId = app(\App\Core\Shared\TenantContext::class)->id();
    $redisValue = Redis::connection('dialer')->hget("tenant:{$tenantId}:agent_presence", $agent->id);
    expect($redisValue)->not->toBeNull();
    $decoded = json_decode($redisValue, true);
    expect($decoded['status'])->toBe(AgentStatus::Available->value);
});

it('listAvailable reads from Redis and returns only available agents', function () {
    $a = UserFactory::new()->agent()->create();
    $b = UserFactory::new()->agent()->create();
    $c = UserFactory::new()->agent()->create();

    $service = app(AgentPresenceService::class);
    $service->set($a->id, AgentStatus::Available);
    $service->set($b->id, AgentStatus::OnCall);
    $service->set($c->id, AgentStatus::Available);

    $available = $service->listAvailable();
    sort($available);

    $expected = [$a->id, $c->id];
    sort($expected);

    expect($available)->toBe($expected);
});

it('rewarms Redis from Postgres if the cache is empty', function () {
    $a = UserFactory::new()->agent()->create();

    app(AgentPresenceService::class)->set($a->id, AgentStatus::Available);

    // Simulate a Redis flush after Postgres was written
    Redis::connection('dialer')->flushdb();

    $available = app(AgentPresenceService::class)->listAvailable();

    expect($available)->toContain($a->id);

    $tenantId = app(\App\Core\Shared\TenantContext::class)->id();
    $redisValue = Redis::connection('dialer')->hget("tenant:{$tenantId}:agent_presence", $a->id);
    expect($redisValue)->not->toBeNull();
});

it('records an audit log entry on every status transition', function () {
    $agent = UserFactory::new()->agent()->create();
    $service = app(AgentPresenceService::class);

    $service->set($agent->id, AgentStatus::Available);
    $service->set($agent->id, AgentStatus::OnCall);
    $service->set($agent->id, AgentStatus::WrapUp);

    $count = \Illuminate\Support\Facades\DB::table('audit_logs')
        ->where('action', 'agent.status_changed')
        ->where('entity_id', $agent->id)
        ->count();

    expect($count)->toBe(3);
});
