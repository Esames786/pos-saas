<?php

return [
    'default_trial_days' => (int) env('SAAS_DEFAULT_TRIAL_DAYS', 14),

    'default_currency' => env('SAAS_DEFAULT_CURRENCY', 'PKR'),

    'enabled_currencies' => array_values(array_filter(array_map('trim', explode(',', env('SAAS_ENABLED_CURRENCIES', 'PKR'))))),

    'default_locale' => env('SAAS_DEFAULT_LOCALE', 'en'),

    'enabled_locales' => array_values(array_filter(array_map('trim', explode(',', env('SAAS_ENABLED_LOCALES', 'en,ur'))))),

    'reserved_subdomains' => [
        'www',
        'app',
        'admin',
        'api',
        'mail',
        'support',
        'billing',
        'central',
        'demo',
        'pos-saas',
        'assets',
        'static',
        'cdn',
        'login',
        'register',
        'signup',
        'trial',
        'pricing',
    ],
];
