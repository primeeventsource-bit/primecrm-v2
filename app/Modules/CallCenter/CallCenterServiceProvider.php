<?php

declare(strict_types=1);

namespace App\Modules\CallCenter;

use App\Modules\CallCenter\Application\Listeners\RecordContactAttemptOnCallInitiated;
use App\Modules\CallCenter\Application\Listeners\UpdateContactOutcomeOnCallEnded;
use App\Modules\CallCenter\Domain\Events\CallEnded;
use App\Modules\CallCenter\Domain\Events\CallInitiated;
use App\Modules\CallCenter\Infrastructure\Telephony\TelephonyProvider;
use App\Modules\CallCenter\Infrastructure\Telephony\TwilioTelephonyProvider;
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
    }

    public function boot(): void
    {
        Event::listen(CallInitiated::class, [RecordContactAttemptOnCallInitiated::class, 'handle']);
        Event::listen(CallEnded::class, [UpdateContactOutcomeOnCallEnded::class, 'handle']);
    }
}
