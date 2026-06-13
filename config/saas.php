<?php

return [
    'default_trial_days' => (int) env('SAAS_DEFAULT_TRIAL_DAYS', 14),

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
