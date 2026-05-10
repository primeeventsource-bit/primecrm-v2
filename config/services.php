<?php

declare(strict_types=1);

return [

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook' => [
            'secret' => env('STRIPE_WEBHOOK_SECRET'),
            'tolerance' => (int) env('STRIPE_WEBHOOK_TOLERANCE', 300),
        ],
    ],

    'docusign' => [
        'integration_key' => env('DOCUSIGN_INTEGRATION_KEY'),
        'user_id' => env('DOCUSIGN_USER_ID'),
        'account_id' => env('DOCUSIGN_ACCOUNT_ID'),
        'rsa_private_key_path' => env('DOCUSIGN_RSA_PRIVATE_KEY_PATH'),
    ],

    /*
    | Twilio
    |
    | Voice (telephony module) reuses the existing TWILIO_ACCOUNT_SID +
    | TWILIO_AUTH_TOKEN — see config/telephony.php. Prime Connect (video)
    | needs an additional API Key SID + Secret pair: Twilio's Video JWT
    | minter ONLY accepts API Keys, not the master auth token. Create the
    | key in console.twilio.com → API keys (Standard scope) and treat the
    | secret as write-credentials (rotate on a schedule).
    */
    'twilio' => [
        'account_sid' => env('TWILIO_ACCOUNT_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
        'api_key_sid' => env('TWILIO_API_KEY_SID'),
        'api_key_secret' => env('TWILIO_API_KEY_SECRET'),
        'region' => env('TWILIO_REGION', 'us1'),
    ],

];
