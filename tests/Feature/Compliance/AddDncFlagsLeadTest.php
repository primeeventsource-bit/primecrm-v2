<?php

declare(strict_types=1);

use App\Modules\Compliance\Application\Actions\AddDncEntryAction;
use App\Modules\Compliance\Domain\Enums\DncSource;
use App\Modules\Lead\Domain\Models\Lead;
use Database\Factories\LeadFactory;

beforeEach(function () {
    $this->actingAsTenant();
});

it('flags matching leads when an internal DNC entry is added', function () {
    $lead = LeadFactory::new()->withPhone('+14155558888')->create();

    expect($lead->is_on_dnc)->toBeFalse();

    app(AddDncEntryAction::class)->execute(
        rawPhone: '+14155558888',
        source: DncSource::InternalDnc,
        reason: 'Customer asked to be removed',
    );

    expect($lead->fresh()->is_on_dnc)->toBeTrue();
});

it('flags matching leads across all tenants when adding a federal entry', function () {
    $tenantA = $this->actingAsTenant();
    $leadA = LeadFactory::new()->withPhone('+14155559999')->create();

    // Switch to a second tenant
    $tenantB = \Database\Factories\TenantFactory::new()->create();
    app(\App\Core\Shared\TenantContext::class)->set($tenantB->id);
    $leadB = LeadFactory::new()->withPhone('+14155559999')->create();

    // Federal entries are global — added with tenant_id NULL.
    app(AddDncEntryAction::class)->execute(
        rawPhone: '+14155559999',
        source: DncSource::FederalDnc,
    );

    // Both tenants' leads must be flagged.
    expect(Lead::query()->withoutTenantScope()->find($leadA->id)->is_on_dnc)->toBeTrue();
    expect(Lead::query()->withoutTenantScope()->find($leadB->id)->is_on_dnc)->toBeTrue();
});
