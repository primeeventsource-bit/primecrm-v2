<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Controllers;

use App\Modules\CallCenter\Application\Services\WebhookEventStore;
use App\Modules\Payment\Application\Jobs\ProcessStripeWebhookJob;
use App\Modules\Payment\Infrastructure\Gateway\PaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * Stripe webhook receiver.
 *
 * PUBLIC route (no auth) — verified by signature using the configured
 * webhook secret. Stripe's `Webhook::constructEvent()` does the HMAC
 * check and timestamp validation in one step; we delegate via
 * PaymentGateway::verifyAndParseWebhook() so tests can swap a fake.
 *
 * Body MUST be the raw payload — Laravel's JSON decoding would invalidate
 * the signature. We grab `getContent()` rather than `$request->all()`.
 */
final class StripeWebhookController extends Controller
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly WebhookEventStore $store,
    ) {}

    public function handle(Request $request): Response
    {
        $rawPayload = (string) $request->getContent();
        $signature = (string) $request->header('Stripe-Signature', '');

        $parsed = $this->gateway->verifyAndParseWebhook($rawPayload, $signature);

        if ($parsed === null) {
            return response('invalid signature', 403);
        }

        $eventId = (string) ($parsed['id'] ?? '');
        $eventType = (string) ($parsed['type'] ?? '');

        if ($eventId === '' || $eventType === '') {
            return response('missing event id', 400);
        }

        $event = $this->store->ingest(
            provider: 'stripe',
            externalId: $eventId,
            eventType: $eventType,
            payload: $parsed,
            headers: ['Stripe-Signature' => $signature],
        );

        if ($event === null) {
            return response('OK', 200); // duplicate
        }

        ProcessStripeWebhookJob::dispatch($event->id);

        return response('OK', 200);
    }
}
