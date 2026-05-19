<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        // 2026-05-19: DigitalOcean Spaces (compatível S3) para anexos
        // de tenders + biblioteca técnica + filesystem mirror de agent
        // shares. Activar mudando FILESYSTEM_DISK=spaces no .env, depois
        // correr `php artisan tenders:migrate-attachments-to-spaces`.
        //
        // Setup (uma vez):
        //   1. DO Panel → Spaces → Create Space (ex.: nome "clawyard-prod",
        //      região "fra1" ou "ams3" para baixa latência da Europa)
        //   2. DO Panel → API → Spaces Keys → Generate New Key
        //      → guarda access_key + secret
        //   3. No .env do Forge:
        //      DO_SPACES_KEY=<access_key>
        //      DO_SPACES_SECRET=<secret>
        //      DO_SPACES_REGION=fra1
        //      DO_SPACES_BUCKET=clawyard-prod
        //      DO_SPACES_ENDPOINT=https://fra1.digitaloceanspaces.com
        //   4. composer require league/flysystem-aws-s3-v3 (já testado 3.x)
        //   5. php artisan config:cache
        'spaces' => [
            'driver'   => 's3',
            'key'      => env('DO_SPACES_KEY'),
            'secret'   => env('DO_SPACES_SECRET'),
            'region'   => env('DO_SPACES_REGION', 'fra1'),
            'bucket'   => env('DO_SPACES_BUCKET'),
            'endpoint' => env('DO_SPACES_ENDPOINT', 'https://fra1.digitaloceanspaces.com'),
            // Path-style addressing porque o endpoint não tem o bucket no DNS
            'use_path_style_endpoint' => false,
            'visibility' => 'private',
            'throw'      => false,
            'report'     => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
