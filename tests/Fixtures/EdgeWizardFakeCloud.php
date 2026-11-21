<?php

/**
 * EDGE 0.9 (stream B) — a REHEARSAL Cloud for the headless setup wizard:
 *
 *   php -S 127.0.0.1:9821 -t public tests/Fixtures/EdgeWizardFakeCloud.php      (cwd = the repository root)
 *
 * Every existing Edge API path (pair, device/me, bootstrap, recovery key, heartbeat, ...) is served by the REAL Cloud app of
 * this tree (public/index.php) on the TEST databases given in the environment. The stream-C contracts that do not exist yet
 * are answered here, built on the REAL services (no fake crypto):
 *
 *   K2  POST /api/edge/setup/redeem                 one setup code (setup.json) -> setup token + a REAL pairing code
 *                                                    (EdgePairingService::generateCode) + the enrollment public key
 *   K3  POST /api/edge/setup/enrollment-assertions  device auth + X-Edge-Setup-Token -> REAL assertions (EdgeEnrollmentIssuer)
 *   K4  POST /api/edge/device/status                device auth -> appended to k4.jsonl
 *   K5  POST /api/edge/device/lan-profile           device auth -> appended to k5.jsonl
 *   K6  GET  /api/edge/updates/latest               device auth -> setup.json "release" or {"available": false}
 *
 * State lives in EDGE_WIZARD_FAKE_CLOUD_DIR (setup.json written by the rehearsal). TEST ONLY — never shipped (tests/ is
 * excluded from every artifact and package).
 */

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$root = dirname(__DIR__, 2);
$fake = ['/api/edge/setup/redeem', '/api/edge/setup/enrollment-assertions', '/api/edge/device/status', '/api/edge/device/lan-profile', '/api/edge/updates/latest'];

if (! in_array($path, $fake, true)) {
    $static = $root . '/public' . $path;
    if ($path !== '/' && is_file($static) && ! str_ends_with($static, '.php')) {
        return false; // let php -S serve the asset
    }
    $_SERVER['SCRIPT_FILENAME'] = $root . '/public/index.php';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    chdir($root . '/public');
    require $root . '/public/index.php';

    return true;
}

require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$dir = (string) getenv('EDGE_WIZARD_FAKE_CLOUD_DIR');
$stateFile = $dir . DIRECTORY_SEPARATOR . 'setup.json';
$state = json_decode((string) @file_get_contents($stateFile), true) ?: [];
$body = json_decode((string) file_get_contents('php://input'), true) ?: [];
$header = fn (string $name) => (string) ($_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] ?? '');

$respond = function (int $status, array $doc): bool {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($doc, JSON_UNESCAPED_SLASHES);

    return true;
};
$log = function (string $file, array $row) use ($dir): void {
    file_put_contents($dir . DIRECTORY_SEPARATOR . $file, json_encode($row + ['received_at' => date('c')], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
};
$device = function () use ($header): ?App\Models\Master\EdgeDevice {
    $uuid = $header('X-Edge-Device-ID');
    $auth = $header('Authorization');
    $secret = str_starts_with($auth, 'Bearer ') ? substr($auth, 7) : '';
    if ($uuid === '' || $secret === '') {
        return null;
    }
    $d = App\Models\Master\EdgeDevice::query()->where('public_uuid', $uuid)->active()->first();

    return $d && hash_equals((string) $d->device_secret_hash, hash('sha256', $secret)) ? $d : null;
};
$tenant = function () use ($state): App\Models\Master\Tenant {
    $t = App\Models\Master\Tenant::findOrFail((int) $state['tenant_id']);
    app(App\Services\Tenancy\TenancyManager::class)->activate($t);

    return $t;
};

try {
    switch ($path) {
        case '/api/edge/setup/redeem':
            if (($body['setup_code'] ?? '') === '' || ! hash_equals((string) ($state['setup_code'] ?? ''), (string) $body['setup_code']) || ! empty($state['redeemed'])) {
                return $respond(422, ['code' => 'SETUP_CODE_INVALID', 'message' => 'The setup code is invalid, expired or already used.']);
            }
            $t = $tenant();
            $branch = App\Models\Tenant\Branch::findOrFail((int) $state['branch_id']);
            $issued = app(App\Services\Edge\EdgePairingService::class)->generateCode($t, $branch, (int) $state['issuer_user_id']);
            $token = bin2hex(random_bytes(24));
            $state['redeemed'] = true;
            $state['setup_token_hash'] = hash('sha256', $token);
            $state['installation_uuid'] = (string) ($body['installation_uuid'] ?? '');
            $state['device_name'] = (string) ($body['device_name'] ?? '');
            file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $log('k2.jsonl', ['installation_uuid' => $state['installation_uuid'], 'device_name' => $state['device_name']]);

            return $respond(200, [
                // stream C K2 shape (edge09/c-cloud c305c5a7)
                'mode' => 'install',
                'setup_session_id' => 'fake-session-' . substr(hash('sha256', $token), 0, 8),
                'setup_token_expires_at' => date('c', time() + 3600),
                'pairing_code_expires_at' => date('c', time() + 900),
                'device_id' => null,
                'setup_token' => $token,
                'cloud_url' => 'http://127.0.0.1:' . $_SERVER['SERVER_PORT'],
                'tenant_code' => (string) $t->tenant_code,
                'tenant_name' => (string) $t->business_name,
                'branch_id' => (int) $branch->id,
                'branch_name' => (string) $branch->name,
                'pairing_code' => (string) $issued['code'],
                'enrollment_public_key' => (string) $state['enrollment_public_key'],
                'update_public_key_fingerprint' => (string) ($state['update_public_key_fingerprint'] ?? ''),
                'maintenance_window' => $state['maintenance_window'] ?? ['start' => '03:00', 'end' => '05:00', 'tz' => 'Asia/Karachi'],
            ]);

        case '/api/edge/setup/enrollment-assertions':
            $d = $device();
            if (! $d) {
                return $respond(401, ['code' => 'EDGE_DEVICE_UNAUTHENTICATED']);
            }
            if (empty($state['setup_token_hash']) || ! hash_equals((string) $state['setup_token_hash'], hash('sha256', $header('X-Edge-Setup-Token')))) {
                return $respond(403, ['code' => 'SETUP_TOKEN_INVALID']);
            }
            $t = $tenant();
            $ids = ! empty($body['all_active'])
                ? App\Models\Tenant\User::query()->where('status', 'active')->pluck('id')->map(fn ($v) => (int) $v)->all()
                : array_map('intval', (array) ($body['user_ids'] ?? []));
            $assertions = [];
            $skipped = [];
            foreach ($ids as $id) {
                $user = App\Models\Tenant\User::find($id);
                if (! $user) {
                    $skipped[] = ['user_id' => $id, 'reason' => 'not_found'];
                    continue;
                }
                if ((string) $user->employee_code === '') {
                    $skipped[] = ['user_id' => $id, 'reason' => 'no_employee_code'];
                    continue;
                }
                try {
                    $a = app(App\Services\Edge\EdgeEnrollmentIssuer::class)->issue($t, (int) $d->branch_id, $d, $user, (int) $state['issuer_user_id']);
                    $assertions[] = ['user_id' => $id, 'employee_code' => (string) $user->employee_code, 'name' => (string) $user->name, 'assertion' => $a, 'expires_at' => date('c', (int) $a['payload']['expires_at'])];
                } catch (Throwable $e) {
                    $skipped[] = ['user_id' => $id, 'reason' => mb_substr($e->getMessage(), 0, 120)];
                }
            }
            $log('k3.jsonl', ['issued' => array_column($assertions, 'user_id'), 'skipped' => $skipped]);

            return $respond(200, ['assertions' => $assertions, 'skipped' => $skipped]);

        case '/api/edge/device/status':
        case '/api/edge/device/lan-profile':
            $d = $device();
            if (! $d) {
                return $respond(401, ['code' => 'EDGE_DEVICE_UNAUTHENTICATED']);
            }
            $log($path === '/api/edge/device/status' ? 'k4.jsonl' : 'k5.jsonl', ['device' => (string) $d->public_uuid] + $body);

            return $respond(200, $path === '/api/edge/device/status'
                ? ['status' => 'recorded', 'phase' => (string) ($body['phase'] ?? ''), 'reported_version' => (string) ($body['version'] ?? ''), 'recorded_at' => date('c'), 'update_now' => false]
                : ['status' => 'recorded']);

        case '/api/edge/updates/latest':
            if (! $device()) {
                return $respond(401, ['code' => 'EDGE_DEVICE_UNAUTHENTICATED']);
            }

            return $respond(200, $state['release'] ?? ['available' => false, 'reason' => 'no_release', 'channel' => 'stable', 'update_now' => false]);
    }
} catch (Throwable $e) {
    return $respond(500, ['code' => 'FAKE_CLOUD_ERROR', 'message' => mb_substr(get_class($e) . ': ' . $e->getMessage(), 0, 400)]);
}

return $respond(404, ['code' => 'NOT_FOUND']);
