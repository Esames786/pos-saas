<?php

/**
 * CP4 (stream B) — the appliance side of the 0.8.5 -> 0.9 upgrade + auto-update rehearsal, step by step, on DISPOSABLE resources:
 *   appliance  C:\Users\Dell\BingooEdgeLab\e09\b\cp4\appliance-085\  (install + data root), DB edge_local_cp4_e09b (shared 127.0.0.1:3306)
 *   Cloud      the integrated Cloud on http://127.0.0.1:9821 (cp4-router.php) driven by cp4-cloud.php
 *   packages   the REAL signed 0.8.5 package (read-only) and the DEV-signed 0.9.x rehearsal packages under cp4\pkgs
 * No elevation, no task/service/firewall/cert-store/hosts change: the appliance runs -NoServices -NoGateway; processes it needs
 * (a loopback web backend for the PIN login proof) are started and stopped by this script.
 *
 *   php cp4-steps.php install-085 | wizard-update | auto-update <phase> | report
 */

const CP4 = 'C:\\Users\\Dell\\BingooEdgeLab\\e09\\b\\cp4';
const APPL = CP4 . '\\appliance-085';
const INSTALL = APPL . '\\Bingoo Edge';
const DATA = APPL . '\\ProgramData\\BingooEdge';
const SECRETS = CP4 . '\\secrets';
const EVID = CP4 . '\\evidence';
const EDGE_DB = 'edge_local_cp4_e09b';
const REL085 = 'C:\\Users\\Dell\\.bingoo-edge-release\\releases\\BingooEdge-0.8.5-edge';
const PKGS = CP4 . '\\pkgs';
const CLOUD_URL = 'http://127.0.0.1:9821';
const LOGIN_PORT = 9825;

$WT = dirname(__DIR__, 3);
$step = $argv[1] ?? '';
@mkdir(EVID, 0775, true);
secureDir(SECRETS);

function say(string $m): void { echo '[' . date('H:i:s') . "] {$m}\n"; flush(); }
function evid(string $name, $doc): void { file_put_contents(EVID . '\\' . $name, is_string($doc) ? $doc : json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); }
function fail(string $m): never { say('FAILED: ' . $m); exit(1); }
function secureDir(string $d): void
{
    if (! is_dir($d)) {
        mkdir($d, 0700, true);
        exec('icacls "' . $d . '" /inheritance:r /grant:r "' . getenv('USERDOMAIN') . '\\' . getenv('USERNAME') . ':(OI)(CI)F" 2>&1');
    }
}
function devPublic(): string { return json_decode(file_get_contents('C:\\Users\\Dell\\BingooEdgeLab\\e09\\dev-signing\\dev-public-facts.json'), true)['public_key']; }
function cloudKeys(): array { return json_decode(file_get_contents(CP4 . '\\cloud\\keys.json'), true); }
function cloudState(): array { return json_decode(file_get_contents(CP4 . '\\cloud\\state.json'), true); }

/** The appliance's process environment: nothing from a dev tree (the launcher supplies appliance.env). */
function applianceEnv(array $extra = []): array
{
    $env = getenv() ?: [];
    foreach (array_keys($env) as $k) {
        if (preg_match('/^(APP_|DB_|EDGE_|TENANT_|BINGOO_)/', $k) || in_array($k, ['SESSION_DRIVER', 'CACHE_STORE', 'QUEUE_CONNECTION', 'LOG_CHANNEL', 'MAIL_MAILER', 'CENTRAL_DOMAIN'], true)) {
            unset($env[$k]);
        }
    }

    return array_merge($env, $extra);
}

/** @return array{0:int,1:string} */
function run(array $cmd, ?string $cwd = null, ?array $env = null, int $timeout = 3600): array
{
    $log = tempnam(sys_get_temp_dir(), 'cp4');
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes, $cwd, $env);
    fclose($pipes[0]);
    $deadline = time() + $timeout;
    while (($st = proc_get_status($p)) && $st['running'] && time() < $deadline) {
        usleep(200000);
    }
    if ($st['running']) {
        exec('taskkill /PID ' . $st['pid'] . ' /T /F >nul 2>&1');
    }
    $code = proc_close($p);
    $code = $st['running'] ? 124 : (int) ($st['exitcode'] ?? $code);
    $out = (string) file_get_contents($log);
    @unlink($log);

    return [$code, $out];
}

function cloud(array $args): array
{
    global $WT;

    return run(array_merge([PHP_BINARY, $WT . '\\tests\\Fixtures\\EdgeWizardCp4\\cp4-cloud.php'], $args), $WT, null, 1800);
}

function phpOf(): string { return INSTALL . '\\php\\php.exe'; }

/** An appliance command through the INSTALLED launcher (resolves the current runtime pointer). */
function edge(array $args, int $timeout = 900, array $extraEnv = []): array
{
    return run(array_merge([phpOf(), INSTALL . '\\artisan'], $args, ['--no-interaction']), INSTALL, applianceEnv($extraEnv), $timeout);
}

function lastJson(string $out): ?array
{
    $d = json_decode(trim($out), true);
    if (is_array($d)) {
        return $d;
    }
    $start = strpos($out, '{');
    while ($start !== false) {
        $d = json_decode(substr($out, $start), true);
        if (is_array($d)) {
            return $d;
        }
        $start = strpos($out, '{', $start + 1);
    }

    return null;
}

function pdo(?string $db = EDGE_DB): PDO
{
    return new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4' . ($db ? ';dbname=' . $db : ''), 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}

function envValue(string $key): ?string
{
    $s = str_replace("\r\n", "\n", (string) @file_get_contents(DATA . '\\config\\appliance.env'));

    return preg_match('/^' . preg_quote($key, '/') . '=(.*)$/m', $s, $m) ? trim($m[1], "'\"") : null;
}

/** Rehearsal-only appliance.env adjustments on the DISPOSABLE copy (reported). */
function setEnvValues(array $kv): void
{
    $f = DATA . '\\config\\appliance.env';
    $s = str_replace("\r\n", "\n", (string) file_get_contents($f));
    foreach ($kv as $k => $v) {
        $line = $k . '=' . $v;
        $s = preg_match('/^' . preg_quote($k, '/') . '=.*$/m', $s) ? preg_replace('/^' . preg_quote($k, '/') . '=.*$/m', str_replace('$', '\\$', $line), $s) : rtrim($s, "\n") . "\n" . $line . "\n";
    }
    file_put_contents($f, str_replace("\n", "\r\n", $s));
}

function pointer(): string { return trim((string) @file_get_contents(INSTALL . '\\runtime\\current')); }

/** Business rows that must survive every update / rollback: per table count + content checksum. */
function survivalFingerprint(): array
{
    $p = pdo();
    $out = [];
    foreach (['users', 'edge_local_user_credentials', 'shifts', 'sales_orders', 'sales_order_lines', 'sale_payments', 'edge_sync_outbox', 'restaurant_table_sessions', 'edge_local_meta'] as $t) {
        try {
            $cnt = (int) $p->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
            $ids = $p->query("SELECT id FROM `{$t}` ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
            $out[$t] = ['rows' => $cnt, 'ids' => implode(',', $ids)];
        } catch (Throwable $e) {
            $out[$t] = ['error' => $e->getMessage()];
        }
    }
    // Content hash of the seeded local business rows (immune to columns a migration ADDS): the seeded columns only.
    $rows = $p->query("SELECT id, sale_uuid, sale_no, status, grand_total FROM sales_orders ORDER BY id")->fetchAll();
    $out['sales_orders_hash'] = hash('sha256', json_encode($rows));
    $rows = $p->query("SELECT id, sale_uuid, state, content_hash FROM edge_sync_outbox ORDER BY id")->fetchAll();
    $out['outbox_hash'] = hash('sha256', json_encode($rows));
    $rows = $p->query("SELECT user_id, credential_type, credential_hash, status FROM edge_local_user_credentials ORDER BY user_id")->fetchAll();
    $out['credentials_hash'] = hash('sha256', json_encode($rows));

    return $out;
}

function insertFilled(PDO $p, string $table, array $values): int
{
    $cols = $p->prepare('SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $cols->execute([$table]);
    foreach ($cols->fetchAll() as $c) {
        $n = $c['COLUMN_NAME'];
        if (array_key_exists($n, $values) || $c['IS_NULLABLE'] === 'YES' || $c['COLUMN_DEFAULT'] !== null || str_contains((string) $c['EXTRA'], 'auto_increment')) {
            continue;
        }
        $t = strtolower($c['DATA_TYPE']);
        $values[$n] = match (true) {
            in_array($t, ['int', 'bigint', 'smallint', 'tinyint', 'mediumint', 'decimal', 'double', 'float'], true) => 0,
            in_array($t, ['datetime', 'timestamp'], true) => date('Y-m-d H:i:s'),
            $t === 'date' => date('Y-m-d'),
            $t === 'enum' => trim(explode(',', substr($c['COLUMN_TYPE'], 5, -1))[0], "'"),
            $t === 'json' => '[]',
            default => 'cp4',
        };
    }
    $keys = array_keys($values);
    $st = $p->prepare('INSERT INTO `' . $table . '` (`' . implode('`,`', $keys) . '`) VALUES (' . implode(',', array_fill(0, count($keys), '?')) . ')');
    $st->execute(array_values($values));

    return (int) $p->lastInsertId();
}

function ulid(): string { return strtoupper(bin2hex(random_bytes(13))); }

/** PIN login proof: a loopback web backend of the CURRENT runtime, the real login form (CSRF), cookies handled by hand. */
function pinLogin(string $employeeCode, string $pin): array
{
    [$proc, $pid] = startServe();
    try {
        $jar = [];
        $r = http('GET', '/edge/local/login', null, $jar);
        if (! preg_match('/name="_token"\s+value="([^"]+)"/', $r['body'], $m)) {
            return ['ok' => false, 'why' => 'no CSRF token on the login page (HTTP ' . $r['status'] . ')'];
        }
        $r = http('POST', '/edge/local/login', ['_token' => $m[1], 'employee_code' => $employeeCode, 'credential' => $pin], $jar);
        $location = $r['headers']['location'] ?? '';
        $status = http('GET', '/edge/local/status', null, $jar);

        return ['ok' => $r['status'] === 302 && ! str_contains($location, '/login') && $status['status'] === 200, 'login_status' => $r['status'], 'location' => $location, 'status_after' => $status['status']];
    } finally {
        exec('taskkill /PID ' . $pid . ' /T /F >nul 2>&1');
        @proc_close($proc);
    }
}

function startServe(): array
{
    $log = CP4 . '\\serve-' . date('His') . '.log';
    $p = proc_open([phpOf(), INSTALL . '\\artisan', 'edge:local:serve', '--worker=1', '--port=' . LOGIN_PORT], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, INSTALL, applianceEnv());
    fclose($pipes[0]);
    $pid = proc_get_status($p)['pid'];
    $deadline = microtime(true) + 40;
    while (microtime(true) < $deadline) {
        $fp = @fsockopen('127.0.0.1', LOGIN_PORT, $e, $s, 0.5);
        if (is_resource($fp)) {
            fclose($fp);
            usleep(500000);

            return [$p, $pid];
        }
        usleep(300000);
    }
    exec('taskkill /PID ' . $pid . ' /T /F >nul 2>&1');
    fail('the loopback web backend did not listen: ' . @file_get_contents($log));
}

function http(string $method, string $path, ?array $form, array &$jar): array
{
    $ch = curl_init('http://127.0.0.1:' . LOGIN_PORT . $path);
    $headers = ['Accept: text/html,application/json', 'X-Forwarded-Proto: https', 'X-Forwarded-Port: 443'];
    if ($jar !== []) {
        $headers[] = 'Cookie: ' . implode('; ', array_map(fn ($k, $v) => $k . '=' . $v, array_keys($jar), $jar));
    }
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $headers]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form ?? []));
    }
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $head = substr($raw, 0, $hsize);
    $headers = [];
    foreach (preg_split('/\r?\n/', $head) as $line) {
        if (preg_match('/^set-cookie:\s*([^=]+)=([^;]*)/i', $line, $m)) {
            $jar[trim($m[1])] = $m[2];
        } elseif (preg_match('/^([A-Za-z-]+):\s*(.*)$/', $line, $m)) {
            $headers[strtolower($m[1])] = trim($m[2]);
        }
    }

    return ['status' => $status, 'headers' => $headers, 'body' => substr($raw, $hsize)];
}

function pins(): array
{
    $f = SECRETS . '\\pins.json';
    if (! is_file($f)) {
        $s = cloudState();
        $pins = [];
        foreach ($s['users'] as $i => $uid) {
            $pins[$uid] = (string) (482913 + 111111 * $i + random_int(0, 9));
        }
        file_put_contents($f, json_encode($pins));
    }

    return json_decode(file_get_contents($f), true);
}

function health(): array
{
    [$code, $out] = edge(['edge:local:health', '--json'], 300);

    return lastJson($out) ?? ['error' => 'exit ' . $code . ': ' . substr($out, -800)];
}

switch ($step) {
    // ───────────────────────────────────────────────────────────────────────────────────────────────────────────────
    case 'install-085':
        $t0 = time();
        $s = cloudState();
        if (is_dir(APPL)) {
            exec('rmdir /s /q "' . APPL . '" 2>&1');
            if (is_dir(APPL)) {
                fail('could not remove the previous disposable appliance ' . APPL);
            }
        }
        pdo(null)->exec('DROP DATABASE IF EXISTS `' . EDGE_DB . '`');
        $dbpw = SECRETS . '\\db.pass';
        file_put_contents($dbpw, '');
        $codeFile = SECRETS . '\\pairing.code';
        @unlink($codeFile);
        $args = ['powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', REL085 . '\\scripts\\Install-EdgeAppliance.ps1',
            '-PackageRoot', REL085, '-InstallRoot', INSTALL, '-DataRoot', DATA, '-DbHost', '127.0.0.1', '-DbPort', '3306', '-DbName', EDGE_DB, '-DbUser', 'root', '-DbPasswordFile', $dbpw,
            '-CloudUrl', CLOUD_URL, '-AllowHttpCloud', '-PairingCodeFile', $codeFile, '-DeviceName', 'CP4-KHATRI-COPY',
            '-EnrollmentPublicKey', cloudKeys()['enrollment_public'], '-UpdatePublicKey', devPublic(),
            '-LanHostname', 'bingoo-edge.test', '-LanIp', '127.0.0.1', '-HttpsPort', '9822', '-HttpPort', '9824', '-WebWorkers', '1', '-WebPortBase', '9823', '-NoServices', '-NoGateway'];
        $log = EVID . '\\install-085.log';
        say('0.8.5 installer (real signed package, read-only) -> ' . INSTALL);
        $p = proc_open($args, [0 => ['pipe', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes, CP4, applianceEnv());
        fclose($pipes[0]);
        $handed = false;
        while (($st = proc_get_status($p)) && $st['running']) {
            if (! $handed && str_contains((string) @file_get_contents($log), 'waiting up to')) {
                [$c, $o] = cloud(['pairing-code', $codeFile]);
                say('pairing code issued by the Cloud owner path: ' . trim($o));
                $handed = true;
            }
            usleep(500000);
        }
        $code = proc_close($p);
        $out = (string) file_get_contents($log);
        if (! str_contains($out, 'INSTALL COMPLETE')) {
            fail("0.8.5 install did not complete:\n" . substr($out, -3000));
        }
        say('0.8.5 installed in ' . (time() - $t0) . ' s; device ' . envValue('EDGE_SYNC_DEVICE_ID') . ', installation ' . envValue('EDGE_INSTALLATION_UUID') . ', pointer ' . pointer());
        // Users + PINs on 0.8.5 (the documented operator path: Cloud assertion + edge:local:enroll --credential-file).
        $pins = pins();
        foreach ($s['users'] as $uid) {
            $af = SECRETS . "\\assert-{$uid}.json";
            [$c, $o] = cloud(['assertion', (string) $uid, $af]);
            $pf = SECRETS . "\\pin-{$uid}";
            file_put_contents($pf, $pins[$uid]);
            [$c, $o] = edge(['edge:local:enroll', $af, '--type=pin', '--credential-file=' . $pf]);
            @unlink($af);
            @unlink($pf);
            say("enroll user #{$uid} on 0.8.5: exit {$c} " . trim(preg_replace('/\e\[[0-9;]*m/', '', $o)));
        }
        // no break: continue with the local history seed

    case 'seed-085':
        $t0 = $t0 ?? time();
        $s = cloudState();
        $pins = pins();
        // Local business history (as left by an earlier supervised Local Mode period, all synced): a closed shift, two paid
        // local sales, one held bill, two ACKNOWLEDGED outbox events. Inserted directly (the survival proof needs known rows).
        $p = pdo();
        $branch = (int) $p->query('SELECT branch_id FROM edge_local_meta LIMIT 1')->fetchColumn();
        $term = (int) $p->query('SELECT id FROM terminals ORDER BY id LIMIT 1')->fetchColumn();
        $shift = insertFilled($p, 'shifts', ['shift_uuid' => ulid(), 'branch_id' => $branch, 'terminal_id' => $term, 'opened_by_user_id' => (int) $s['users'][1], 'status' => 'closed',
            'opened_at' => date('Y-m-d H:i:s', time() - 7200), 'closed_at' => date('Y-m-d H:i:s', time() - 3600), 'opening_cash' => 1000, 'business_date' => date('Y-m-d'),
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        $local = [];
        foreach ([['paid', 450], ['paid', 900], ['held', 1350]] as [$status, $total]) {
            $local[] = insertFilled($p, 'sales_orders', ['sale_uuid' => ulid(), 'sale_no' => 'EDGE-CP4-' . random_int(1000, 9999), 'branch_id' => $branch, 'terminal_id' => $term,
                'shift_id' => $shift, 'order_source' => 'pos', 'order_type' => 'quick_sale', 'status' => $status, 'sale_date' => date('Y-m-d H:i:s'), 'business_date' => date('Y-m-d'),
                'subtotal' => $total, 'grand_total' => $total, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        }
        foreach (array_slice($local, 0, 2) as $saleId) {
            $uuid = $p->query("SELECT sale_uuid FROM sales_orders WHERE id = {$saleId}")->fetchColumn();
            insertFilled($p, 'edge_sync_outbox', ['sale_uuid' => $uuid, 'envelope_schema_version' => 'edge-sale-v1', 'config_revision' => 1, 'activation_epoch' => 1,
                'envelope' => '{"cp4":"history"}', 'content_hash' => hash('sha256', $uuid), 'state' => 'acknowledged', 'acknowledged_at' => date('Y-m-d H:i:s'),
                'ack_ingestion_uuid' => 'cp4-' . substr($uuid, 0, 20), 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        }
        [$c, $o] = edge(['edge:local:authority-worker', '--max-ticks=2', '--interval=3'], 300);
        $h = health();
        $fp = survivalFingerprint();
        $codes = $s['employee_codes'];
        $logins = [];
        foreach (array_values($s['users']) as $i => $uid) {
            $logins[$codes[$i]] = pinLogin($codes[$i], $pins[$uid]);
        }
        $doc = ['seconds' => time() - $t0, 'pointer' => pointer(), 'device_id' => envValue('EDGE_SYNC_DEVICE_ID'), 'installation_uuid' => envValue('EDGE_INSTALLATION_UUID'),
            'status' => $h['status'] ?? null, 'problems' => $h['problems'] ?? null, 'authority' => $h['authority']['state'] ?? null, 'binding' => $h['binding'] ?? null,
            'local_rows' => ['shift' => $shift, 'sales' => $local], 'survival' => $fp, 'pin_logins' => $logins];
        evid('state-085.json', $doc);
        say('0.8.5 baseline: ' . json_encode(['status' => $doc['status'], 'authority' => $doc['authority'], 'logins' => array_map(fn ($l) => $l['ok'], $logins)]));
        break;

    // ───────────────────────────────────────────────────────────────────────────────────────────────────────────────
    case 'wizard-update':
        $t0 = time();
        $approve = $argv[2] ?? '';
        $pkg = PKGS . '\\BingooEdge-0.9.0-dev';
        $codeFile = SECRETS . '\\setup.code';
        [$c, $o] = cloud(['setup-code', $codeFile]);
        say('setup code (Cloud, update mode expected): ' . trim(preg_replace('/\s+/', ' ', $o)));
        $answers = [
            'cloud_url' => CLOUD_URL, 'allow_http_cloud' => true, 'install_root' => INSTALL, 'data_root' => DATA, 'log_dir' => EVID . '\\wizard-logs',
            'update_public_key' => devPublic(), 'services' => ['register' => false, 'gateway' => false],
            'update' => array_filter(['use_setup_code' => true, 'approve_plan_hash' => $approve !== '' ? $approve : null, 'health_wait_seconds' => 60]),
            'result_file' => EVID . '\\wizard-update-result' . ($approve !== '' ? '-approved' : '-unapproved') . '.json',
        ];
        $af = CP4 . '\\answers-update.json';
        file_put_contents($af, json_encode($answers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $env = applianceEnv(['BINGOO_WIZARD_TEST_SECRETS' => '1', 'BINGOO_WIZARD_SECRET_SETUP_CODE' => trim((string) file_get_contents($codeFile))]);
        @unlink($codeFile);
        $before = ['pointer' => pointer(), 'device_id' => envValue('EDGE_SYNC_DEVICE_ID'), 'installation_uuid' => envValue('EDGE_INSTALLATION_UUID'), 'survival' => survivalFingerprint()];
        say('wizard UPDATE mode from ' . $pkg . ($approve !== '' ? " (approved plan {$approve})" : ' (no plan approval)'));
        [$code, $out] = run(['powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', $pkg . '\\app\\scripts\\edge\\wizard\\Start-BingooEdgeWizard.ps1',
            '-Headless', '-AnswersFile', $af], CP4, $env, 3600);
        evid('wizard-update' . ($approve !== '' ? '-approved' : '-unapproved') . '.out.txt', $out);
        say("wizard exit {$code} in " . (time() - $t0) . ' s');
        if ($approve === '') {
            preg_match('/approve plan (sha256:[0-9a-f]{64})/', $out, $m);
            say('plan approval gate: ' . (str_contains($out, 'WIZARD_UPDATE_PLAN_NOT_APPROVED') ? 'REFUSED as expected; actual plan ' . ($m[1] ?? '?') : 'NOT REFUSED'));
            evid('wizard-plan.txt', $m[1] ?? '');
            break;
        }
        $h = health();
        $after = ['pointer' => pointer(), 'device_id' => envValue('EDGE_SYNC_DEVICE_ID'), 'installation_uuid' => envValue('EDGE_INSTALLATION_UUID'), 'survival' => survivalFingerprint()];
        $migs = pdo()->query("SELECT migration FROM migrations WHERE migration LIKE '2026_11_2%' ORDER BY migration")->fetchAll(PDO::FETCH_COLUMN);
        [$c, $o] = edge(['edge:local:authority-worker', '--max-ticks=3', '--interval=3'], 300);
        $mirror = [];
        foreach (['edge_mirror_shifts', 'edge_mirror_open_orders', 'edge_mirror_held_sales', 'edge_mirror_orders', 'edge_mirror_table_sessions'] as $t) {
            try {
                $mirror[$t] = (int) pdo()->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
            } catch (Throwable) {
            }
        }
        $mirrorTables = pdo()->query("SHOW TABLES LIKE 'edge_mirror%'")->fetchAll(PDO::FETCH_COLUMN);
        $s = cloudState();
        $pins = pins();
        $logins = [];
        foreach (array_values($s['users']) as $i => $uid) {
            $logins[$s['employee_codes'][$i]] = pinLogin($s['employee_codes'][$i], $pins[$uid]);
        }
        [$c, $dev] = cloud(['device']);
        $doc = ['seconds' => time() - $t0, 'wizard_exit' => $code, 'before' => $before, 'after' => $after, 'same_device' => $before['device_id'] === $after['device_id'],
            'same_installation' => $before['installation_uuid'] === $after['installation_uuid'], 'survival_equal' => array_diff_key($before['survival'], ['edge_local_meta' => 1]) == array_diff_key($after['survival'], ['edge_local_meta' => 1]),
            'edge_migrations_0_9' => $migs, 'mirror_tables' => $mirrorTables, 'mirror_counts' => $mirror, 'status' => $h['status'] ?? null, 'problems' => $h['problems'] ?? null,
            'version' => $h['runtime']['edge_app_version'] ?? null, 'pin_logins' => $logins, 'cloud_device' => lastJson($dev)];
        evid('state-090.json', $doc);
        say('0.9.0 after wizard: ' . json_encode(['pointer' => $after['pointer'], 'same_device' => $doc['same_device'], 'same_installation' => $doc['same_installation'], 'survival_equal' => $doc['survival_equal'],
            'logins' => array_map(fn ($l) => $l['ok'], $logins), 'mirror' => $mirror]));
        break;

    // ───────────────────────────────────────────────────────────────────────────────────────────────────────────────
    case 'tune':
        // Rehearsal-only adjustments of the DISPOSABLE appliance.env: no Windows tasks exist here (service control none), a
        // short health wait for the deliberately broken packages, and a short lease skew margin so a real takeover is reachable.
        setEnvValues(['EDGE_AUTO_UPDATE_SERVICE_CONTROL' => 'none', 'EDGE_AUTO_UPDATE_HEALTH_WAIT' => '20', 'EDGE_AUTHORITY_SKEW_MARGIN' => '8']);
        say('appliance.env tuned (service_control none, health wait 20 s, skew margin 8 s)');
        break;

    case 'tick':
        $t0 = time();
        edge(['edge:local:authority-worker', '--max-ticks=1', '--interval=1'], 120);
        [$code, $out] = edge(array_merge(['edge:local:auto-update', '--json'], array_slice($argv, 2)), 1800);
        $doc = lastJson($out) ?? ['raw' => substr($out, -2000)];
        $tag = $argv[2] ?? 'tick';
        $name = 'tick-' . date('His') . '.json';
        evid($name, ['exit' => $code, 'seconds' => time() - $t0, 'pointer' => pointer(), 'result' => $doc]);
        say("auto-update exit {$code} in " . (time() - $t0) . ' s, pointer ' . pointer() . ': ' . json_encode(array_intersect_key($doc, array_flip(['result', 'version', 'code', 'message', 'rollback', 'reasons']))) . " -> evidence {$name}");
        break;

    case 'edge':
        [$code, $out] = edge(array_slice($argv, 2), 1800);
        echo $out;
        exit($code);

    case 'fingerprint':
        echo json_encode(['pointer' => pointer(), 'survival' => survivalFingerprint()], JSON_PRETTY_PRINT) . "\n";
        break;

    case 'pin-logins':
        $s = cloudState();
        $pins = pins();
        $r = [];
        foreach (array_values($s['users']) as $i => $uid) {
            $r[$s['employee_codes'][$i]] = pinLogin($s['employee_codes'][$i], $pins[$uid]);
        }
        echo json_encode($r, JSON_PRETTY_PRINT) . "\n";
        break;

    default:
        fwrite(STDERR, "unknown step [{$step}]\n");
        exit(2);
}
