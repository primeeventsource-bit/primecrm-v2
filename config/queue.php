<?php

declare(strict_types=1);

return [
    'default' => env('QUEUE_CONNECTION', 'redis'),

    'connections' => [
        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 90),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 90),
            'block_for' => null,
            'after_commit' => false, // jobs queued mid-transaction dispatch immediately
        ],
    ],

    'batching' => [
        'database' => env('DB_CONNECTION', 'pgsql'),
        'table' => 'job_batches',
    ],

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'pgsql'),
        'table' => 'failed_jobs',
    ],

    /**
     * Named queues — referenced by jobs via onQueue(). Single source of truth.
     * If you add a queue here, add it to config/horizon.php as well.
     */
    'names' => [
        'dialer' => 'dialer',                    // predictive dialer ticks, lead preload
        'calls' => 'calls',                      // initiate Twilio call, end call, hang up
        'webhooks' => 'webhooks',                // generic webhook processing
        'webhooks_twilio' => 'webhooks-twilio',  // Twilio status callbacks
        'webhooks_stripe' => 'webhooks-stripe',  // Stripe payment events
        'lead_assignment' => 'lead-assignment',  // lead routing, reassignment
        'lead_import' => 'lead-import',          // CSV imports, batch ingestion
        'lead_scoring' => 'lead-scoring',        // score recalc
        'recordings' => 'recordings',            // download/upload to S3, transcription
        'contracts' => 'contracts',              // PDF generation, e-sign envelope
        'reports' => 'reports',                  // dashboard snapshot generation
        'commissions' => 'commissions',          // calc + reversal jobs
        'notifications' => 'notifications',      // email, SMS, push
        'default' => 'default',
    ],
];
