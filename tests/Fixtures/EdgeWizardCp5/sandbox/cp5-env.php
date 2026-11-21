<?php

/**
 * CP5 (stream B) - the DISPOSABLE Cloud that runs INSIDE the Windows Sandbox (php -S + its own mysqld on 127.0.0.1:3407).
 * Paths come from the driver's environment (CP5_*), so nothing here names a host path:
 *   CP5_TREE   the Cloud code, mapped READ-ONLY (git archive of the built commit + copied no-dev vendor)
 *   CP5_WORK   sandbox-local writable work dir (Laravel storage + caches, keys, state) - dies with the sandbox
 *   CP5_KIT    the kit, mapped READ-ONLY (dev-public-facts.json = the DEV update PUBLIC key)
 * Applied over the tree's minimal .env (process environment wins). Lease TTL stays at the production default (120 s).
 */

function cp5_cfg(string $k, string $default): string
{
    $v = getenv($k);

    return ($v === false || $v === '') ? $default : $v;
}

define('CP5_TREE', cp5_cfg('CP5_TREE', 'C:/cp5/cloudsrc'));
define('CP5_WORK', cp5_cfg('CP5_WORK', 'C:/cp5w'));
define('CP5_KIT', cp5_cfg('CP5_KIT', 'C:/cp5/kit'));
define('CP5_PORT', (int) cp5_cfg('CP5_CLOUD_PORT', '9831'));
define('CP5_DB_PORT', cp5_cfg('CP5_CLOUD_DB_PORT', '3407'));
define('CP5_MASTER_DB', 'e09b_cp5_master_edge');
define('CP5_TENANT_CODE', 'cp5edgewiz');            // tenant DB = pos_tenant_cp5edgewiz
define('CP5_CLOUD_DIR', CP5_WORK . '/cloud');

function cp5_keys(): array
{
    $file = CP5_CLOUD_DIR . '/keys.json';
    if (! is_file($file)) {
        @mkdir(dirname($file), 0700, true);
        $kp = sodium_crypto_sign_keypair();
        file_put_contents($file, json_encode([
            'app_key' => 'base64:' . base64_encode(random_bytes(32)),
            'enrollment_secret' => base64_encode(sodium_crypto_sign_secretkey($kp)),
            'enrollment_public' => base64_encode(sodium_crypto_sign_publickey($kp)),
        ], JSON_PRETTY_PRINT));
    }

    return json_decode((string) file_get_contents($file), true);
}

function cp5_env(): array
{
    $keys = cp5_keys();
    $dev = json_decode((string) file_get_contents(CP5_KIT . '/dev-public-facts.json'), true);
    // Laravel treats only paths starting with '/' or '\' as absolute for APP_*_CACHE (a "C:" path is glued to the base path):
    // use the drive-relative form - the work dir and the processes' current directory are on the same drive (C:).
    $cache = preg_replace('#^[A-Za-z]:#', '', str_replace('\\', '/', CP5_CLOUD_DIR)) . '/cache';

    return [
        'APP_ENV' => 'local', 'APP_DEBUG' => 'true', 'APP_ROLE' => 'cloud', 'APP_KEY' => $keys['app_key'],
        'APP_URL' => 'http://127.0.0.1:' . CP5_PORT,
        'CENTRAL_DOMAIN' => '127.0.0.1', 'TENANT_BASE_DOMAIN' => 'cp5.test',
        'DB_CONNECTION' => 'master', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => CP5_DB_PORT, 'DB_DATABASE' => CP5_MASTER_DB, 'DB_USERNAME' => 'root', 'DB_PASSWORD' => '',
        'TENANT_DB_HOST' => '127.0.0.1', 'TENANT_DB_PORT' => CP5_DB_PORT, 'TENANT_DB_USERNAME' => 'root', 'TENANT_DB_PASSWORD' => '',
        'EDGE_FEATURE_ENABLED' => 'true',
        'EDGE_ENROLLMENT_SIGNING_KEY' => $keys['enrollment_secret'],
        'EDGE_UPDATE_PUBLIC_KEY' => (string) $dev['public_key'],   // the DEV key (rehearsal only; production verifies with the release key)
        'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'file', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
        'BROADCAST_CONNECTION' => 'log', 'LOG_CHANNEL' => 'single', 'LOG_LEVEL' => 'info',
        'PULSE_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false',
        // the tree is mapped read-only: storage and every bootstrap cache live in the sandbox work dir
        'LARAVEL_STORAGE_PATH' => CP5_CLOUD_DIR . '/storage',
        'APP_SERVICES_CACHE' => $cache . '/services.php', 'APP_PACKAGES_CACHE' => $cache . '/packages.php',
        'APP_CONFIG_CACHE' => $cache . '/config.php', 'APP_ROUTES_CACHE' => $cache . '/routes-v7.php', 'APP_EVENTS_CACHE' => $cache . '/events.php',
    ];
}

function cp5_apply_env(): void
{
    foreach (['storage/app/private', 'storage/app/public', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'cache'] as $d) {
        if (! is_dir(CP5_CLOUD_DIR . '/' . $d)) {
            @mkdir(CP5_CLOUD_DIR . '/' . $d, 0700, true);
        }
    }
    foreach (cp5_env() as $k => $v) {
        putenv("{$k}={$v}");
        $_ENV[$k] = $v;
        $_SERVER[$k] = $v;
    }
    foreach (['BINGOO_EDGE_ENV_DIR', 'EDGE_LOCAL_APP_KEY', 'EDGE_DB_DATABASE'] as $k) {
        putenv($k);
        unset($_ENV[$k], $_SERVER[$k]);
    }
}
