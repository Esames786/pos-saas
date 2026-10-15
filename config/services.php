<?php

return [

    /*
     * WHATSAPP-REPORT-CHANNEL-1 — Cloud API, direct. No BSP: a provider wanted PKR 14,000/month to
     * wrap one HTTP call, against a message bill of roughly PKR 840.
     *
     * The template is APPROVED and therefore frozen — its name, language and five body variables
     * cannot change without a new Meta review. 'language' is 'en', NOT 'en_US': the approved
     * template is English, while the two sample templates on the same account are English (US).
     * The wrong code is not a warning, it is a failed send.
     */
    'whatsapp' => [
        'base_url'        => env('WHATSAPP_BASE_URL', 'https://graph.facebook.com'),
        'version'         => env('WHATSAPP_API_VERSION', 'v25.0'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        // WABA id sirf analytics ke liye: bhejne me phone_number_id chalta hai, magar ginti ke
        // aankRe WhatsApp Business Account par milte hain, number par nahi.
        'waba_id'         => env('WHATSAPP_WABA_ID'),
        'token'           => env('WHATSAPP_TOKEN'),
        'template'        => env('WHATSAPP_TEMPLATE', 'daily_sales_report'),
        'language'        => env('WHATSAPP_TEMPLATE_LANG', 'en'),

        // ONLINE-ORDERING-1: website order messages — Meta par jis naam se approve hue (naam badla nahi ja sakta).
        'web_templates'   => [
            'received'  => env('WHATSAPP_WEB_TPL_RECEIVED', 'web_order_received'),
            'confirmed' => env('WHATSAPP_WEB_TPL_CONFIRMED', 'web_order_confirmed'),
            'cancelled' => env('WHATSAPP_WEB_TPL_CANCELLED', 'web_order_cancelled'),
        ],

        // Tenant se per message kitna liya jata hai, aur hamein kitna paRta hai. DONO har usage row
        // par likhe jate hain, wahan se parhe nahi jate — rate badle to purane invoice nahi hilne
        // chahiyen, aur margin naapa jana chahiye, farz nahi kiya jana.
        //
        // ONLINE-ORDERING-1: asal rate ab master `platform_settings` me hai (platform admin →
        // Billing rates). Ye do sirf SAHARA hain jab wahan row na ho — parhna hamesha
        // `App\Services\Saas\BillingRates` se.
        'rate_pkr'        => env('WHATSAPP_RATE_PKR', 9.85),
        'cost_pkr'        => env('WHATSAPP_COST_PKR', 5.95),
    ],

    // ONLINE-ORDERING-1: website orders par Bingoo ka commission (%). Asal qeemat master
    // `platform_settings` me; ye sirf sahara. Parhna `App\Services\Saas\BillingRates` se.
    'online_ordering' => [
        'commission_percent' => env('WEBSITE_ORDER_COMMISSION_PERCENT', 1),
    ],


    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
