<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Role (Branch Edge)
    |--------------------------------------------------------------------------
    |
    | 'cloud'          — the central SaaS instance (default).
    | 'branch_server'  — an on-prem Branch Server running Local POS Mode for one
    |                    branch, hard-bound via edge_tenant_code / edge_branch_id.
    |
    | SECURITY: role + edge binding come ONLY from the environment/config, NEVER
    | from HTTP request data. A branch server may only mutate its bound branch.
    |
    */

    'role' => env('APP_ROLE', 'cloud'),

    'edge_tenant_code' => env('EDGE_TENANT_CODE'),

    'edge_branch_id' => env('EDGE_BRANCH_ID'),

    // Local POS setup journey (pairing/bootstrap/sync) is incomplete — keep OFF
    // in production until it is ready. Existing cloud POS is unaffected either way.
    'edge_feature_enabled' => env('EDGE_FEATURE_ENABLED', false),

    // OFFLINE-EDGE-ENTITLEMENT-1: the Bingoo Edge installer (BingooEdgeSetup.exe)
    // does NOT exist yet. These stay unset until EDGE-BUILD-PACKAGING-1 produces a
    // real, signed artifact. Availability is derived from these config values ONLY
    // (never from request input); an absent/invalid artifact yields a controlled
    // EDGE_INSTALLER_NOT_AVAILABLE response, never a fake file.
    'edge_installer_path'           => env('EDGE_INSTALLER_PATH'),
    'edge_installer_version'        => env('EDGE_INSTALLER_VERSION'),
    'edge_installer_sha256'         => env('EDGE_INSTALLER_SHA256'),
    'edge_installer_signature_path' => env('EDGE_INSTALLER_SIGNATURE_PATH'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];
