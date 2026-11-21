<?php

/**
 * CP5 scenario s (stream D) - Cloud-side helper for the cashier SELF-SERVICE proof, run INSIDE the sandbox with the package php
 * against the SAME disposable Cloud as stream B's cp5-cloud.php (same env, DBs, keys):
 *
 *   php cp5-selfservice.php prepare <secretsDir>                 the scenario users (passwords read from <secretsDir>\s-web-<CODE>.keep)
 *   php cp5-selfservice.php tls <publicDir> <keyFile>            a sandbox-only TEST CA + a server cert for *.cp5.test (Online POS TLS)
 *   php cp5-selfservice.php verify-download <file> <userId>      independent check of the downloaded counter setup (sodium, DB facts)
 *   php cp5-selfservice.php assertion-variant <file> <kind> <out>  tenant | branch | badsig: a NEGATIVE assertion for the Branch Server
 *   php cp5-selfservice.php audit <userId>                       the Cloud audit lines for the user's self-service downloads (counts)
 *
 * Prints ONE JSON document. Never prints a password, a PIN, an assertion, a signature or a private key.
 */

require __DIR__ . '/cp5-env.php';

if (($argv[1] ?? '') === 'tls') {
        // A sandbox-ONLY test CA (not Bingoo) and a server certificate for the Online POS host names. The CA is trusted in the
        // SANDBOX's LocalMachine\Root by the scenario (recorded thumbprint, removed at the end); the key stays in the secrets dir.
        [$publicDir, $keyFile] = [(string) ($argv[2] ?? ''), (string) ($argv[3] ?? '')];
        $cnf = tempnam(sys_get_temp_dir(), 'cp5s');
        file_put_contents($cnf, "[req]\ndistinguished_name=dn\n[dn]\n"
            . "[v3_ca]\nbasicConstraints=critical,CA:TRUE,pathlen:0\nkeyUsage=critical,keyCertSign,cRLSign\nsubjectKeyIdentifier=hash\n"
            . "[v3_srv]\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\n"
            . "subjectAltName=DNS:" . CP5_TENANT_CODE . ".cp5.test,DNS:*.cp5.test\nauthorityKeyIdentifier=keyid\n");
        $base = ['config' => $cnf, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048, 'digest_alg' => 'sha256'];
        $caKey = openssl_pkey_new($base);
        $caCert = openssl_csr_sign(openssl_csr_new(['commonName' => 'CP5 Sandbox Cloud Test CA', 'organizationName' => 'CP5 Rehearsal (not Bingoo)'], $caKey, $base), null, $caKey, 30, $base + ['x509_extensions' => 'v3_ca'], random_int(1, PHP_INT_MAX));
        $srvKey = openssl_pkey_new($base);
        $srvCert = openssl_csr_sign(openssl_csr_new(['commonName' => CP5_TENANT_CODE . '.cp5.test', 'organizationName' => 'CP5 Rehearsal (not Bingoo)'], $srvKey, $base), $caCert, $caKey, 30, $base + ['x509_extensions' => 'v3_srv'], random_int(1, PHP_INT_MAX));
        openssl_x509_export($caCert, $caPem);
        openssl_x509_export($srvCert, $srvPem);
        $exported = openssl_pkey_export($srvKey, $srvKeyPem, null, $base);   // on Windows the export needs the config too
        @unlink($cnf);
        if (! $exported || ! str_contains((string) $srvKeyPem, 'PRIVATE KEY')) {
            fwrite(STDERR, "could not export the TLS key\n");
            exit(1);
        }
        @mkdir($publicDir, 0700, true);
        file_put_contents($publicDir . '/cloud-test-ca.crt', $caPem);
        file_put_contents($publicDir . '/cloud-tls.crt', $srvPem . $caPem);
        file_put_contents($keyFile, $srvKeyPem);
        $der = fn (string $pem) => base64_decode(preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem));
        echo json_encode(['result' => 'ok', 'ca_thumbprint' => strtoupper(sha1($der($caPem))), 'ca_sha256' => strtoupper(hash('sha256', $der($caPem))),
            'ca_subject' => 'CN=CP5 Sandbox Cloud Test CA, O=CP5 Rehearsal (not Bingoo)', 'server_names' => [CP5_TENANT_CODE . '.cp5.test', '*.cp5.test']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

cp5_apply_env();

$root = CP5_TREE;
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Master\EdgeDevice;
use App\Models\Master\Tenant;
use App\Models\Tenant\User;
use App\Services\Edge\Cloud\CounterBundleService;
use App\Services\Edge\Cloud\DeviceProfileService;
use App\Services\Edge\EdgeCanonicalJson;
use App\Services\Edge\EdgeEnrollmentCrypto;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

const CP5_SS_PERMISSION = 'tenant.offline-edge.counter-install';

$out = function (array $doc): void {
    echo json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
};
$fail = function (string $why) {
    fwrite(STDERR, $why . "\n");
    exit(1);
};
$state = json_decode((string) @file_get_contents(CP5_CLOUD_DIR . '/state.json'), true) ?: [];
$tenant = Tenant::where('tenant_code', CP5_TENANT_CODE)->firstOrFail();
app(TenancyManager::class)->activate($tenant);
$device = EdgeDevice::active()->where('tenant_id', $tenant->id)->orderByDesc('id')->first();

/** Insert a row, filling every NOT NULL column without a default that the caller did not give (as stream B's seed does). */
$insert = function (string $table, array $values): int {
    $c = DB::connection('tenant');
    $cols = $c->select('SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
    foreach ($cols as $col) {
        $n = $col->COLUMN_NAME;
        if (array_key_exists($n, $values) || $col->IS_NULLABLE === 'YES' || $col->COLUMN_DEFAULT !== null || str_contains((string) $col->EXTRA, 'auto_increment')) {
            continue;
        }
        $t = strtolower((string) $col->DATA_TYPE);
        $values[$n] = match (true) {
            in_array($t, ['int', 'bigint', 'smallint', 'tinyint', 'mediumint', 'decimal', 'double', 'float'], true) => 0,
            in_array($t, ['datetime', 'timestamp'], true) => now(),
            $t === 'date' => now()->toDateString(),
            $t === 'enum' => trim(explode(',', substr((string) $col->COLUMN_TYPE, 5, -1))[0], "'"),
            $t === 'json' => '[]',
            default => 'cp5s',
        };
    }

    return (int) $c->table($table)->insertGetId($values);
};

/** The PowerShell single-quoted variables of a downloaded counter wizard. */
$wizardVars = function (string $file) use ($fail): array {
    $text = (string) @file_get_contents($file);
    if ($text === '') {
        $fail("cannot read {$file}");
    }
    $vars = [];
    foreach (['SetupKind', 'TenantLabel', 'BranchLabel', 'EdgeIp', 'EdgeHost', 'EdgeBaseByName', 'EdgeBaseByIp', 'UrlOnline', 'UrlOffline', 'UrlStatus', 'CaName',
        'CaThumbprint', 'CaSha256', 'CaSha256Display', 'SignedProfileB64', 'SignedProfileSig', 'SignedProfileKey', 'SignedProfileKeySha256', 'SignedProfileKeyId',
        'EnrollAssertionB64', 'EnrollUserLabel', 'TerminalLabel'] as $name) {
        $vars[$name] = preg_match('/^\$' . $name . '\s*=\s*\'((?:[^\']|\'\')*)\'/m', $text, $m) ? str_replace("''", "'", $m[1]) : null;
    }
    foreach (['EdgePort', 'EnrollExpiresAt', 'TerminalId'] as $name) {
        $vars[$name] = preg_match('/^\$' . $name . '\s*=\s*(\d+)/m', $text, $m) ? (int) $m[1] : null;
    }
    $vars['pem'] = preg_match('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $text, $m) ? $m[0] : null;
    $vars['sha256_of_file'] = strtoupper(hash('sha256', $text));

    return $vars;
};

$cmd = $argv[1] ?? '';
switch ($cmd) {
    case 'prepare':
        $dir = (string) ($argv[2] ?? '');
        $password = function (string $code) use ($dir, $fail): string {
            $f = $dir . DIRECTORY_SEPARATOR . 's-web-' . $code . '.keep';
            $v = trim((string) @file_get_contents($f));
            if (strlen($v) < 12) {
                $fail("missing password file for {$code}");
            }

            return $v;
        };
        $branchId = (int) ($state['branch_id'] ?? 0);
        $terminalId = (int) (($state['terminals'] ?? [])[0] ?? 0);
        $cashierRole = DB::connection('tenant')->table('roles')->where('guard_name', 'tenant')->where('name', 'like', '%ashier%')->value('name');
        $hasTerminalColumn = Schema::connection('tenant')->hasColumn('users', 'default_terminal_id');

        // a second branch WITHOUT a Branch Server (the "other branch" negative)
        $branch2 = (int) (DB::connection('tenant')->table('branches')->where('name', 'CP5 Branch Two')->value('id') ?: 0);
        if (! $branch2) {
            $branch2 = $insert('branches', ['code' => 'CP5B2-' . Str::upper(Str::random(4)), 'name' => 'CP5 Branch Two', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        }

        $spec = [
            'CP5C1' => ['email' => 'cp5c1@cp5edgewiz.test', 'name' => 'Counter One', 'branch' => $branchId, 'role' => true, 'terminal' => $terminalId],
            'CP5C2' => ['email' => 'cp5c2@cp5edgewiz.test', 'name' => 'Counter Two', 'branch' => $branchId, 'role' => true, 'terminal' => 0],
            'CP5NP' => ['email' => 'cp5np@cp5edgewiz.test', 'name' => 'No Permission', 'branch' => $branchId, 'role' => false, 'terminal' => 0],
            'CP5B2' => ['email' => 'cp5b2@cp5edgewiz.test', 'name' => 'Branch Two Cashier', 'branch' => $branch2, 'role' => true, 'terminal' => 0],
        ];
        $users = [];
        foreach ($spec as $code => $s) {
            $u = User::where('employee_code', $code)->first() ?? User::where('email', $s['email'])->first() ?? new User();
            $u->forceFill(['name' => $u->name ?: $s['name'], 'email' => $u->email ?: $s['email'], 'employee_code' => $code, 'status' => 'active',
                'default_branch_id' => $s['branch'], 'password' => Hash::make($password($code))]);
            if ($hasTerminalColumn) {
                $u->forceFill(['default_terminal_id' => $s['terminal'] ?: null]);
            }
            if (Schema::connection('tenant')->hasColumn('users', 'force_password_change')) {
                $u->forceFill(['force_password_change' => false]);
            }
            $u->save();
            if ($s['role'] && $cashierRole && ! $u->hasRole($cashierRole)) {
                $u->assignRole($cashierRole);
            }
            if (! $s['role']) {
                $u->syncRoles([]);
                $u->syncPermissions([]);
            }
            $u = $u->fresh();
            $users[$code] = ['id' => (int) $u->id, 'email' => (string) $u->email, 'branch_id' => (int) $u->default_branch_id, 'terminal_id' => $hasTerminalColumn ? (int) $u->default_terminal_id : null,
                'roles' => $u->getRoleNames()->values()->all(), 'can_counter_install' => (bool) $u->can(CP5_SS_PERMISSION)];
        }
        $profiles = app(DeviceProfileService::class);
        $out(['result' => 'ok', 'tenant_id' => (int) $tenant->id, 'tenant_code' => CP5_TENANT_CODE, 'tenant_host' => CP5_TENANT_CODE . '.cp5.test', 'branch_id' => $branchId,
            'branch2_id' => $branch2, 'owner_id' => (int) (($state['users'] ?? [])[0] ?? 0), 'cashier_role' => $cashierRole, 'users' => $users,
            'enrollment_key_id' => $profiles->enrollmentKeyId(),
            'device' => $device ? ['public_uuid' => (string) $device->public_uuid, 'lan_hostname' => (string) $device->lan_hostname, 'lan_ip' => (string) $device->lan_ip,
                'lan_https_port' => (int) $device->lan_https_port, 'lan_ca_sha256' => strtoupper((string) $device->lan_ca_sha256)] : null]);
        break;

    case 'verify-download':
        $v = $wizardVars((string) ($argv[2] ?? ''));
        $userId = (int) ($argv[3] ?? 0);
        $profiles = app(DeviceProfileService::class);
        $cloudKey = (string) $profiles->enrollmentPublicKey();
        $canonical = base64_decode((string) $v['SignedProfileB64'], true);
        $sig = base64_decode((string) $v['SignedProfileSig'], true);
        $key = base64_decode((string) $v['SignedProfileKey'], true);
        $profileSigOk = $canonical !== false && $sig !== false && $key !== false && strlen($sig) === 64 && strlen($key) === 32
            && sodium_crypto_sign_verify_detached($sig, $canonical, $key);
        $profile = $canonical !== false ? json_decode($canonical, true) : null;
        $pemDer = $v['pem'] ? base64_decode(preg_replace('/-----[A-Z ]+-----|\s+/', '', $v['pem'])) : '';
        $assertion = $v['EnrollAssertionB64'] ? json_decode((string) base64_decode($v['EnrollAssertionB64'], true), true) : null;
        $p = is_array($assertion) ? ($assertion['payload'] ?? []) : [];
        $deviceSha = $device ? strtoupper((string) $device->lan_ca_sha256) : null;
        $facts = [
            'file_sha256' => $v['sha256_of_file'],
            'setup_kind' => $v['SetupKind'],
            'signed_profile_present' => $v['SignedProfileB64'] !== null && $v['SignedProfileB64'] !== '',
            'profile_signature_valid_sodium' => $profileSigOk,
            'profile_key_is_the_cloud_enrollment_key' => $key !== false && base64_encode($key) === $cloudKey,
            'key_id_in_file' => $v['SignedProfileKeyId'],
            'key_id_cloud' => $profiles->enrollmentKeyId(),
            'key_id_recomputed' => $key !== false ? strtoupper(substr(hash('sha256', $key), 0, 16)) : null,
            'profile' => is_array($profile) ? array_intersect_key($profile, array_flip(['purpose', 'version', 'tenant_code', 'branch_id', 'branch_name', 'lan_hostname', 'lan_ip', 'https_port', 'ca_sha256', 'user_id', 'online_pos_url', 'issued_at', 'expires_at'])) : null,
            'profile_purpose_ok' => is_array($profile) && ($profile['purpose'] ?? null) === CounterBundleService::PROFILE_PURPOSE,
            'profile_for_this_user' => is_array($profile) && (int) ($profile['user_id'] ?? 0) === $userId,
            'profile_matches_device' => is_array($profile) && $device && (string) $profile['lan_ip'] === (string) $device->lan_ip
                && strtolower((string) $profile['lan_hostname']) === strtolower((string) $device->lan_hostname) && (int) $profile['https_port'] === (int) $device->lan_https_port
                && strtoupper((string) $profile['ca_sha256']) === $deviceSha,
            'profile_not_expired' => is_array($profile) && (int) ($profile['expires_at'] ?? 0) > time(),
            'online_url_in_profile_equals_shortcut' => is_array($profile) && (string) ($profile['online_pos_url'] ?? '') === (string) $v['UrlOnline'],
            'ca_sha256_in_file' => $v['CaSha256'],
            'ca_sha256_display_in_file' => $v['CaSha256Display'],
            'ca_pem_sha256' => $pemDer !== '' ? strtoupper(hash('sha256', $pemDer)) : null,
            'ca_sha256_device' => $deviceSha,
            'ca_consistent' => $pemDer !== '' && strtoupper(hash('sha256', $pemDer)) === strtoupper((string) $v['CaSha256']) && strtoupper((string) $v['CaSha256']) === $deviceSha,
            'edge' => ['host' => $v['EdgeHost'], 'ip' => $v['EdgeIp'], 'port' => $v['EdgePort'], 'by_name' => $v['EdgeBaseByName'], 'by_ip' => $v['EdgeBaseByIp']],
            'shortcuts' => ['online' => $v['UrlOnline'], 'offline' => $v['UrlOffline'], 'status' => $v['UrlStatus']],
            'terminal_hint' => ['id' => $v['TerminalId'], 'label' => $v['TerminalLabel']],
            'enrollment_present' => is_array($assertion),
            'enrollment_user_label' => $v['EnrollUserLabel'],
            'assertion_purpose' => $p['purpose'] ?? null,
            'assertion_user_id' => $p['user_id'] ?? null,
            'assertion_issuer_user_id' => $p['issuer_user_id'] ?? null,
            'assertion_self_only' => is_array($assertion) && (int) ($p['user_id'] ?? 0) === $userId && (int) ($p['issuer_user_id'] ?? 0) === $userId,
            'assertion_branch_ok' => (int) ($p['branch_id'] ?? 0) === (int) ($state['branch_id'] ?? -1),
            'assertion_tenant_ok' => (int) ($p['tenant_id'] ?? 0) === (int) $tenant->id,
            'assertion_device_ok' => $device && (string) ($p['device_public_uuid'] ?? '') === (string) $device->public_uuid,
            'assertion_ttl_s' => isset($p['expires_at'], $p['issued_at']) ? (int) $p['expires_at'] - (int) $p['issued_at'] : null,
            'assertion_signature_valid' => is_array($assertion) && EdgeEnrollmentCrypto::verifySignature($assertion, $cloudKey),
            'assertion_jti_sha256_8' => isset($p['jti']) ? substr(hash('sha256', (string) $p['jti']), 0, 8) : null,
        ];
        $facts['verdict'] = $facts['profile_signature_valid_sodium'] && $facts['profile_key_is_the_cloud_enrollment_key'] && $facts['key_id_in_file'] === $facts['key_id_cloud']
            && $facts['key_id_recomputed'] === $facts['key_id_cloud'] && $facts['profile_purpose_ok'] && $facts['profile_for_this_user'] && $facts['profile_matches_device']
            && $facts['ca_consistent'] && $facts['enrollment_present'] && $facts['assertion_self_only'] && $facts['assertion_branch_ok'] && $facts['assertion_tenant_ok']
            && $facts['assertion_device_ok'] && $facts['assertion_signature_valid'] && $facts['assertion_ttl_s'] !== null && $facts['assertion_ttl_s'] <= 900;
        $out($facts);
        break;

    case 'assertion-variant':
        // NEGATIVES for the Branch Server's /edge/local/enroll: the user's own assertion rebound to another tenant / branch and
        // re-signed with the Cloud key, or signed by a stranger's key. Fresh jti each, so only the binding / signature can refuse it.
        $v = $wizardVars((string) ($argv[2] ?? ''));
        $kind = (string) ($argv[3] ?? '');
        $assertion = json_decode((string) base64_decode((string) $v['EnrollAssertionB64'], true), true);
        if (! is_array($assertion) || ! is_array($assertion['payload'] ?? null)) {
            $fail('the download carries no assertion');
        }
        $payload = $assertion['payload'];
        $payload['jti'] = (string) Str::ulid();
        $secret = cp5_keys()['enrollment_secret'];
        if ($kind === 'tenant') {
            $payload['tenant_id'] = (int) $payload['tenant_id'] + 1000;
            $payload['tenant_code'] = (string) $payload['tenant_code'] . 'x';
        } elseif ($kind === 'branch') {
            $payload['branch_id'] = (int) $payload['branch_id'] + 1000;
        } elseif ($kind === 'badsig') {
            $secret = base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        } else {
            $fail("unknown variant {$kind}");
        }
        file_put_contents((string) $argv[4], json_encode(EdgeEnrollmentCrypto::sign($payload, $secret), JSON_UNESCAPED_SLASHES));
        $out(['result' => 'ok', 'kind' => $kind]);
        break;

    case 'audit':
        $userId = (int) ($argv[2] ?? 0);
        $log = (string) @file_get_contents(CP5_CLOUD_DIR . '/storage/logs/laravel.log');
        $issued = 0;
        $wizards = 0;
        foreach (preg_split('/\r?\n/', $log) as $line) {
            if (str_contains($line, '[edge-enrollment-issued]') && str_contains($line, 'self_service_counter') && preg_match('/"target_user_id":' . $userId . '\b/', $line)) {
                $issued++;
            }
            if (str_contains($line, 'edge.counter.wizard_issued') && preg_match('/"user_id":' . $userId . '\b/', $line)) {
                $wizards++;
            }
        }
        $out(['user_id' => $userId, 'self_enrollment_assertions_issued' => $issued, 'counter_wizards_issued' => $wizards]);
        break;

    default:
        $fail("unknown command [{$cmd}]");
}
