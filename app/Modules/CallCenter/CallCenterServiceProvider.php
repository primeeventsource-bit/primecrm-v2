<?php

declare(strict_types=1);

namespace App\Modules\CallCenter;

use App\Modules\CallCenter\Application\Listeners\RecordContactAttemptOnCallInitiated;
use App\Modules\CallCenter\Application\Listeners\UpdateContactOutcomeOnCallEnded;
use App\Modules\CallCenter\Application\Services\CircuitBreaker;
use App\Modules\CallCenter\Application\Services\TwilioRoomService;
use App\Modules\CallCenter\Domain\Events\CallEnded;
use App\Modules\CallCenter\Domain\Events\CallInitiated;
use App\Modules\CallCenter\Infrastructure\Telephony\TelephonyProvider;
use App\Modules\CallCenter\Infrastructure\Telephony\TwilioTelephonyProvider;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Twilio\Rest\Client as TwilioClient;

final class CallCenterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TwilioClient::class, function (): TwilioClient {
            $cfg = config('telephony.providers.twilio');

            return new TwilioClient(
                (string) ($cfg['account_sid'] ?? ''),
                (string) ($cfg['auth_token'] ?? ''),
            );
        });

        $this->app->singleton(TelephonyProvider::class, function ($app): TelephonyProvider {
            $cfg = config('telephony.providers.twilio');

            return new TwilioTelephonyProvider(
                client: $app->make(TwilioClient::class),
                authToken: (string) ($cfg['auth_token'] ?? ''),
                verifySignatures: (bool) ($cfg['verify_signature'] ?? true),
                recordingEnabled: (bool) ($cfg['recording_enabled'] ?? true),
                machineDetection: $cfg['machine_detection'] ?? null,
            );
        });

        // Prime Connect (video) — singleton breaker shared across requests
        // and queue workers via Redis. One global breaker (rather than
        // per-endpoint) is intentional: Twilio outages tend to be regional
        // and affect every endpoint, so global is the right grain.
        $this->app->singleton(CircuitBreaker::class, function ($app): CircuitBreaker {
            $cfg = (array) config('prime-connect.resilience.circuit_breaker', []);

            return new CircuitBreaker(
                cache: $app->make(Cache::class),
                name: 'twilio_video',
                failureThreshold: (int) ($cfg['failure_threshold'] ?? 5),
                windowSeconds: (int) ($cfg['window_seconds'] ?? 30),
                cooldownSeconds: (int) ($cfg['cooldown_seconds'] ?? 15),
            );
        });

        $this->app->singleton(TwilioRoomService::class, function ($app): TwilioRoomService {
            return new TwilioRoomService(
                twilio: $app->make(TwilioClient::class),
                breaker: $app->make(CircuitBreaker::class),
                config: $app->make(Config::class),
            );
        });
    }

    public function boot(): void
    {
        Event::listen(CallInitiated::class, [RecordContactAttemptOnCallInitiated::class, 'handle']);
        Event::listen(CallEnded::class, [UpdateContactOutcomeOnCallEnded::class, 'handle']);
    }
}
