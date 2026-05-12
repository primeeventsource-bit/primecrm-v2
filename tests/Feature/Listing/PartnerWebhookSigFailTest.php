<?php

declare(strict_types=1);

use App\Core\Shared\TenantContext;
use App\Modules\Listing\Domain\Models\PartnerWebhookEvent;
use Database\Factories\PartnerSiteFactory;

/*
 * Regression test for the tenant-context ordering fix in commit e6e0de4.
 *
 * Before the fix, the controller called $this->events->record(...) for
 * sig failures BEFORE $this->tenants->set($site->tenant_id) had run.
 * PartnerWebhookEvent is TenantScoped, so its `creating` hook threw
 * RuntimeException("Cannot create ... without a resolved tenant
 * context"). PartnerWebhookEventLogger swallows Throwables (so the
 * webhook handler never fails because of audit-log issues), which
 * meant the operationally-most-useful log row — the sig failure —
 * was silently dropped.
 *
 * This test asserts the post-fix behaviour: an HMAC-failing post
 * returns 401 AND leaves exactly one PartnerWebhookEvent row with
 * the correct tenant_id, signature_valid=false, http_status=401.
 *
 * The test deliberately CLEARS the TenantContext before the request
 * so that the only thing setting it is the controller itself. Without
 * the clear(), the test would pass even on the broken code (the
 * factory call above sets the context as a side-effect).
 */

beforeEach(function () {
    $this->tenant = $this->actingAsTenant();
});

it('writes a sig-fail event row with tenant_id resolved from the slug', function () {
    $site = PartnerSiteFactory::new()
        ->withWebhookSecret('correct-secret-shared-with-partner')
        ->create();

    // Drop tenant context so the controller is the only thing that can
    // resolve it. The bug-under-test was that the event-log write ran
    // BEFORE the controller set context — under that ordering, the
    // tenant-scoped creating hook would have thrown.
    app(TenantContext::class)->clear();

    $body = json_encode([
        'external_inquiry_id' => 'abc-123',
        'external_listing_id' => 'lst-9',
        'renter_name' => 'Jamie',
    ]);

    $response = $this->call(
        'POST',
        "/api/partner-webhooks/{$site->slug}/inquiries",
        [], [], [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PARTNER_SIGNATURE' => 'sha256=deadbeef', // wrong sig
        ],
        $body,
    );

    $response->assertStatus(401);

    // Look up across all tenants because tenant_id is the thing under
    // test — using the tenant-scoped query would mask a mis-attributed
    // row.
    $events = PartnerWebhookEvent::query()->withoutGlobalScopes()->get();
    expect($events)->toHaveCount(1);

    $event = $events->first();
    expect($event->tenant_id)->toBe($this->tenant->id)
        ->and($event->partner_site_id)->toBe($site->id)
        ->and((bool) $event->signature_valid)->toBeFalse()
        ->and((int) $event->http_status)->toBe(401)
        ->and($event->kind)->toBe('inquiry');
});

it('writes a sig-fail event row on the booking endpoint too', function () {
    $site = PartnerSiteFactory::new()
        ->withWebhookSecret('correct-secret')
        ->create();

    app(TenantContext::class)->clear();

    $response = $this->call(
        'POST',
        "/api/partner-webhooks/{$site->slug}/bookings",
        [], [], [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PARTNER_SIGNATURE' => 'wrong',
        ],
        json_encode(['external_booking_id' => 'bk-1', 'renter_name' => 'Test']),
    );

    $response->assertStatus(401);

    $events = PartnerWebhookEvent::query()->withoutGlobalScopes()->get();
    expect($events)->toHaveCount(1)
        ->and($events->first()->kind)->toBe('booking')
        ->and((bool) $events->first()->signature_valid)->toBeFalse();
});

it('returns 404 with no event row when the slug is unknown (no site to attribute to)', function () {
    app(TenantContext::class)->clear();

    $this->call(
        'POST',
        '/api/partner-webhooks/does-not-exist/inquiries',
        [], [], [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PARTNER_SIGNATURE' => 'sha256=whatever',
        ],
        '{}',
    )->assertStatus(404);

    expect(PartnerWebhookEvent::query()->withoutGlobalScopes()->count())->toBe(0);
});
