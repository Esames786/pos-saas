<?php

return [
    'default_trial_days' => (int) env('SAAS_DEFAULT_TRIAL_DAYS', 30),

    'brand_name' => env('SAAS_BRAND_NAME', 'Bingoo'),

    'brand_tagline' => env(
        'SAAS_BRAND_TAGLINE',
        'The Ultimate Boss of Retail & Restaurant Software'
    ),

    'brand_logo' => env('SAAS_BRAND_LOGO', 'images/bingoo_new/bingoo-navbar-logo.webp'),

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
            'financedemo',
        ],
        'tenant_codes' => [
            'retail' => 'retaildemo',
            'inventory' => 'inventorydemo',
            'restaurant' => 'restaurantdemo',
            'restaurant_pro' => 'restaurantprodemo',
            'enterprise' => 'enterprisedemo',
            'finance' => 'financedemo',
        ],

        // Tenants that demo:reset / demo:reset-all are allowed to drop & recreate (15D-8).
        // Deliberately EXCLUDES the legacy 'demo' tenant (which is in 'allowlist').
        'reset_tenant_codes' => [
            'retaildemo',
            'inventorydemo',
            'restaurantdemo',
            'restaurantprodemo',
            'enterprisedemo',
            'financedemo',
        ],

        // Time-of-day for the optional nightly demo reset scheduler (24h HH:MM).
        'reset_daily_at' => env('SAAS_DEMOS_RESET_DAILY_AT', '04:00'),

        // Public /demos page metadata (15D-7). Drives the live demo cards.
        // 'key' is also the anchor id used in /demos#<key> deep links.
        'cards' => [
            'retail' => [
                'title'        => 'Retail Demo',
                'tenant_code'  => 'retaildemo',
                'plan_code'    => 'retail_starter',
                'badge'        => 'Retail Starter',
                'icon'         => 'ti-building-store',
                'image'        => 'images/data/retailers.webp',
                'description'  => 'Test barcode checkout, products, customers, receipts, and retail sales reports.',
                'email'        => 'demo@retaildemo.com',
                'cta_type'     => 'trial',
                'cta_plan'     => 'retail_starter',
                'bullets'      => [
                    'Fast POS checkout',
                    'Barcode-style product search',
                    'Sample customers and paid sales',
                    'Receipt-ready sales flow',
                ],
            ],
            'inventory' => [
                'title'        => 'Inventory Demo',
                'tenant_code'  => 'inventorydemo',
                'plan_code'    => 'inventory_store',
                'badge'        => 'Inventory Store',
                'icon'         => 'ti-package',
                'image'        => 'images/data/inventory.jpg',
                'description'  => 'Explore purchasing, suppliers, GRNs, stock transfers, low-stock items, and inventory reports.',
                'email'        => 'demo@inventorydemo.com',
                'cta_type'     => 'trial',
                'cta_plan'     => 'inventory_store',
                'bullets'      => [
                    'Purchase orders and GRNs',
                    'Supplier and bill examples',
                    'Warehouse to store transfer',
                    'Low-stock product examples',
                ],
            ],
            'restaurant' => [
                'title'        => 'Restaurant Demo',
                'tenant_code'  => 'restaurantdemo',
                'plan_code'    => 'restaurant_starter',
                'badge'        => 'Restaurant Starter',
                'icon'         => 'ti-tools-kitchen-2',
                'image'        => 'images/data/restaurant.png',
                'description'  => 'Try tables, waiters, dine-in orders, service charge, KOT-ready orders, and restaurant reporting.',
                'email'        => 'demo@restaurantdemo.com',
                'cta_type'     => 'trial',
                'cta_plan'     => 'restaurant_starter',
                'bullets'      => [
                    'Floors and tables',
                    'Waiter/order flow',
                    'Paid dine-in and takeaway orders',
                    'Service charge and sales controls',
                ],
            ],
            'restaurant_pro' => [
                'title'        => 'Restaurant Pro Demo',
                'tenant_code'  => 'restaurantprodemo',
                'plan_code'    => 'restaurant_pro',
                'badge'        => 'Restaurant Pro',
                'icon'         => 'ti-chef-hat',
                'image'        => 'images/data/Banner-Full.webp',
                'description'  => 'Test KDS, recipes/BOM, kitchen inventory, purchasing, ingredient stock, and advanced restaurant workflows.',
                'email'        => 'demo@restaurantprodemo.com',
                'cta_type'     => 'trial',
                'cta_plan'     => 'restaurant_pro',
                'bullets'      => [
                    'Kitchen Display orders',
                    'Recipes and ingredient consumption',
                    'Kitchen stock and purchasing',
                    'Accounting: expenses, ledger, P&L & balance sheet',
                ],
            ],
            'enterprise' => [
                'title'        => 'Enterprise Demo',
                'tenant_code'  => 'enterprisedemo',
                'plan_code'    => 'enterprise',
                'badge'        => 'Enterprise',
                'icon'         => 'ti-building-skyscraper',
                'image'        => 'images/data/Supermarket-Grocery-POS-Billing-Software.jpg',
                'description'  => 'Explore a multi-branch rollout with retail, restaurant, warehouse, purchasing, roles, terminals, and branch reporting.',
                'email'        => 'demo@enterprisedemo.com',
                'cta_type'     => 'contact',
                'cta_plan'     => 'enterprise',
                'bullets'      => [
                    '4 branches and 6 terminals',
                    'Branch-level sales comparison',
                    'Full accounting: GL, P&L, branch-wise P&L, balance sheet',
                    'Enterprise users and safe roles',
                ],
            ],
            'finance' => [
                'title'        => 'Finance ERP Demo',
                'tenant_code'  => 'financedemo',
                'plan_code'    => 'finance_erp',
                'badge'        => 'Finance + ERP',
                'icon'         => 'ti-report-money',
                'image'        => 'images/data/dashbaord.png',
                'description'  => 'Explore accounting, purchasing, inventory, receivables/payables, financial reports, and planned ERP/manufacturing extensions.',
                'email'        => 'demo@financedemo.com',
                'cta_type'     => 'contact',
                'cta_plan'     => 'finance_erp',
                'bullets'      => [
                    'Journal Entries, General Ledger, Trial Balance',
                    'P&L, Balance Sheet, Branch-wise P&L',
                    'Opening balances, suppliers, customers',
                    'PO → GRN and inventory movement',
                    'ERP extensions marked Coming Soon',
                ],
            ],
        ],
    ],
];
