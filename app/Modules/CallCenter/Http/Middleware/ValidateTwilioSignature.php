<?php

declare(strict_types=1);

namespace App\Modules\CallCenter\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Twilio\Security\RequestValidator;

/**
 * Verifies the X-Twilio-Signature header on incoming Twilio webhooks.
 *
 * Refactored out of the legacy in-controller `guardSignature()` so that
 * both voice (TwilioWebhookController) and video (Prime Connect)
 * webhook routes can share one signature-verification implementation.
 *
 * The verifier needs the master Auth Token — Twilio signs every webhook
 * with it regardless of which API key created the resource. This is one
 * of the reasons the master Auth Token is the most sensitive credential
 * the app holds; rotating it requires updating both the live webhook
 * receiver and any local development tunnels at the same moment.
 *
 * Toggling: set TWILIO_VERIFY_SIGNATURE=false (or
 * PRIME_CONNECT_VERIFY_SIGNATURE=false for video specifically) to bypass
 * during local development with ngrok-rewritten URLs that won't match
 * what Twilio signed. NEVER turn off in production — without this check
 * any anonymous POST to the webhook URL can spoof an event.
 */
final class ValidateTwilioSignature
{
    public function __construct(private readonly Config $config) {}

    /**
     * @param  string  $scope  'voice' (default) reads telephony config; 'video' reads prime-connect config.
     */
    public function handle(Request $request, Closure $next, string $scope = 'voice'): Response
    {
        if (! $this->shouldVerify($scope)) {
            return $next($request);
        }

        $authToken = (string) $this->config->get('telephony.providers.twilio.auth_token');
        if ($authToken === '') {
            // Refuse to proceed silently. Either the env is misconfigured
            // or someone bypassed the verify flag without supplying creds.
            abort(500, 'Twilio auth token is not configured for signature validation.');
        }

        $signature = (string) $request->header('X-Twilio-Signature', '');
        $url = $request->fullUrl();
        // Twilio signs over the parsed POST params for application/x-www-form-urlencoded
        // (which is what their webhooks send). For JSON bodies the param array is empty
        // and the validator falls back to the URL alone, which is the documented behavior.
        $params = $request->isMethod('POST') ? $request->post() : [];

        $validator = new RequestValidator($authToken);

        if (! $validator->validate($signature, $url, $params)) {
            abort(403, 'Invalid Twilio signature');
        }

        return $next($request);
    }

    private function shouldVerify(string $scope): bool
    {
        return match ($scope) {
            'video' => (bool) $this->config->get('prime-connect.webhook.verify_signature', true),
            default => (bool) $this->config->get('telephony.providers.twilio.verify_signature', true),
        };
    }
}
