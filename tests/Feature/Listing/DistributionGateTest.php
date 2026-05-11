<?php

declare(strict_types=1);

use App\Modules\Listing\Domain\Models\Listing;
use App\Modules\Listing\Domain\Models\PartnerSite;
use App\Modules\Listing\Domain\Models\PartnerSiteListing;
use App\Modules\Sales\Domain\Models\Deal;
use App\Support\Enums\DealStage;
use App\Support\Enums\UserRole;
use Database\Factories\LeadFactory;

/**
 * The distribution gate is the single load-bearing piece of compliance
 * enforcement: an unverified agreement cannot reach a partner site.
 * Every regression here is a regulatory exposure.
 *
 * Setup is verbose because the gate sits at the bottom of the
 * listing→property→deal chain; we build the chain once and flip
 * deal flags to exercise each branch.
 */
beforeEach(function () {
    $tenant = $this->actingAsTenant();
    $user = $this->actingAsUser($tenant, UserRole::Admin);

    $owner = LeadFactory::new()->create();

    // A "ready" deal — TCPA captured, verifier signed off, live status.
    $this->dealReady = Deal::query()->create([
        'lead_id' => $owner->id,
        'agent_id' => $user->id,
        'stage' => DealStage::ClosedWon->value,
        'total_value' => 2400,
        'snr_amount' => 0,
        'vd_amount' => 0,
        'payable_amount' => 2400,
        'listing_fee' => 2400,
        'listing_fee_collected' => 2400,
        'payment_status' => 'paid',
        'agreement_status' => 'live',
        'tcpa_disclosure_completed' => true,
        'verification_call_completed' => true,
        'closed_at' => now()->subDays(2),
    ]);

    $property = \App\Modules\Listing\Domain\Models\Property::query()->create([
        'owner_id' => $owner->id,
        'resort_name' => 'Marriott Test Resort',
        'location_city' => 'Orlando',
        'location_state' => 'FL',
        'ownership_type' => 'fixed_week',
        'ownership_verified' => true,
        'rental_allowed_by_resort' => true,
    ]);

    $this->listing = Listing::query()->create([
        'property_id' => $property->id,
        'deal_id' => $this->dealReady->id,
        'check_in_date' => now()->addMonths(3)->toDateString(),
        'check_out_date' => now()->addMonths(3)->addDays(7)->toDateString(),
        'asking_price' => 1800,
        'owner_payout' => 1200,
        'status' => 'live',
    ]);

    $this->partnerSite = PartnerSite::query()->create([
        'name' => 'Test Partner',
        'slug' => 'test-partner',
        'is_active' => true,
    ]);
});

it('allows distribution when TCPA + verification are both complete', function () {
    $response = $this->postJson(
        "/api/listings/{$this->listing->id}/distributions",
        ['partner_site_id' => $this->partnerSite->id],
    );

    $response->assertStatus(200)
        ->assertJsonStructure(['message', 'partner_site_listing']);

    $row = PartnerSiteListing::query()
        ->where('listing_id', $this->listing->id)
        ->first();
    expect($row)->not->toBeNull();
    expect((string) $row->partner_site_id)->toBe($this->partnerSite->id);
});

it('refuses distribution when TCPA disclosures are missing', function () {
    $this->dealReady->forceFill(['tcpa_disclosure_completed' => false])->save();

    $response = $this->postJson(
        "/api/listings/{$this->listing->id}/distributions",
        ['partner_site_id' => $this->partnerSite->id],
    );

    $response->assertStatus(422)
        ->assertJson(['code' => 'tcpa_disclosure_missing']);

    expect(PartnerSiteListing::query()->where('listing_id', $this->listing->id)->count())
        ->toBe(0);
});

it('refuses distribution when verification callback is not complete', function () {
    $this->dealReady->forceFill([
        'tcpa_disclosure_completed' => true,
        'verification_call_completed' => false,
    ])->save();

    $response = $this->postJson(
        "/api/listings/{$this->listing->id}/distributions",
        ['partner_site_id' => $this->partnerSite->id],
    );

    $response->assertStatus(422)
        ->assertJson(['code' => 'verification_call_missing']);
});

it('refuses distribution on a refunded agreement even if disclosures captured', function () {
    $this->dealReady->forceFill(['agreement_status' => 'refunded'])->save();

    $response = $this->postJson(
        "/api/listings/{$this->listing->id}/distributions",
        ['partner_site_id' => $this->partnerSite->id],
    );

    $response->assertStatus(422)
        ->assertJson(['code' => 'agreement_terminal']);
});

it('refuses re-push on an unverified agreement', function () {
    // Push once with the deal verified.
    $first = $this->postJson(
        "/api/listings/{$this->listing->id}/distributions",
        ['partner_site_id' => $this->partnerSite->id],
    );
    $first->assertStatus(200);

    $row = PartnerSiteListing::query()->where('listing_id', $this->listing->id)->first();

    // Now revoke the verification — a re-push attempt must be refused.
    $this->dealReady->forceFill(['verification_call_completed' => false])->save();

    $response = $this->postJson(
        "/api/listings/{$this->listing->id}/distributions/{$row->id}/repush",
    );

    $response->assertStatus(422)
        ->assertJson(['code' => 'verification_call_missing']);
});

it('allows pause and sync on an unverified agreement (no new outbound push)', function () {
    // Push once verified.
    $this->postJson(
        "/api/listings/{$this->listing->id}/distributions",
        ['partner_site_id' => $this->partnerSite->id],
    )->assertStatus(200);

    $row = PartnerSiteListing::query()->where('listing_id', $this->listing->id)->first();

    // Revoke verification.
    $this->dealReady->forceFill(['verification_call_completed' => false])->save();

    // Pause should still work — no re-publication.
    $this->postJson("/api/listings/{$this->listing->id}/distributions/{$row->id}/pause")
        ->assertStatus(200);

    // Sync should still work — no re-publication.
    $this->postJson("/api/listings/{$this->listing->id}/distributions/{$row->id}/sync")
        ->assertStatus(200);
});
