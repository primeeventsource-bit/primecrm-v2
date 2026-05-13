<?php

declare(strict_types=1);

return [

    'default' => env('FILESYSTEM_DISK', 'local'),

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
        ],

        /*
         * `public` is the disk ListingController writes photos to.
         *
         * On Laravel Cloud: the platform reads LARAVEL_CLOUD_DISK_CONFIG
         * at container start and REPLACES the values below with the
         * Default bucket's R2-backed s3 driver config. The keys here
         * are only the local-dev fallback — they're ignored in
         * production. Verify with `Storage::disk('public')->url('x')`:
         * Cloud returns the bucket's CDN URL, local returns
         * `APP_URL/storage/x`.
         *
         * Don't add a parallel "listing_photos" or "r2" disk here for
         * Cloud — Cloud's auto-binding already covers it. See
         * docs/ARCHITECTURE.md for the rationale.
         */
        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => (bool) env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
        ],

    ],

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
