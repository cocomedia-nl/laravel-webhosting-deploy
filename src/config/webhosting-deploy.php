<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Hosting Driver
    |--------------------------------------------------------------------------
    |
    | Supported: "directadmin", "transip".
    | Existing DIRECTADMIN_* environment variables are used as fallbacks.
    |
    */
    'driver' => env('WEBHOSTING_DRIVER', 'directadmin'),

    /*
    |--------------------------------------------------------------------------
    | SSH Connection Settings
    |--------------------------------------------------------------------------
    */
    'ssh' => [
        'host' => env('WEBHOSTING_SSH_HOST', env('DIRECTADMIN_SSH_HOST')),
        'username' => env('WEBHOSTING_SSH_USERNAME', env('DIRECTADMIN_SSH_USERNAME')),
        'port' => env('WEBHOSTING_SSH_PORT', env('DIRECTADMIN_SSH_PORT', 22)),
        'timeout' => 30,
        'deploy_timeout' => 600,
    ],

    /*
    |--------------------------------------------------------------------------
    | Deployment Settings
    |--------------------------------------------------------------------------
    */
    'deployment' => [
        'site_dir' => env('WEBHOSTING_SITE_DIR', env('DIRECTADMIN_SITE_DIR')),
        'app_path' => env('WEBHOSTING_APP_PATH'),
        'composer_flags' => '--no-dev --optimize-autoloader',
        'run_migrations' => true,
        'run_storage_link' => true,
        'run_config_cache' => false,
        'run_route_cache' => false,
        'run_view_cache' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | GitHub Actions Settings
    |--------------------------------------------------------------------------
    */
    'github' => [
        'workflow_file' => '.github/workflows/webhosting-deploy.yml',
        'php_version' => '8.3',
        'default_branch' => 'main',
        'api_token' => env('GITHUB_API_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Server Paths
    |--------------------------------------------------------------------------
    |
    | Relative segments used by the DirectAdmin and TransIP drivers.
    | Override WEBHOSTING_APP_PATH for a custom path from the SSH home directory.
    |
    */
    'paths' => [
        'domains' => 'domains',
        'public_html' => 'public_html',
        'public' => 'public',
        'laravel_html' => 'laravel_html',
        'transip_www' => 'www',
    ],
];
