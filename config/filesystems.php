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

    ],

    /*
    | Railway Bucket credentials. Read once here so config:cache keeps them.
    | CloudStorageManager::railwayEnv() consumes this array.
    */
    'railway_bucket' => [
        'id' => env('RAILWAY_BUCKET_ID', ''),
        'key' => env('AWS_ACCESS_KEY_ID', ''),
        'secret' => env('AWS_SECRET_ACCESS_KEY', ''),
        'bucket' => env('AWS_BUCKET', ''),
        'region' => env('AWS_DEFAULT_REGION', ''),
        'endpoint' => env('AWS_ENDPOINT', ''),
        'access_key_id' => env('ACCESS_KEY_ID', ''),
        'bucket_access_key_id' => env('BUCKET_ACCESS_KEY_ID', ''),
        'secret_access_key' => env('SECRET_ACCESS_KEY', ''),
        'bucket_secret_access_key' => env('BUCKET_SECRET_ACCESS_KEY', ''),
        'name' => env('BUCKET', ''),
        'name_alt' => env('BUCKET_NAME', ''),
        'region_alt' => env('REGION', ''),
        'endpoint_alt' => env('ENDPOINT', ''),
        'bucket_endpoint' => env('BUCKET_ENDPOINT', ''),
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
