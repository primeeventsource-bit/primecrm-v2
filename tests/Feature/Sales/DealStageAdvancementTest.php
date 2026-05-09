<?php

declare(strict_types=1);

use App\Modules\Sales\Application\Services\DealService;
use App\Modules\Sales\Domain\Events\DealClosedWon;
use App\Modules\Sales\Domain\Models\DealStageTransition;
use App\Support\Enums\DealStage;
use Database\Factories\DealFactory;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->actingAsTenant();
});

it('advances stage and writes a transition row', function () {
    $deal = DealFactory::new()->create();

    app(DealService::class)->advanceStage($deal, DealStage::PitchPresented, 'pitched the package');

    $fresh = $deal->fresh();
    expect($fresh->stage)->toBe(DealStage::PitchPresented);
    expect($fresh->previous_stage)->toBe(DealStage::New->value);

    $transitions = DealStageTransition::query()->where('deal_id', $deal->id)->get();
    expect($transitions)->toHaveCount(1);
    expect($transitions[0]->from_stage)->toBe(DealStage::New->value);
    expect($transitions[0]->to_stage)->toBe(DealStage::PitchPresented->value);
});

it('fires DealClosedWon when transitioning to closed_won', function () {
    Event::fake([DealClosedWon::class]);
    $deal = DealFactory::new()->create();

    app(DealService::class)->advanceStage($deal, DealStage::ClosedWon);

    Event::assertDispatched(DealClosedWon::class);
    expect($deal->fresh()->closed_at)->not->toBeNull();
});

it('is a no-op when called with the same stage', function () {
    $deal = DealFactory::new()->create(['stage' => DealStage::Qualified->value]);

    app(DealService::class)->advanceStage($deal, DealStage::Qualified);

    expect(DealStageTransition::query()->where('deal_id', $deal->id)->count())->toBe(0);
});
