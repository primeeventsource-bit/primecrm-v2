<?php

declare(strict_types=1);

use App\Modules\Dialer\Application\Services\DialerService;
use App\Modules\Dialer\Domain\Models\DialSession;
use App\Support\Enums\AgentStatus;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    $this->actingAsTenant();
    Redis::connection('dialer')->flushdb();
});

it('starts a session and flips agent presence to Available', function () {
    $agent = UserFactory::new()->agent()->create();

    $session = app(DialerService::class)->start($agent);

    expect($session->status)->toBe(DialSession::STATUS_ACTIVE);
    expect($session->agent_id)->toBe($agent->id);
    expect(app(\App\Modules\CallCenter\Application\Services\AgentPresenceService::class)->listAvailable())
        ->toContain($agent->id);
});

it('returns the existing session if the agent already has one active', function () {
    $agent = UserFactory::new()->agent()->create();
    $service = app(DialerService::class);

    $first = $service->start($agent);
    $second = $service->start($agent);

    expect($second->id)->toBe($first->id);
    expect(DialSession::query()->forAgent($agent->id)->count())->toBe(1);
});

it('pause flips presence to OnBreak; resume flips back to Available', function () {
    $agent = UserFactory::new()->agent()->create();
    $service = app(DialerService::class);
    $presence = app(\App\Modules\CallCenter\Application\Services\AgentPresenceService::class);

    $session = $service->start($agent);
    expect($presence->listAvailable())->toContain($agent->id);

    $service->pause($session);
    expect($presence->listAvailable())->not->toContain($agent->id);

    $service->resume($session->fresh());
    expect($presence->listAvailable())->toContain($agent->id);
});

it('stop ends the session and takes the agent offline', function () {
    $agent = UserFactory::new()->agent()->create();
    $service = app(DialerService::class);

    $session = $service->start($agent);
    $stopped = $service->stop($session);

    expect($stopped->status)->toBe(DialSession::STATUS_ENDED);
    expect($stopped->ended_at)->not->toBeNull();
    expect(app(\App\Modules\CallCenter\Application\Services\AgentPresenceService::class)->listAvailable())
        ->not->toContain($agent->id);
});
