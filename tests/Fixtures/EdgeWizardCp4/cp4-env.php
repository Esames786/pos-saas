<?php

/**
 * CP4 (stream B) — the DISPOSABLE Cloud's environment for the 0.8.5 -> 0.9 upgrade and auto-update rehearsal.
 * Shared by the php -S router (cp4-router.php) and the Cloud driver (cp4-cloud.php). Applied over the worktree .env (process
 * environment wins over .env in Laravel). Databases are disposable rehearsal ones; nothing here is a production secret: the
 * Cloud APP_KEY and the enrollment signing key are generated once per rehearsal into <CP4>\cloud\keys.json (user-only dir).
 */

const CP4_DIR = 'C:\\Users\\Dell\\BingooEdgeLab\\e09\\b\\cp4';
const CP4_PORT = 9821;
const CP4_MASTER_DB = 'e09b_cp4_master_edge';
const CP4_TENANT_CODE = 'cp4edgewiz';           // tenant DB = pos_tenant_cp4edgewiz (TenantProvisioner naming)
const CP4_DEV_PUBLIC_FACTS = 'C:\\Users\\Dell\\BingooEdgeLab\\e09\\dev-signing\\dev-public-facts.json';

function cp4_keys(): array
{
    $file = CP4_DIR . '\\cloud\\keys.json';
    if (! is_file($file)) {
        @mkdir(dirname($file), 0700, true);
        $kp = sodium_crypto_sign_keypair();
        $keys = [
            'app_key' => 'base64:' . base64_encode(random_bytes(32)),
            'enrollment_secret' => base64_encode(sodium_crypto_sign_secretkey($kp)),
            'enrollment_public' => base64_encode(sodium_crypto_sign_publickey($kp)),
        ];
        file_put_contents($file, json_encode($keys, JSON_PRETTY_PRINT));
    }

    return json_decode((string) file_get_contents($file), true);
}

function cp4_env(): array
{
    $keys = cp4_keys();
    $dev = json_decode((string) file_get_contents(CP4_DEV_PUBLIC_FACTS), true);

    return [
        'APP_ENV' => 'local', 'APP_DEBUG' => 'true', 'APP_ROLE' => 'cloud', 'APP_KEY' => $keys['app_key'],
        'APP_URL' => 'http://127.0.0.1:' . CP4_PORT,
        'CENTRAL_DOMAIN' => '127.0.0.1', 'TENANT_BASE_DOMAIN' => 'cp4.test',
        'DB_CONNECTION' => 'master', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_DATABASE' => CP4_MASTER_DB, 'DB_USERNAME' => 'root', 'DB_PASSWORD' => '',
        'TENANT_DB_HOST' => '127.0.0.1', 'TENANT_DB_PORT' => '3306', 'TENANT_DB_USERNAME' => 'root', 'TENANT_DB_PASSWORD' => '',
        'EDGE_FEATURE_ENABLED' => 'true',
        'EDGE_ENROLLMENT_SIGNING_KEY' => $keys['enrollment_secret'],
        'EDGE_UPDATE_PUBLIC_KEY' => (string) $dev['public_key'],     // the DEV key (rehearsal only; production verifies with the release key)
        'EDGE_AUTHORITY_TTL' => '20',                                 // short lease so a real takeover is reachable in the rehearsal
        'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'file', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
        'BROADCAST_CONNECTION' => 'log', 'LOG_CHANNEL' => 'single', 'LOG_LEVEL' => 'debug',
        'PULSE_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false',
    ];
}

function cp4_apply_env(): void
{
    foreach (cp4_env() as $k => $v) {
        putenv("{$k}={$v}");
        $_ENV[$k] = $v;
        $_SERVER[$k] = $v;
    }
    foreach (['BINGOO_EDGE_ENV_DIR', 'EDGE_LOCAL_APP_KEY', 'EDGE_DB_DATABASE'] as $k) {
        putenv($k);
        unset($_ENV[$k], $_SERVER[$k]);
    }
}
