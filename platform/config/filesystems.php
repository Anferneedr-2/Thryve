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

        // Private user files: not web-served, errors throw. Access only via authorized routes.
        'private' => [
            'driver' => 'local',
            'root' => storage_path('app/private/files'),
            'visibility' => 'private',
            'serve' => false,
            'throw' => true,
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

        // Off-server backups (decision D10): SFTP to the Hermes VPS, a different machine from the web host. Used only when
        // THRYVE_BACKUP_OFFSITE_DISK=offsite is set in .env. Use a dedicated VPS user with a key and no shell rights beyond
        // its own backup folder.
        'offsite' => [
            'driver' => 'sftp',
            'host' => env('THRYVE_OFFSITE_HOST'),
            'port' => (int) env('THRYVE_OFFSITE_PORT', 22),
            'username' => env('THRYVE_OFFSITE_USER'),
            'privateKey' => env('THRYVE_OFFSITE_KEY_PATH'),
            'passphrase' => env('THRYVE_OFFSITE_KEY_PASSPHRASE'),
            'hostFingerprint' => env('THRYVE_OFFSITE_HOST_FINGERPRINT'),
            'root' => env('THRYVE_OFFSITE_PATH', '/var/backups/thryve'),
            'timeout' => 30,
            'throw' => true,
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
