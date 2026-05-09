<?php

declare(strict_types=1);

use Illuminate\Support\Str;

return [
    'domain' => env('HORIZON_DOMAIN'),
    'path' => env('HORIZON_PATH', 'horizon'),
    'use' => 'default',
    'prefix' => env('HORIZON_PREFIX', Str::slug(env('APP_NAME', 'crm'), '_').'_horizon:'),

    'middleware' => ['web', 'auth'],

    'waits' => [
        'redis:dialer' => 5,
        'redis:calls' => 5,
        'redis:lead-assignment' => 10,
        'redis:webhooks' => 10,
        'redis:default' => 60,
        'redis:recordings' => 120,
        'redis:contracts' => 120,
        'redis:reports' => 300,
        'redis:notifications' => 60,
    ],

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    'silenced' => [
        // Add job classes here to suppress completion notifications
    ],

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    'fast_termination' => false,
    'memory_limit' => 256,

    'defaults' => [
        'supervisor-dialer' => [
            'connection' => 'redis',
            'queue' => ['dialer', 'calls'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 50,
            'minProcesses' => 5,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 3,
            'timeout' => 90,
            'nice' => 0,
        ],

        'supervisor-webhooks' => [
            'connection' => 'redis',
            'queue' => ['webhooks', 'webhooks-twilio', 'webhooks-stripe'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'size',
            'maxProcesses' => 30,
            'minProcesses' => 3,
            'memory' => 256,
            'tries' => 5,
            'timeout' => 60,
            'backoff' => [10, 30, 90, 300, 900], // exponential backoff for webhook retries
        ],

        'supervisor-leads' => [
            'connection' => 'redis',
            'queue' => ['lead-assignment', 'lead-import', 'lead-scoring'],
            'balance' => 'auto',
            'maxProcesses' => 20,
            'minProcesses' => 2,
            'memory' => 512, // CSV imports can be memory-heavy
            'tries' => 3,
            'timeout' => 600, // imports take a while
        ],

        'supervisor-background' => [
            'connection' => 'redis',
            'queue' => ['recordings', 'contracts', 'reports', 'notifications'],
            'balance' => 'auto',
            'maxProcesses' => 20,
            'minProcesses' => 2,
            'memory' => 512,
            'tries' => 3,
            'timeout' => 300,
        ],

        'supervisor-commissions' => [
            'connection' => 'redis',
            'queue' => ['commissions'],
            'balance' => 'auto',
            'maxProcesses' => 10,
            'minProcesses' => 1,
            'memory' => 256,
            'tries' => 3,
            'timeout' => 120,
        ],

        'supervisor-default' => [
            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'auto',
            'maxProcesses' => 10,
            'minProcesses' => 1,
            'memory' => 256,
            'tries' => 3,
            'timeout' => 60,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-dialer' => [
                'maxProcesses' => 50,
                'balanceMaxShift' => 5,
                'balanceCooldown' => 3,
            ],
            'supervisor-webhooks' => [
                'maxProcesses' => 30,
            ],
            'supervisor-leads' => [
                'maxProcesses' => 20,
            ],
            'supervisor-background' => [
                'maxProcesses' => 20,
            ],
            'supervisor-commissions' => [
                'maxProcesses' => 10,
            ],
            'supervisor-default' => [
                'maxProcesses' => 10,
            ],
        ],

        'staging' => [
            'supervisor-dialer' => ['maxProcesses' => 10],
            'supervisor-webhooks' => ['maxProcesses' => 5],
            'supervisor-leads' => ['maxProcesses' => 3],
            'supervisor-background' => ['maxProcesses' => 3],
            'supervisor-commissions' => ['maxProcesses' => 2],
            'supervisor-default' => ['maxProcesses' => 3],
        ],

        'local' => [
            'supervisor-dialer' => ['maxProcesses' => 3],
            'supervisor-webhooks' => ['maxProcesses' => 2],
            'supervisor-leads' => ['maxProcesses' => 2],
            'supervisor-background' => ['maxProcesses' => 2],
            'supervisor-commissions' => ['maxProcesses' => 1],
            'supervisor-default' => ['maxProcesses' => 2],
        ],
    ],
];
