<?php

declare(strict_types=1);

return [
    'default_provider' => env('TELEPHONY_PROVIDER', 'twilio'),

    'providers' => [
        'twilio' => [
            'account_sid' => env('TWILIO_ACCOUNT_SID'),
            'auth_token' => env('TWILIO_AUTH_TOKEN'),
            'from_number' => env('TWILIO_FROM_NUMBER'),
            'webhook_base_url' => env('TWILIO_WEBHOOK_BASE_URL'),
            'recording_enabled' => (bool) env('TWILIO_RECORDING_ENABLED', true),
            'recording_status_callback' => env('TWILIO_RECORDING_CALLBACK'),
            'machine_detection' => env('TWILIO_MACHINE_DETECTION', 'DetectMessageEnd'),
            'caller_id_pool' => array_filter(explode(',', (string) env('TWILIO_CALLER_ID_POOL', ''))),
            'verify_signature' => (bool) env('TWILIO_VERIFY_SIGNATURE', true),
        ],

        'telnyx' => [
            'api_key' => env('TELNYX_API_KEY'),
            'connection_id' => env('TELNYX_CONNECTION_ID'),
            'from_number' => env('TELNYX_FROM_NUMBER'),
        ],
    ],

    'recording' => [
        'storage_disk' => env('CALL_RECORDING_DISK', 's3'),
        'storage_path' => env('CALL_RECORDING_PATH', 'recordings'),
        'retention_days' => (int) env('CALL_RECORDING_RETENTION_DAYS', 365),
        'encrypt' => (bool) env('CALL_RECORDING_ENCRYPT', true),
        'pause_during_payment' => true, // PCI: never record CC capture
    ],

    /**
     * Predictive dialer pacing parameters.
     * Adjustable per-tenant via campaign settings, but these are the safe defaults.
     */
    'predictive' => [
        'target_abandon_rate' => 0.03, // FCC cap: 3% abandoned over 30 days
        'safety_factor_initial' => 1.0,
        'safety_factor_min' => 0.5,
        'safety_factor_max' => 2.5,
        'pacing_interval_seconds' => 30,
        'min_connection_rate' => 0.05,  // floor to avoid wild overdialing on cold lists
        'max_dials_per_agent' => 4,     // hard ceiling regardless of math
        'wrap_up_seconds_default' => 15,
    ],

    'tcpa' => [
        'min_seconds_between_attempts_same_number' => 14400, // 4 hours
        'max_attempts_per_day_same_number' => 3,
        'max_attempts_per_30days_same_number' => 7,
        'min_call_local_hour' => 8,
        'max_call_local_hour' => 21,
        'block_holidays' => true,
    ],
];
