<?php

declare(strict_types=1);

use App\Modules\CallCenter\Http\Middleware\ValidateTwilioSignature;
use Illuminate\Support\Facades\Route;
use Twilio\Security\RequestValidator;

beforeEach(function () {
    // Stable auth token used by both test config and signature generation.
    config([
        'telephony.providers.twilio.auth_token' => 'test-auth-token-XYZ',
        'telephony.providers.twilio.verify_signature' => true,
        'prime-connect.webhook.verify_signature' => true,
    ]);

    Route::middleware([ValidateTwilioSignature::class.':voice'])
        ->post('/_test/twilio/voice', fn () => response('voice-ok', 200));

    Route::middleware([ValidateTwilioSignature::class.':video'])
        ->post('/_test/twilio/video', fn () => response('video-ok', 200));
});

it('accepts a request with a valid Twilio signature (voice scope)', function () {
    $url = url('/_test/twilio/voice');
    $params = ['CallSid' => 'CAabc'];
    $signature = (new RequestValidator('test-auth-token-XYZ'))
        ->computeSignature($url, $params);

    $this->withServerVariables(['HTTPS' => 'on'])
        ->post('/_test/twilio/voice', $params, ['X-Twilio-Signature' => $signature])
        ->assertOk();
});

it('rejects a request with a wrong Twilio signature', function () {
    $this->post('/_test/twilio/voice', ['CallSid' => 'CAabc'], [
        'X-Twilio-Signature' => 'this-is-not-the-signature-twilio-would-have-sent',
    ])->assertForbidden();
});

it('bypasses verification when the scope flag is off', function () {
    config(['telephony.providers.twilio.verify_signature' => false]);

    $this->post('/_test/twilio/voice', ['CallSid' => 'CAabc'], [
        'X-Twilio-Signature' => 'garbage',
    ])->assertOk();
});

it('honours independent flags for voice vs video scopes', function () {
    // Disable video, leave voice on.
    config(['prime-connect.webhook.verify_signature' => false]);

    // Voice still requires a valid sig.
    $this->post('/_test/twilio/voice', [], ['X-Twilio-Signature' => 'bad'])
        ->assertForbidden();

    // Video bypasses entirely.
    $this->post('/_test/twilio/video', [], ['X-Twilio-Signature' => 'bad'])
        ->assertOk();
});

it('returns 500 when verify is on but the auth token is missing', function () {
    config(['telephony.providers.twilio.auth_token' => '']);

    $this->post('/_test/twilio/voice', [], ['X-Twilio-Signature' => 'whatever'])
        ->assertStatus(500);
});
