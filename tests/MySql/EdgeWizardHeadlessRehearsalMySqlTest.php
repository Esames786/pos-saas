<?php

namespace Tests\MySql;

use App\Services\Edge\EdgeEnrollmentCrypto;
use App\Services\Edge\EdgeSigningKeyStore;
use App\Services\Edge\EdgeTenantMigrationManifest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * EDGE 0.9 (stream B) — HEADLESS SETUP-WIZARD REHEARSAL, end to end, real processes, no command typed by an operator:
 *
 *   Cloud    = this tree's REAL Cloud app on `php -S 127.0.0.1:9821` (test DBs) + the not-yet-built stream-C contracts K2–K6
 *              answered by tests/Fixtures/EdgeWizardFakeCloud.php on top of the REAL pairing / enrollment services;
 *   Package  = a DEV package of THIS tree built by `edge:build-package` (signed with a rehearsal keystore), wizard inside;
 *   Wizard   = <package>\scripts\wizard\Start-BingooEdgeWizard.ps1 -Headless -AnswersFile (no secret in the answers file:
 *              setup code, DB password and PINs come from the TEST-only env injection), non-elevated, NO services / gateway /
 *              firewall / hosts / clock / power change, a disposable `edge_local_e09b_rehearsal` database on the shared MySQL;
 *   Proven   = prerequisites → K2 redeem (tenant/branch, central Cloud URL, pairing code) → Install-EdgeAppliance (verify,
 *              db-init, pair with the code handed over when the installer asks, bootstrap, recovery key) → users list →
 *              K3 assertions → each user enrolled with HIS PIN (Argon2 hash verifies the exact PIN) → K4 bootstrapped /
 *              enrolled / ready → K5 LAN profile → 3 shortcuts with the Bingoo icon → no secret in any log;
 *              then UPDATE MODE with a newer signed package: detected from appliance.env, gate (standby, outbox 0), full DB
 *              snapshot by the NEW code, Update-EdgeAppliance (signed, plan check), pointer switch, health → updated, K4.
 *
 * Opt-in (fixed ports 9821-9824, ~10-30 min): EDGE_WIZARD_REHEARSAL=1. Windows only. Never a production system.
 */
class EdgeWizardHeadlessRehearsalMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private const CLOUD_PORT = 9821;
    private const HTTPS_PORT = 9822;
    private const WEB_PORT = 9823;
    private const HTTP_PORT = 9824;
    private const DB = 'edge_local_e09b_rehearsal';

    private string $scratch;
    private string $tenantCode;
    private int $cloudTenantId;
    private int $branchId;
    private array $userIds = [];
    private array $enrollKeys;
    private array $procs = [];
    private bool $wizardFromTree = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (DIRECTORY_SEPARATOR !== '\\') {
            $this->markTestSkipped('the Windows wizard rehearsal runs on Windows only');
        }
        if (getenv('EDGE_WIZARD_REHEARSAL') !== '1') {
            $this->markTestSkipped('opt-in: set EDGE_WIZARD_REHEARSAL=1 (fixed ports 9821-9824, builds two packages)');
        }
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        $base = getenv('EDGE_WIZARD_REHEARSAL_ROOT') ?: 'C:\\Users\\Dell\\BingooEdgeLab\\e09\\b\\rehearsal';
        $this->scratch = rtrim($base, '\\') . '\\run-' . date('His') . '-' . Str::lower(Str::random(4));
        @mkdir($this->scratch, 0775, true);
        $this->tenantCode = 'edgewiz' . Str::lower(Str::random(4));
        $this->enrollKeys = EdgeEnrollmentCrypto::generateKeypair();

        $this->cleanTenant(['edge_branch_authority_leases', 'print_jobs', 'terminal_printer_settings', 'printers', 'model_has_permissions', 'model_has_roles',
            'role_has_permissions', 'permissions', 'roles', 'stock_ledgers', 'stock_balances', 'inventory_batches', 'sale_payments', 'sales_order_lines', 'sales_orders', 'shifts',
            'payment_methods', 'products', 'categories', 'units', 'terminals', 'branches', 'users', 'accounts', 'cash_bank_accounts']);
        (new \Database\Seeders\Tenant\DefaultChartOfAccountsSeeder())->run();
        $this->branchId = $this->makeBranch(['name' => 'Wizard Rehearsal Branch', 'allow_negative_stock' => 0]);
        foreach ([['Owner Wiz', 'OWN'], ['Counter One', 'C1'], ['Counter Two', 'C2'], ['No Code Helper', null]] as [$name, $code]) {
            $this->userIds[] = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => $code === null ? null : $code . strtoupper(Str::random(3)), 'name' => $name]);
        }
        foreach (array_slice($this->userIds, 0, 3) as $uid) {
            foreach ($this->onlinePosParityPermissions() as $p) {
                $this->grantEdgePermission($uid, $p);
            }
        }
        $this->grantEdgePermission($this->userIds[0], 'tenant.offline-edge.security');
        $this->makeTerminal($this->branchId);
        $conn = DB::connection('tenant');
        $unit = $conn->table('units')->insertGetId(['code' => 'pc', 'name' => 'Piece', 'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $biryani = $this->makeProduct($this->makeCategory(), ['name' => 'Biryani', 'unit_id' => $unit, 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 450]);
        // Official stock so the warm standby's stock baseline is not empty (STANDBY_READY instead of DEGRADED).
        $batch = $conn->table('inventory_batches')->insertGetId(['batch_key' => "b-{$this->branchId}-{$biryani}", 'branch_id' => $this->branchId, 'product_id' => $biryani, 'batch_no' => 'B1', 'received_date' => now()->toDateString(), 'unit_cost' => 200, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $conn->table('stock_balances')->insert(['balance_key' => "{$this->branchId}-{$biryani}-0-{$batch}", 'branch_id' => $this->branchId, 'product_id' => $biryani, 'inventory_batch_id' => $batch, 'quantity_on_hand' => 50, 'average_cost' => 200, 'created_at' => now(), 'updated_at' => now()]);
        $cash = $this->makePaymentMethod(['method_type' => 'cash']);
        $acc = $conn->table('accounts')->where('code', '1000')->value('id') ?? $conn->table('accounts')->value('id');
        $cb = $conn->table('cash_bank_accounts')->insertGetId(['code' => 'TILL', 'name' => 'Till', 'account_type' => 'cash', 'account_id' => $acc, 'current_balance' => 0, 'is_active' => 1, 'is_default' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $conn->table('payment_methods')->where('id', $cash)->update(['cash_bank_account_id' => $cb]);

        $m = DB::connection('master');
        $m->table('tenant_databases')->where('db_database', (string) config('database.connections.tenant.database'))->delete();
        $this->cloudTenantId = $m->table('tenants')->insertGetId(['tenant_code' => $this->tenantCode, 'business_name' => 'Wizard Rehearsal Biryani', 'owner_name' => 'Owner', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $c = config('database.connections.tenant');
        $m->table('tenant_databases')->insert(['tenant_id' => $this->cloudTenantId, 'db_connection' => 'tenant', 'db_host' => $c['host'], 'db_port' => (int) $c['port'], 'db_database' => $c['database'], 'db_username' => $c['username'], 'db_password' => null, 'migration_status' => 'completed', 'created_at' => now(), 'updated_at' => now()]);
        $this->dropDb(self::DB);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->procs) as $p) {
            @exec('taskkill /PID ' . $p['pid'] . ' /T /F >nul 2>&1');
            @proc_close($p['proc']);
        }
        if (getenv('EDGE_WIZARD_REHEARSAL_KEEP') !== '1') {
            $this->dropDb(self::DB);
        }
        try {
            $m = DB::connection('master');
            if (isset($this->cloudTenantId)) {
                $snapshots = $m->table('edge_bootstrap_snapshots')->where('tenant_id', $this->cloudTenantId)->pluck('id')->all();
                if ($snapshots !== []) {
                    $m->table('edge_bootstrap_snapshot_sections')->whereIn('snapshot_id', $snapshots)->delete();
                }
                foreach (['edge_bootstrap_snapshots', 'edge_devices', 'edge_pairing_codes', 'edge_branch_activations', 'edge_branch_config_revisions', 'edge_reconciliation_markers', 'edge_backup_recovery_audits', 'edge_backup_recovery_keys'] as $t) {
                    if ($m->getSchemaBuilder()->hasTable($t)) {
                        $m->table($t)->where('tenant_id', $this->cloudTenantId)->delete();
                    }
                }
                $m->table('tenant_databases')->where('tenant_id', $this->cloudTenantId)->delete();
                $m->table('tenants')->where('id', $this->cloudTenantId)->delete();
            }
        } catch (\Throwable) {
        }
        config(['edge.app_version' => env('EDGE_APP_VERSION', '0.1.0-edge'), 'edge.testing.assume_entitled' => false]);
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    public function test_the_headless_wizard_installs_enrolls_and_finishes_then_updates_in_place(): void
    {
        EdgeTenantMigrationManifest::overrideForTests(null);
        $t0 = microtime(true);
        // 1. Packages (DEV provenance, rehearsal keystore — never the release key).
        // EDGE_WIZARD_REHEARSAL_REUSE=<earlier run dir>: reuse its two packages + keystore and run the wizard scripts of THIS tree
        // against them (iteration aid; the acceptance run builds fresh packages and runs the wizard FROM the package).
        $reuse = (string) (getenv('EDGE_WIZARD_REHEARSAL_REUSE') ?: '');
        $base = $reuse !== '' ? rtrim($reuse, '\\') : $this->scratch;
        $keystore = $base . '\\custody\\rehearsal.keystore.json';
        $pass = $base . '\\custody\\rehearsal.passphrase';
        $pkgA = $base . '\\pkg-0.9.0';
        $pkgB = $base . '\\pkg-0.9.1';
        $this->wizardFromTree = $reuse !== '';
        if ($reuse === '') {
            @mkdir(dirname($keystore), 0700, true);
            file_put_contents($pass, 'wizard-rehearsal-' . Str::random(20) . PHP_EOL);
            $this->assertSame(0, Artisan::call('edge:update:keygen', ['--keystore' => $keystore, '--passphrase-file' => $pass, '--label' => 'e09b-wizard-rehearsal']), Artisan::output());
            $opts = ['--allow-dirty' => true, '--signing-keystore' => $keystore, '--signing-passphrase-file' => $pass, '--vendor-junction' => base_path('vendor')];
            config(['edge.app_version' => '0.9.0-edge']);
            $this->assertSame(0, Artisan::call('edge:build-package', ['dest' => $pkgA, '--git-commit' => 'wizard-rehearsal-a'] + $opts), Artisan::output());
            config(['edge.app_version' => '0.9.1-edge']);
            $this->assertSame(0, Artisan::call('edge:build-package', ['dest' => $pkgB, '--git-commit' => 'wizard-rehearsal-b'] + $opts), Artisan::output());
            config(['edge.app_version' => env('EDGE_APP_VERSION', '0.1.0-edge')]);
        }
        $updatePublic = EdgeSigningKeyStore::describe($keystore)['public_key'];
        foreach ([$pkgA, $pkgB] as $pkg) {
            $this->assertFileExists($pkg . '\\scripts\\wizard\\Start-BingooEdgeWizard.ps1', 'the wizard rides inside the package');
            $this->assertFileExists($pkg . '\\scripts\\wizard\\assets\\bingoo.ico');
        }
        $report = ['packages_built_s' => (int) (microtime(true) - $t0)];

        // 2. The rehearsal Cloud.
        $cloudDir = $this->scratch . '\\cloud';
        @mkdir($cloudDir, 0775, true);
        $setupCode = 'WZ' . strtoupper(Str::random(8));
        file_put_contents($cloudDir . '\\setup.json', json_encode([
            'setup_code' => $setupCode, 'tenant_id' => $this->cloudTenantId, 'branch_id' => $this->branchId, 'issuer_user_id' => $this->userIds[0],
            'enrollment_public_key' => $this->enrollKeys['public'], 'update_public_key_fingerprint' => EdgeSigningKeyStore::keyId($updatePublic),
            'maintenance_window' => ['start' => '03:00', 'end' => '05:00', 'tz' => 'Asia/Karachi'],
        ], JSON_PRETTY_PRINT));
        $this->startCloud($cloudDir);

        // 3. The answers (NO secret) + the TEST-only secret injection.
        $installRoot = $this->scratch . '\\Bingoo Edge';
        $dataRoot = $this->scratch . '\\ProgramData\\BingooEdge';
        $c = config('database.connections.tenant');
        $answers = [
            'cloud_url' => 'http://127.0.0.1:' . self::CLOUD_PORT, 'allow_http_cloud' => true,
            'device_name' => 'WIZARD-REHEARSAL-1', 'install_root' => $installRoot, 'data_root' => $dataRoot, 'log_dir' => $this->scratch . '\\wizard-logs',
            'php_path' => PHP_BINARY, 'update_public_key' => $updatePublic,
            'mysql' => ['mode' => 'existing', 'host' => (string) $c['host'], 'port' => (int) $c['port'], 'db_name' => self::DB, 'db_user' => (string) $c['username']],
            'certificates' => ['mode' => 'self_signed_lab'],
            'lan' => ['hostname' => 'bingoo-edge.test', 'ip' => '127.0.0.1', 'https_port' => self::HTTPS_PORT, 'http_port' => self::HTTP_PORT],
            'services' => ['register' => false, 'gateway' => false, 'firewall' => false, 'hosts_line' => false, 'web_workers' => 1, 'web_port_base' => self::WEB_PORT],
            'prerequisites' => ['fix_clock' => false, 'fix_sleep' => false, 'fix_network_private' => false],
            'users' => ['enroll' => $this->userIds],
            'shortcuts' => ['create' => true, 'dir' => $this->scratch . '\\Desktop', 'icon_dir' => $this->scratch . '\\BingooPOS'],
            'update' => ['approve_any_plan_for_lab' => true, 'health_wait_seconds' => 60],
            'result_file' => $this->scratch . '\\result-install.json',
        ];
        $answersFile = $this->scratch . '\\answers.json';
        file_put_contents($answersFile, json_encode($answers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $pins = [];
        $secrets = ['BINGOO_WIZARD_TEST_SECRETS' => '1', 'BINGOO_WIZARD_SECRET_SETUP_CODE' => $setupCode,
            'BINGOO_WIZARD_SECRET_DB_PASSWORD' => ((string) ($c['password'] ?? '')) !== '' ? (string) $c['password'] : '__EMPTY__'];
        foreach ($this->userIds as $i => $uid) {
            $pins[$uid] = (string) (482913 + 111111 * $i + random_int(0, 9));
            $secrets['BINGOO_WIZARD_SECRET_PIN_' . $uid] = $pins[$uid];
        }

        // 4. INSTALL (headless wizard from the package).
        [$code, $out] = $this->wizard($pkgA, $answersFile, $secrets);
        $report['install_s'] = (int) (microtime(true) - $t0) - $report['packages_built_s'];
        file_put_contents($this->scratch . '\\wizard-install.out.txt', $out);
        $this->assertSame(0, $code, "headless install failed:\n" . $out . $this->logs($dataRoot, $cloudDir));
        $this->assertStringContainsString('Bingoo Edge setup result', $out);
        $result = json_decode(ltrim((string) file_get_contents($answers['result_file']), "﻿"), true);
        $this->assertSame('install', $result['Mode']);
        $this->assertSame($this->tenantCode, $result['Tenant']);
        $byUser = collect($result['Users'])->keyBy('UserId');
        foreach (array_slice($this->userIds, 0, 3) as $uid) {
            $this->assertTrue($byUser[$uid]['Ok'], "user #{$uid} enrolled: " . json_encode($byUser[$uid]));
        }
        $this->assertFalse($byUser[$this->userIds[3]]['Ok'], 'a user without an employee code is skipped by the Cloud');
        $this->assertStringContainsString('no_employee_code', $byUser[$this->userIds[3]]['Detail']);

        // appliance.env: paired with the installation uuid the setup code was redeemed with.
        $env = str_replace("\r\n", "\n", (string) file_get_contents($dataRoot . '\\config\\appliance.env'));
        $cloudState = json_decode((string) file_get_contents($cloudDir . '\\setup.json'), true);
        $this->assertTrue($cloudState['redeemed']);
        $this->assertMatchesRegularExpression('/^EDGE_SYNC_DEVICE_ID=[0-9a-f-]{36}$/m', $env);
        $this->assertMatchesRegularExpression('/^EDGE_INSTALLATION_UUID=' . preg_quote($cloudState['installation_uuid'], '/') . '$/m', $env);
        $this->assertMatchesRegularExpression('/^EDGE_AUTO_UPDATE_WINDOW_TZ=Asia\/Karachi$/m', $env);
        preg_match('/^EDGE_SYNC_DEVICE_SECRET=(\S+)$/m', $env, $sec);
        // Every enrolled user holds a PIN credential that verifies against EXACTLY the PIN typed for him.
        $local = $this->pdo(self::DB);
        $creds = $local->query("SELECT user_id, credential_type, credential_hash FROM edge_local_user_credentials WHERE status = 'active'")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(3, $creds);
        foreach ($creds as $row) {
            $this->assertSame('pin', $row['credential_type']);
            $this->assertTrue(password_verify($pins[(int) $row['user_id']], $row['credential_hash']), 'the stored PIN is exactly the typed one (no CR/LF)');
        }
        // K4 / K5 reached the Cloud; K3 issued exactly the three eligible users.
        $k4 = array_map(fn ($l) => json_decode($l, true)['phase'], array_filter(explode("\n", (string) @file_get_contents($cloudDir . '\\k4.jsonl'))));
        foreach (['bootstrapped', 'enrolled', 'ready'] as $phase) {
            $this->assertContains($phase, $k4, "K4 {$phase}");
        }
        $k5 = json_decode(trim(explode("\n", trim((string) file_get_contents($cloudDir . '\\k5.jsonl')))[0]), true);
        $this->assertSame('127.0.0.1', $k5['lan_ip']);
        $this->assertSame(self::HTTPS_PORT, $k5['https_port']);
        $k3 = json_decode(trim((string) file_get_contents($cloudDir . '\\k3.jsonl')), true);
        $this->assertEqualsCanonicalizing(array_slice($this->userIds, 0, 3), $k3['issued']);
        // Shortcuts with the Bingoo icon.
        foreach (['BINGOO Online', 'BINGOO Offline', 'Branch Server Status'] as $name) {
            $url = (string) file_get_contents($this->scratch . '\\Desktop\\' . $name . '.url');
            $this->assertStringContainsString('IconFile=' . $this->scratch . '\\BingooPOS\\bingoo.ico', $url);
        }
        $this->assertStringContainsString('URL=https://bingoo-edge.test:' . self::HTTPS_PORT . '/edge/local/login', (string) file_get_contents($this->scratch . '\\Desktop\\BINGOO Offline.url'));
        $this->assertFileExists($this->scratch . '\\BingooPOS\\bingoo.ico');
        // No secret in any log / output.
        $haystack = $out . $this->logs($dataRoot, $cloudDir);
        foreach (array_merge([$setupCode, $sec[1] ?? 'no-secret-found'], array_values($pins)) as $secret) {
            $this->assertStringNotContainsString($secret, $haystack, 'a secret leaked into a log');
        }

        // 5. UPDATE MODE: the same wizard, a newer signed package, detected from appliance.env (no re-pairing).
        $answers['result_file'] = $this->scratch . '\\result-update.json';
        $answers['users'] = ['enroll' => 'none'];
        file_put_contents($answersFile, json_encode($answers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $deviceBefore = $this->envValue($dataRoot, 'EDGE_SYNC_DEVICE_ID');
        $t1 = microtime(true);
        [$code, $out] = $this->wizard($pkgB, $answersFile, ['BINGOO_WIZARD_TEST_SECRETS' => '1']);
        $report['update_s'] = (int) (microtime(true) - $t1);
        file_put_contents($this->scratch . '\\wizard-update.out.txt', $out);
        $this->assertSame(0, $code, "headless update failed:\n" . $out . $this->logs($dataRoot, $cloudDir));
        $upd = json_decode(ltrim((string) file_get_contents($answers['result_file']), "﻿"), true);
        $this->assertSame(['update', '0.9.0-edge', '0.9.1-edge', 'updated'], [$upd['Mode'], $upd['From'], $upd['To'], $upd['Result']], json_encode($upd));
        $this->assertSame('0.9.1-edge', trim((string) file_get_contents($installRoot . '\\runtime\\current')));
        $this->assertSame($deviceBefore, $this->envValue($dataRoot, 'EDGE_SYNC_DEVICE_ID'), 'no re-pairing');
        $this->assertSame(3, (int) $this->pdo(self::DB)->query("SELECT COUNT(*) FROM edge_local_user_credentials WHERE status='active'")->fetchColumn(), 'users kept');
        $k4 = array_map(fn ($l) => json_decode($l, true)['phase'], array_filter(explode("\n", (string) file_get_contents($cloudDir . '\\k4.jsonl'))));
        $this->assertContains('updating', $k4);
        $this->assertContains('updated', $k4);
        $this->assertFileExists($installRoot . '\\scripts\\wizard\\Start-BingooEdgeWizard.ps1', 'installed scripts refreshed from the package');
        $this->assertSame([], glob($dataRoot . '\\updates\\snapshots\\*.snap') ?: [], 'the pre-update snapshot is removed after a healthy update');
        file_put_contents($this->scratch . '\\rehearsal-report.json', json_encode($report, JSON_PRETTY_PRINT));
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    /** @return array{0:int,1:string} */
    private function wizard(string $pkg, string $answersFile, array $secrets): array
    {
        $script = $this->wizardFromTree ? base_path('scripts\\edge\\wizard\\Start-BingooEdgeWizard.ps1') : $pkg . '\\scripts\\wizard\\Start-BingooEdgeWizard.ps1';
        $cmd = ['powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', $script, '-Headless', '-AnswersFile', $answersFile, '-PackageRoot', $pkg];
        $log = $this->scratch . '\\wizard-' . basename($pkg) . '.log';
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log . '.err', 'w']], $pipes, $this->scratch, array_merge($this->cleanEnv(), $secrets));
        fclose($pipes[0]);
        $deadline = time() + 60 * 60;
        while (($st = proc_get_status($proc)) && $st['running'] && time() < $deadline) {
            usleep(500000);
        }
        if ($st['running']) {
            @exec('taskkill /PID ' . $st['pid'] . ' /T /F >nul 2>&1');
        }
        $code = proc_close($proc);
        $code = $st['running'] ? 124 : (int) ($st['exitcode'] ?? $code);

        return [$code, (string) @file_get_contents($log) . "\nSTDERR:\n" . (string) @file_get_contents($log . '.err')];
    }

    private function startCloud(string $cloudDir): void
    {
        $m = config('database.connections.master');
        $t = config('database.connections.tenant');
        $env = array_merge(getenv() ?: [], [
            'APP_ROLE' => 'cloud', 'APP_ENV' => 'testing', 'APP_DEBUG' => 'true', 'APP_KEY' => (string) config('app.key'), 'APP_URL' => 'http://127.0.0.1:' . self::CLOUD_PORT,
            'CENTRAL_DOMAIN' => '127.0.0.1', 'TENANT_BASE_DOMAIN' => '127.0.0.1',
            'DB_CONNECTION' => 'master', 'DB_HOST' => (string) $m['host'], 'DB_PORT' => (string) $m['port'], 'DB_DATABASE' => (string) $m['database'], 'DB_USERNAME' => (string) $m['username'], 'DB_PASSWORD' => (string) ($m['password'] ?? ''),
            'TENANT_DB_HOST' => (string) $t['host'], 'TENANT_DB_PORT' => (string) $t['port'], 'TENANT_DB_USERNAME' => (string) $t['username'], 'TENANT_DB_PASSWORD' => (string) ($t['password'] ?? ''),
            'EDGE_FEATURE_ENABLED' => 'true', 'EDGE_TESTING_ASSUME_ENTITLED' => 'true', 'EDGE_ENROLLMENT_SIGNING_KEY' => $this->enrollKeys['secret'],
            'EDGE_WIZARD_FAKE_CLOUD_DIR' => $cloudDir,
            'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'LOG_CHANNEL' => 'stderr',
            'PULSE_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false',
        ]);
        unset($env['BINGOO_EDGE_ENV_DIR'], $env['EDGE_LOCAL_APP_KEY'], $env['EDGE_DB_DATABASE']);
        $log = $this->scratch . '\\proc-cloud.log';
        $proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . self::CLOUD_PORT, '-t', public_path(), base_path('tests\\Fixtures\\EdgeWizardFakeCloud.php')],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, base_path(), $env);
        fclose($pipes[0]);
        $this->procs[] = ['proc' => $proc, 'pid' => (int) proc_get_status($proc)['pid']];
        $deadline = microtime(true) + 30;
        while (microtime(true) < $deadline) {
            $fp = @fsockopen('127.0.0.1', self::CLOUD_PORT, $e, $s, 0.5);
            if (is_resource($fp)) {
                fclose($fp);
                break;
            }
            usleep(250000);
        }
        $up = @file_get_contents('http://127.0.0.1:' . self::CLOUD_PORT . '/up');
        $this->assertNotFalse($up, 'the rehearsal Cloud answers /up: ' . (string) @file_get_contents($log));
    }

    /** The wizard's environment: nothing from the dev tree's Laravel env (the appliance reads appliance.env only). */
    private function cleanEnv(): array
    {
        $env = getenv() ?: [];
        foreach (array_keys($env) as $k) {
            if (str_starts_with($k, 'APP_') || str_starts_with($k, 'DB_') || str_starts_with($k, 'EDGE_') || str_starts_with($k, 'TENANT_') || in_array($k, ['SESSION_DRIVER', 'CACHE_STORE', 'QUEUE_CONNECTION', 'LOG_CHANNEL', 'MAIL_MAILER', 'CENTRAL_DOMAIN', 'BINGOO_EDGE_ENV_DIR'], true)) {
                unset($env[$k]);
            }
        }

        return $env;
    }

    private function envValue(string $dataRoot, string $key): ?string
    {
        return preg_match('/^' . preg_quote($key, '/') . '=(.*)$/m', str_replace("\r\n", "\n", (string) file_get_contents($dataRoot . '\\config\\appliance.env')), $m) ? trim($m[1], "'\"") : null;
    }

    private function logs(string $dataRoot, string $cloudDir): string
    {
        $out = '';
        foreach (array_merge(glob($this->scratch . '\\wizard-logs\\*') ?: [], glob($dataRoot . '\\logs\\*.log') ?: [], [$this->scratch . '\\proc-cloud.log'], glob($cloudDir . '\\*.jsonl') ?: []) as $f) {
            if (is_file($f)) {
                $out .= "\n--- " . basename($f) . "\n" . substr((string) file_get_contents($f), -6000);
            }
        }

        return $out;
    }

    private function pdo(string $db): PDO
    {
        $c = config('database.connections.tenant');

        return new PDO("mysql:host={$c['host']};port={$c['port']};dbname={$db};charset=utf8mb4", $c['username'], $c['password'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    private function dropDb(string $db): void
    {
        try {
            $c = config('database.connections.tenant');
            (new PDO("mysql:host={$c['host']};port={$c['port']};charset=utf8mb4", $c['username'], $c['password'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]))->exec('DROP DATABASE IF EXISTS `' . $db . '`');
        } catch (\Throwable) {
        }
    }
}
