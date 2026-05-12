<?php

declare(strict_types=1);

return [

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
     * Disk used by ListingController for photo uploads. Defaults to
     * 'public' (works on local dev where storage:link is reliable).
     * On Laravel Cloud, storage/app/public is ephemeral across deploys
     * and the public/storage symlink doesn't survive container
     * rebuilds — set LISTING_PHOTOS_DISK=listing_photos there to route
     * uploads through the Cloud-managed R2 bucket instead.
     */
    'listing_photos_disk' => env('LISTING_PHOTOS_DISK', 'public'),

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
        ],

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

        /*
         * Cloud-managed object storage for listing photos. Cloudflare
         * R2 is S3-compatible, so we ride the s3 driver with R2-shaped
         * env vars. The `url` is the bucket's public URL (set when the
         * bucket is created with visibility=public on Cloud); reads
         * come from there without signing.
         *
         * Required env (set via Cloud dashboard or `cloud env:vars`):
         *   LISTING_PHOTOS_BUCKET            bucket name
         *   LISTING_PHOTOS_ENDPOINT          https://<account>.r2.cloudflarestorage.com
         *   LISTING_PHOTOS_URL               https://<bucket-public-url>
         *   LISTING_PHOTOS_KEY               access key id
         *   LISTING_PHOTOS_SECRET            secret access key
         *   LISTING_PHOTOS_REGION            auto (R2 default) or aws region
         */
        'listing_photos' => [
            'driver' => 's3',
            'key' => env('LISTING_PHOTOS_KEY'),
            'secret' => env('LISTING_PHOTOS_SECRET'),
            'region' => env('LISTING_PHOTOS_REGION', 'auto'),
            'bucket' => env('LISTING_PHOTOS_BUCKET'),
            'url' => env('LISTING_PHOTOS_URL'),
            'endpoint' => env('LISTING_PHOTOS_ENDPOINT'),
            // R2 requires path-style endpoint addressing.
            'use_path_style_endpoint' => true,
            'visibility' => 'public',
            'throw' => false,
        ],

    ],

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
