<?php

declare(strict_types=1);

use App\Modules\CallCenter\Domain\Models\WebhookEvent;
use App\Modules\Payment\Domain\Models\Payment;
use App\Modules\Payment\Infrastructure\Gateway\PaymentGateway;
use Tests\Support\FakePaymentGateway;

beforeEach(function () {
    $this->actingAsTenant();
    $this->gateway = new FakePaymentGateway;
    $this->app->instance(PaymentGateway::class, $this->gateway);
});

it('accepts a valid Stripe webhook and dispatches one processing job', function () {
    $payment = Payment::query()->create([
        'tenant_id' => app(\App\Core\Shared\TenantContext::class)->id(),
        'provider' => 'stripe',
        'provider_payment_id' => 'pi_test_123',
        'payment_method' => 'card',
        'amount' => 200,
        'currency' => 'USD',
        'type' => Payment::TYPE_CHARGE,
        'status' => Payment::STATUS_PROCESSING,
    ]);

    $this->gateway->parseReturn = [
        'id' => 'evt_abc',
        'type' => 'payment_intent.succeeded',
        'data' => ['object' => [
            'id' => 'pi_test_123',
            'metadata' => ['tenant_id' => app(\App\Core\Shared\TenantContext::class)->id()],
        ]],
    ];

    $response = $this->call('POST', '/webhooks/stripe', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => 'sig',
        'CONTENT_TYPE' => 'application/json',
    ], '{"x":"y"}');

    $response->assertOk();

    expect(WebhookEvent::query()->count())->toBe(1);
    expect(WebhookEvent::query()->first()->external_id)->toBe('evt_abc');

    // QUEUE=sync → ProcessStripeWebhookJob already ran.
    expect($payment->fresh()->cleared_at)->not->toBeNull();
    expect($payment->fresh()->status)->toBe(Payment::STATUS_SUCCEEDED);
});

it('dedups a duplicate Stripe event id', function () {
    $this->gateway->parseReturn = [
        'id' => 'evt_dup',
        'type' => 'payment_intent.succeeded',
        'data' => ['object' => ['id' => 'pi_no_match']],
    ];

    $this->call('POST', '/webhooks/stripe', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => 'sig',
        'CONTENT_TYPE' => 'application/json',
    ], '{"x":"y"}')->assertOk();

    $this->call('POST', '/webhooks/stripe', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => 'sig',
        'CONTENT_TYPE' => 'application/json',
    ], '{"x":"y"}')->assertOk();

    expect(WebhookEvent::query()->where('external_id', 'evt_dup')->count())->toBe(1);
});

it('rejects an invalid signature with 403', function () {
    $this->gateway->parseReturn = null;

    $this->call('POST', '/webhooks/stripe', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => 'invalid',
        'CONTENT_TYPE' => 'application/json',
    ], '{"x":"y"}')->assertStatus(403);

    expect(WebhookEvent::query()->count())->toBe(0);
});
