<?php

return [
    'central_domain' => env('CENTRAL_DOMAIN', 'mywebsite.test'),
    'tenant_base_domain' => env('TENANT_BASE_DOMAIN', 'mywebsite.test'),

    'master_connection' => 'master',
    'tenant_connection' => 'tenant',
];
