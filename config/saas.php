<?php

return [
    'default_trial_days' => (int) env('SAAS_DEFAULT_TRIAL_DAYS', 30),

    'brand_name' => env('SAAS_BRAND_NAME', 'Bingoo POS'),

    'brand_tagline' => env(
        'SAAS_BRAND_TAGLINE',
        'Cloud POS for retail, restaurants, and inventory teams.'
    ),

    'brand_logo' => env('SAAS_BRAND_LOGO', 'images/brand/bingoo-pos-logo.svg'),

    'brand_mark' => env('SAAS_BRAND_MARK', 'images/brand/bingoo-pos-mark.svg'),

    'og_image' => env('SAAS_OG_IMAGE', 'images/data/Banner-Full.webp'),

    'contact' => [
        'sales_email'   => env('SAAS_SALES_EMAIL', 'sales@bingoopos.com'),
        'support_email' => env('SAAS_SUPPORT_EMAIL', 'support@bingoopos.com'),
        'website'       => env('SAAS_WEBSITE_URL', 'https://bingoopos.com'),
        'phone'         => env('SAAS_CONTACT_PHONE', '+92 XXX XXXXXXX'),
    ],

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
        'demos',
        'retaildemo',
        'inventorydemo',
        'restaurantdemo',
        'restaurantprodemo',
        'enterprisedemo',
    ],

    'demos' => [
        'enabled' => (bool) env('SAAS_DEMOS_ENABLED', true),
        'default_password' => env('SAAS_DEMO_DEFAULT_PASSWORD', 'demo1234'),
        'allowlist' => [
            'demo',
            'retaildemo',
            'inventorydemo',
            'restaurantdemo',
            'restaurantprodemo',
            'enterprisedemo',
        ],
        'tenant_codes' => [
            'retail' => 'retaildemo',
            'inventory' => 'inventorydemo',
            'restaurant' => 'restaurantdemo',
            'restaurant_pro' => 'restaurantprodemo',
            'enterprise' => 'enterprisedemo',
        ],
    ],
];
