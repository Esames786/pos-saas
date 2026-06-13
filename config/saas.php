<?php

return [
    'default_trial_days' => (int) env('SAAS_DEFAULT_TRIAL_DAYS', 30),

    'brand_name' => env('SAAS_BRAND_NAME', 'Habibi POS'),

    'brand_tagline' => env(
        'SAAS_BRAND_TAGLINE',
        'Cloud POS for retail, restaurants, and inventory teams.'
    ),

    'brand_logo' => env('SAAS_BRAND_LOGO', 'images/brand/habibi-pos-logo.svg'),

    'brand_mark' => env('SAAS_BRAND_MARK', 'images/brand/habibi-pos-mark.svg'),

    'og_image' => env('SAAS_OG_IMAGE', 'images/data/Banner-Full.webp'),

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
