<?php

declare(strict_types=1);

use App\Core\Shared\TenantContext;
use App\Listeners\FlushTenantContext;
use Laravel\Octane\Contracts\OperationTerminated;
use Laravel\Octane\Events\RequestHandled;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Events\TaskReceived;
use Laravel\Octane\Events\TaskTerminated;
use Laravel\Octane\Events\TickReceived;
use Laravel\Octane\Events\TickTerminated;
use Laravel\Octane\Events\WorkerErrorOccurred;
use Laravel\Octane\Events\WorkerStarting;
use Laravel\Octane\Events\WorkerStopping;
use Laravel\Octane\Listeners\CollectGarbage;
use Laravel\Octane\Listeners\DisconnectFromDatabases;
use Laravel\Octane\Listeners\EnsureUploadedFilesAreValid;
use Laravel\Octane\Listeners\FlushOnce;
use Laravel\Octane\Listeners\FlushQueuedCookies;
use Laravel\Octane\Listeners\FlushTemporaryContainerInstances;
use Laravel\Octane\Listeners\FlushUploadedFiles;
use Laravel\Octane\Listeners\ReportException;
use Laravel\Octane\Listeners\StopWorkerIfNecessary;

return [

    /*
     * Default to FrankenPHP — Laravel Cloud's Octane runtime ships with FrankenPHP
     * but does not have the Swoole extension. Local Docker (see Dockerfile) overrides
     * this to `swoole` for development. To run Swoole locally, set OCTANE_SERVER=swoole
     * in your .env file.
     */
    'server' => env('OCTANE_SERVER', 'frankenphp'),

    'https' => (bool) env('OCTANE_HTTPS', false),

    'listeners' => [

        WorkerStarting::class => [],

        RequestReceived::class => [
            EnsureUploadedFilesAreValid::class,
        ],

        RequestHandled::class => [],

        RequestTerminated::class => [
            FlushUploadedFiles::class,
            FlushQueuedCookies::class,
            // Per-request tenant binding must not leak between requests in a
            // long-lived worker. See app/Listeners/FlushTenantContext.php.
            FlushTenantContext::class,
        ],

        TaskReceived::class => [],

        TaskTerminated::class => [],

        TickReceived::class => [],

        TickTerminated::class => [],

        OperationTerminated::class => [
            FlushOnce::class,
            FlushTemporaryContainerInstances::class,
        ],

        WorkerErrorOccurred::class => [
            ReportException::class,
            StopWorkerIfNecessary::class,
        ],

        WorkerStopping::class => [
            CollectGarbage::class,
            DisconnectFromDatabases::class,
        ],

    ],

    'warm' => [
        'auth',
        'cache',
        'cache.store',
        'config',
        'cookie',
        'db',
        'db.factory',
        'encrypter',
        'files',
        'hash',
        'log',
        'router',
        'routes',
        'session',
        'session.store',
        'translator',
        'url',
        'view',
    ],

    'flush' => [
        TenantContext::class,
    ],

    'cache' => [
        'rows' => 1000,
        'bytes' => 10000,
    ],

    'tables' => [],

];
