<?php

namespace Tests\MySql;

use App\Models\Master\EdgeDevice;
use App\Models\Master\Tenant;
use App\Models\Tenant\Branch;
use App\Models\Tenant\User;
use App\Services\Edge\EdgeEnrollmentCrypto;
use App\Services\Edge\EdgeEnrollmentIssuer;
use App\Services\Edge\EdgePairingService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\EdgeTestDatabases;
use Tests\MySql\Support\TenantFixtures;

/**
 * P4 §16 — ACTUAL CLEAN-MACHINE INSTALL PROOF (the closest this non-elevated dev box can get to a fresh Windows VM).
 *
 * Everything runs as REAL separate processes against REAL databases, from a PACKAGE built by `edge:build-package`:
 *
 *   Cloud   = the dev tree served by a real `php -S` (master + tenant test DBs, real pairing / bootstrap / heartbeat /
 *             baseline / refresh endpoints over HTTP, entitlement assumed through the APP_ENV=testing seam);
 *   Package = dev packages of THIS tree (app/vendor junctioned to the shared closure; B carries the signed 0.2.0 update);
 *   Install = Install-EdgeAppliance.ps1 from that package into a FRESH install root + data root + a FRESH local
 *             database, through the launcher (`<InstallRoot>\artisan`), with -NoServices (Register-ScheduledTask is
 *             denied to a non-admin user — the task registration / reboot / auto-start items stay on the PHYSICAL
 *             certification list) and a LAB self-signed gateway certificate.
 *
 * Proven: package verified → layout → appliance.env (secrets from files, never argv) → db-init on a fresh DB → pair
 * (device secret generated locally) → bootstrap-pull → self-signed gateway cert → nginx.conf + service plan → two warm
 * ticks → health = bound, first heartbeat acknowledged, stock fresh → enrol the first cashier from a Cloud-signed
 * assertion → the installed runtime serves HTTP on loopback and the TLS gateway fronts it (cashier login + POS page +
 * health page over HTTPS) → encrypted backup → restore into a FRESH database B (wrong branch refused; pending event
 * preserved) → signed update to 0.2.0 (tampered package refused first; pointer switch, pre-update backup, outbox kept)
 * → uninstall preserves data by default → data removal refuses while unsynced events exist → explicit removal works.
 *
 * Dev-package caveat (documented, not hidden): app/vendor is a JUNCTION to the shared closure and PHP resolves junctions in
 * __DIR__, so Composer roots `App\` at the shared tree — the installed runtime runs THIS tree's classes with the ARTIFACT's
 * bootstrap / config / routes / public / launcher / manifest. A release package carries a real vendor and runs only its own
 * files (the physical-exclusion and artifact-boot gates cover that shape).
 *
 * Never LOCAL_ACTIVE, never a production system, never a live branch.
 */
class EdgeCleanMachineInstallMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private string $scratch;
    private string $installRoot;
    private string $dataRoot;
    private string $pkgA;
    private string $pkgB;
    private int $cloudPort;
    private int $webPort;
    private int $httpsPort;
    private int $httpPort;
    private string $installDb;
    private string $installDb2;
    private string $tenantCode;
    private int $cloudTenantId;
    private int $branchId;
    private int $userId;
    private string $employeeCode;
    private array $enrollKeys;
    private array $updateKeys;
    private string $recoveryKey;
    /** @var array<int,array{proc:resource,pid:int,name:string}> */
    private array $procs = [];
    private string $nginx = '';
    private array $report = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (DIRECTORY_SEPARATOR !== '\\') {
            $this->markTestSkipped('the Windows appliance install proof runs on Windows only');
        }
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        $this->scratch = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'edge-clean-' . Str::lower(Str::random(6));
        mkdir($this->scratch, 0775, true);
        $this->installRoot = $this->scratch . '\\Bingoo Edge';
        $this->dataRoot = $this->scratch . '\\ProgramData\\BingooEdge';
        $this->pkgA = $this->scratch . '\\pkg-0.1.0';
        $this->pkgB = $this->scratch . '\\pkg-0.2.0';
        $this->cloudPort = 9700 + random_int(0, 99);
        $this->webPort = 9800 + random_int(0, 99);
        $this->httpsPort = 9900 + random_int(0, 49);
        $this->httpPort = 9950 + random_int(0, 49);
        $this->installDb = EdgeTestDatabases::local('install');
        $this->installDb2 = EdgeTestDatabases::local('install2');
        $this->tenantCode = 'edgeinst' . Str::lower(Str::random(4));
        $this->enrollKeys = EdgeEnrollmentCrypto::generateKeypair();
        $this->updateKeys = EdgeEnrollmentCrypto::generateKeypair();
        $this->recoveryKey = base64_encode(random_bytes(32));
        $this->nginx = is_file('D:\\laragon2\\bin\\nginx\\nginx-1.22.0\\nginx.exe') ? 'D:\\laragon2\\bin\\nginx\\nginx-1.22.0\\nginx.exe' : (string) ((new \Symfony\Component\Process\ExecutableFinder())->find('nginx') ?? '');

        // ── the Cloud's truth (tenant test DB) ──
        $this->cleanTenant(['edge_branch_authority_leases', 'edge_inbound_sale_ingestions', 'print_jobs', 'terminal_printer_settings', 'printers', 'model_has_permissions', 'model_has_roles',
            'role_has_permissions', 'permissions', 'roles', 'stock_ledgers', 'stock_balances', 'inventory_batches', 'sale_payments', 'sales_order_lines', 'sales_orders', 'shifts',
            'payment_methods', 'products', 'categories', 'units', 'terminals', 'branches', 'users', 'accounts', 'cash_bank_accounts']);
        (new \Database\Seeders\Tenant\DefaultChartOfAccountsSeeder())->run();
        $this->branchId = $this->makeBranch(['name' => 'Install Branch', 'allow_negative_stock' => 0]);
        $this->employeeCode = 'IN' . strtoupper(Str::random(4));
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => $this->employeeCode, 'name' => 'Install Cashier']);
        $terminalId = $this->makeTerminal($this->branchId);
        $conn = DB::connection('tenant');
        $pc = $conn->table('units')->insertGetId(['code' => 'pc', 'name' => 'Piece', 'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $cat = $this->makeCategory();
        $burger = $this->makeProduct($cat, ['name' => 'Burger', 'unit_id' => $pc, 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 100]);
        $cash = $this->makePaymentMethod(['method_type' => 'cash']);
        $accountId = $conn->table('accounts')->where('code', '1000')->value('id') ?? $conn->table('accounts')->value('id');
        $cbId = $conn->table('cash_bank_accounts')->insertGetId(['code' => 'TILL', 'name' => 'Till', 'account_type' => 'cash', 'account_id' => $accountId, 'current_balance' => 0, 'is_active' => 1, 'is_default' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $conn->table('payment_methods')->where('id', $cash)->update(['cash_bank_account_id' => $cbId]);
        $batchId = $conn->table('inventory_batches')->insertGetId(['batch_key' => "b-{$this->branchId}-{$burger}", 'branch_id' => $this->branchId, 'product_id' => $burger, 'batch_no' => 'B1', 'received_date' => now()->toDateString(), 'unit_cost' => 40, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $conn->table('stock_balances')->insert(['balance_key' => "{$this->branchId}-{$burger}-0-{$batchId}", 'branch_id' => $this->branchId, 'product_id' => $burger, 'inventory_batch_id' => $batchId, 'quantity_on_hand' => 100, 'average_cost' => 40, 'created_at' => now(), 'updated_at' => now()]);
        $printer = $this->makePrinter(['branch_id' => $this->branchId, 'printer_type' => 'network', 'print_role' => 'both', 'ip_address' => '192.168.1.60', 'port' => 9100, 'is_active' => 1, 'name' => 'Counter LAN']);
        $conn->table('terminal_printer_settings')->insert(['terminal_id' => $terminalId, 'receipt_printer_id' => $printer, 'kot_printer_id' => $printer, 'auto_print_receipt' => 1, 'auto_print_kot' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->grantEdgePermission($this->userId, 'tenant.pos.store');

        // ── the Cloud's master registration (tenant + its database; NO device — pairing creates it) ──
        $m = DB::connection('master');
        $m->table('tenant_databases')->where('db_database', (string) config('database.connections.tenant.database'))->delete();
        $m->table('tenants')->where('tenant_code', $this->tenantCode)->delete();
        $this->cloudTenantId = $m->table('tenants')->insertGetId(['tenant_code' => $this->tenantCode, 'business_name' => 'Edge Install Proof', 'owner_name' => 'Owner', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $c = config('database.connections.tenant');
        $m->table('tenant_databases')->insert(['tenant_id' => $this->cloudTenantId, 'db_connection' => 'tenant', 'db_host' => $c['host'], 'db_port' => (int) $c['port'], 'db_database' => $c['database'], 'db_username' => $c['username'], 'db_password' => null, 'migration_status' => 'completed', 'created_at' => now(), 'updated_at' => now()]);
        $this->cleanupMasterEdgeRows();

        config(['edge.testing.assume_entitled' => true, 'app.edge_feature_enabled' => true, 'edge.enrollment.signing_key' => $this->enrollKeys['secret']]);
        $this->dropDb($this->installDb);
        $this->dropDb($this->installDb2);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->procs) as $p) {
            $this->killTree($p['pid']);
            @proc_close($p['proc']);
        }
        $this->dropDb($this->installDb);
        $this->dropDb($this->installDb2);
        $this->cleanupMasterEdgeRows();
        try {
            $m = DB::connection('master');
            $m->table('tenant_databases')->where('tenant_id', $this->cloudTenantId)->delete();
            $m->table('tenants')->where('id', $this->cloudTenantId)->delete();
        } catch (\Throwable) {
        }
        $this->removeTree($this->scratch);
        config(['edge.testing.assume_entitled' => false, 'edge.app_version' => env('EDGE_APP_VERSION', '0.1.0-edge')]);
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    // ── the proof ───────────────────────────────────────────────────────────

    public function test_a_fresh_machine_installs_from_the_package_binds_to_the_cloud_serves_the_cashier_backs_up_updates_and_uninstalls_safely(): void
    {
        // 1. Packages: A = 0.1.0-edge (install), B = 0.2.0-edge (signed update). Real `edge:build-package` runs.
        $keyFile = $this->scratch . '\\update-signing.key';
        file_put_contents($keyFile, $this->updateKeys['secret']);
        $this->assertSame(0, Artisan::call('edge:build-package', ['dest' => $this->pkgA, '--allow-dirty' => true, '--git-commit' => 'clean-install-proof', '--signing-key-file' => $keyFile, '--vendor-junction' => base_path('vendor')]), Artisan::output());
        config(['edge.app_version' => '0.2.0-edge']);
        $this->assertSame(0, Artisan::call('edge:build-package', ['dest' => $this->pkgB, '--allow-dirty' => true, '--git-commit' => 'clean-install-proof-2', '--signing-key-file' => $keyFile, '--vendor-junction' => base_path('vendor')]), Artisan::output());
        config(['edge.app_version' => env('EDGE_APP_VERSION', '0.1.0-edge')]);
        @unlink($keyFile);
        $manifestA = json_decode((string) file_get_contents($this->pkgA . '\\package-manifest.json'), true);
        $manifestB = json_decode((string) file_get_contents($this->pkgB . '\\package-manifest.json'), true);
        $this->assertSame('0.1.0-edge', $manifestA['edge_app_version']);
        $this->assertSame('0.2.0-edge', $manifestB['edge_app_version']);
        $this->assertTrue($manifestA['boundary_audit']['ok'] && $manifestB['boundary_audit']['ok']);
        $this->assertTrue($manifestB['components']['update']['signed']);
        $this->report['WINDOWS_PACKAGE'] = 'built (dev provenance; boundary audit ok; signed update ' . $manifestB['components']['update']['file'] . ')';

        // 2. The Cloud: a REAL php -S over the master + tenant test databases.
        $this->startCloud();

        // 3. The one-time pairing code (15-minute TTL) is issued when the installer ASKS for it — after db-init, whose
        //    duration on this box depends on MySQL's fsync speed (5 minutes … hours on a bad day).
        $tenant = Tenant::find($this->cloudTenantId);
        $branch = Branch::on('tenant')->find($this->branchId);
        $codeFile = $this->scratch . '\\pairing-code.txt';
        $dbPwFile = $this->scratch . '\\dbpw.txt';
        file_put_contents($dbPwFile, (string) (config('database.connections.tenant.password') ?? ''));
        $recoveryFile = $this->scratch . '\\recovery.key';
        file_put_contents($recoveryFile, $this->recoveryKey);

        // 4. INSTALL from the package (first-boot flow) — -NoServices: task registration is admin-only on this box.
        $c = config('database.connections.tenant');
        $args = [
            '-PackageRoot', $this->pkgA, '-InstallRoot', $this->installRoot, '-DataRoot', $this->dataRoot, '-PhpPath', PHP_BINARY,
            '-DbHost', (string) $c['host'], '-DbPort', (string) $c['port'], '-DbName', $this->installDb, '-DbUser', (string) $c['username'], '-DbPasswordFile', $dbPwFile,
            '-CloudUrl', 'http://127.0.0.1:' . $this->cloudPort, '-AllowHttpCloud', '-PairingCodeFile', $codeFile, '-DeviceName', 'clean-install-proof',
            '-EnrollmentPublicKey', $this->enrollKeys['public'], '-UpdatePublicKey', $this->updateKeys['public'], '-RecoveryKeyFile', $recoveryFile,
            '-LanHostname', 'bingoo-edge.test', '-LanIp', '127.0.0.1', '-SelfSignedCert', '-HttpsPort', (string) $this->httpsPort, '-HttpPort', (string) $this->httpPort,
            '-WebWorkers', '1', '-WebPortBase', (string) $this->webPort, '-NoServices',
        ];
        if ($this->nginx !== '') {
            $args[] = '-GatewayPath';
            $args[] = $this->nginx;
        } else {
            $args[] = '-NoGateway';
        }
        $this->startProcess(array_merge(['powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', $this->pkgA . '\\scripts\\Install-EdgeAppliance.ps1'], $args), $this->scratch, 'installer', $this->applianceEnv());
        $this->waitForLog('installer', 'waiting up to', 60 * 60, 'the installer reaching the pairing step (db-init on a fresh database)');
        $issued = app(EdgePairingService::class)->generateCode($tenant, $branch, $this->userId);
        file_put_contents($codeFile, $issued['code']);
        [$code, $out] = $this->waitProcess('installer', 20 * 60);
        $this->assertSame(0, $code, "Install-EdgeAppliance.ps1 failed:\n" . $out . "\n" . $this->applianceLogTail());
        $this->assertStringContainsString('INSTALL COMPLETE', $out);
        $this->assertStringNotContainsString($this->recoveryKey, $out, 'the installer never prints a secret');
        $this->assertFileDoesNotExist($codeFile, 'the one-time pairing code file is consumed');
        $this->assertFileDoesNotExist($recoveryFile, 'the recovery key file is consumed');
        // Layout: launcher, layout file, versioned runtime + pointer, data root with the ONLY secrets file, certs, gateway config + plan.
        $this->assertFileExists($this->installRoot . '\\artisan');
        $this->assertFileExists($this->installRoot . '\\appliance.json');
        $this->assertSame('0.1.0-edge', trim((string) file_get_contents($this->installRoot . '\\runtime\\current')));
        $this->assertFileExists($this->installRoot . '\\runtime\\versions\\0.1.0-edge\\edge-build-manifest.json');
        $this->assertFileExists($this->installRoot . '\\runtime\\versions\\0.1.0-edge\\vendor\\autoload.php');
        $env = str_replace("\r\n", "\n", (string) file_get_contents($this->dataRoot . '\\config\\appliance.env'));
        $this->assertMatchesRegularExpression('/^APP_ROLE=branch_server$/m', $env);
        $this->assertMatchesRegularExpression('/^EDGE_SYNC_DEVICE_ID=[0-9a-f-]{36}$/m', $env, 'pairing persisted the device identity');
        $this->assertMatchesRegularExpression('/^EDGE_SYNC_DEVICE_SECRET=[0-9a-f]{64}$/m', $env, 'the locally generated device secret lives ONLY in appliance.env');
        $this->assertMatchesRegularExpression('/^EDGE_LOCAL_APP_KEY=base64:/m', $env);
        $this->assertStringContainsString('EDGE_BACKUP_RECOVERY_KEY=' . $this->recoveryKey, $env);
        $this->assertFileExists($this->dataRoot . '\\certs\\server.crt');
        $this->assertFileExists($this->dataRoot . '\\certs\\server.key');
        $this->assertFileExists($this->dataRoot . '\\gateway\\nginx.conf');
        $this->assertFileExists($this->dataRoot . '\\gateway\\service-plan.json');
        $plan = json_decode((string) file_get_contents($this->dataRoot . '\\gateway\\service-plan.json'), true);
        $this->assertSame(['BingooEdgeWeb1', 'BingooEdgePrintWorker', 'BingooEdgeSyncSender', 'BingooEdgeAuthorityWorker', 'BingooEdgeBackup'], array_column($plan['tasks'], 'name'));
        $this->assertSame('BingooEdgeGateway', $plan['gateway']['name']);
        foreach ($plan['tasks'] as $t) {
            $this->assertSame('NT AUTHORITY\\LOCAL SERVICE', $t['principal']);
            $this->assertStringNotContainsString('SECRET', strtoupper($t['arguments']));
        }
        // The local DB was created fresh and bound; the Cloud sees the paired device READY and the snapshot acknowledged.
        $pdo = $this->pdo($this->installDb);
        $meta = $pdo->query('select tenant_code, branch_id, device_uuid, activation_epoch, runtime_state from edge_local_meta where singleton_guard = 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('bootstrapped', $meta['runtime_state']);
        $this->assertSame($this->tenantCode, $meta['tenant_code']);
        $this->assertSame($this->branchId, (int) $meta['branch_id']);
        $device = EdgeDevice::query()->where('tenant_id', $this->cloudTenantId)->first();
        $this->assertNotNull($device, 'pairing created the Cloud device row');
        $this->assertSame($device->public_uuid, $meta['device_uuid']);
        $this->assertContains($device->status, ['ready', 'active']);
        $this->assertSame(1, (int) DB::connection('master')->table('edge_bootstrap_snapshots')->where('tenant_id', $this->cloudTenantId)->where('status', 'acknowledged')->count());
        $this->assertSame(0, (int) $pdo->query('select count(*) from edge_local_user_credentials')->fetchColumn(), 'no user enrolled yet');
        $this->assertSame('standby', (string) $pdo->query('select authority_state from edge_local_meta')->fetchColumn(), 'never LOCAL_ACTIVE during install');
        $this->report['FIRST_BOOT_BINDING'] = 'pair + bootstrap-pull over real HTTP; device ' . $device->public_uuid;

        // 5. Enrol the first cashier from a Cloud-signed assertion (issued by the tenant's Offline Edge page).
        $assertion = app(EdgeEnrollmentIssuer::class)->issue($tenant, $this->branchId, $device->fresh(), User::on('tenant')->find($this->userId), $this->userId);
        $assertionFile = $this->scratch . '\\assertion.json';
        file_put_contents($assertionFile, json_encode($assertion));
        $credFile = $this->scratch . '\\cred.txt';
        file_put_contents($credFile, 'CashierPass1');
        [$code, $out] = $this->edge(['edge:local:enroll', $assertionFile, '--credential-file=' . $credFile, '--no-interaction']);
        $this->assertSame(0, $code, $out);
        $this->assertFileDoesNotExist($credFile, 'the credential file is consumed');
        $this->assertSame(1, (int) $pdo->query('select count(*) from edge_local_user_credentials where status = \'active\'')->fetchColumn());

        // 6. Health: bound, heartbeat acknowledged by the real Cloud, warm stock baseline fresh; NOT local; no secret.
        [$code, $out] = $this->edge(['edge:local:health', '--json', '--no-interaction']);
        $this->assertSame(0, $code, $out);
        $health = json_decode(substr($out, (int) strpos($out, '{')), true);
        $this->assertIsArray($health, $out);
        $this->assertTrue($health['binding']['bound']);
        $this->assertTrue($health['binding']['device_identity_matches']);
        $this->assertSame(1, $health['binding']['enrolled_local_users']);
        $this->assertSame('standby', $health['authority']['state']);
        $this->assertNotNull($health['authority']['last_heartbeat_ack_at'], 'the warm ticks reached the real Cloud: ' . json_encode($health['authority']));
        $this->assertTrue($health['freshness']['stock']['ok'], json_encode($health['freshness']));
        $this->assertTrue($health['freshness']['config']['ok'], json_encode($health['freshness']));
        $this->assertFalse($health['auto_failover_enabled']);
        $this->assertSame('0.1.0-edge', $health['update']['active_version_pointer']);
        $this->assertTrue($health['runtime']['packaged_artifact'], 'the installed runtime IS the packaged artifact');
        $this->assertStringNotContainsString($this->recoveryKey, $out);
        $this->assertStringNotContainsString(substr($env, (int) strpos($env, 'EDGE_SYNC_DEVICE_SECRET=') + 24, 64), $out, 'no device secret in the health report');
        $this->report['FIRST_WARM_SYNC'] = 'stock ' . json_encode($health['freshness']['stock']['ok']) . ', config ' . json_encode($health['freshness']['config']['ok']) . ', last ack ' . $health['authority']['last_heartbeat_ack_at'];
        $this->report['STANDBY_READY'] = $health['status'] . ' (' . implode('; ', $health['problems']) . ')';

        // 7. The installed runtime SERVES: loopback web backend (edge:local:serve) + the TLS gateway fronting it.
        $this->startEdge(['edge:local:serve', '--worker=1', '--no-interaction'], 'web1');
        $this->waitPort($this->webPort, 30, 'web backend');
        $direct = $this->http('GET', 'http://127.0.0.1:' . $this->webPort . '/edge/local/health');
        $this->assertSame(200, $direct['status'], $direct['body']);
        $this->assertSame('branch_server', json_decode($direct['body'], true)['runtime_mode'] ?? null);
        $ready = json_decode($this->http('GET', 'http://127.0.0.1:' . $this->webPort . '/edge/local/ready')['body'], true);
        $this->assertTrue($ready['ready'] ?? false, json_encode($ready));
        $this->report['EDGE_WEB_SERVICE'] = 'edge:local:serve answers on 127.0.0.1:' . $this->webPort . ' (loopback only)';
        if ($this->nginx !== '') {
            $this->startProcess([$this->nginx, '-p', $this->dataRoot . '\\gateway', '-c', $this->dataRoot . '\\gateway\\nginx.conf'], $this->dataRoot . '\\gateway', 'nginx');
            $this->waitPort($this->httpsPort, 20, 'TLS gateway');
            $viaTls = $this->http('GET', 'https://127.0.0.1:' . $this->httpsPort . '/edge/local/health');
            $this->assertSame(200, $viaTls['status'], $viaTls['body']);
            $redirect = $this->http('GET', 'http://127.0.0.1:' . $this->httpPort . '/edge/local/health', null, false);
            $this->assertSame(301, $redirect['status'], 'plain HTTP only redirects to HTTPS');
            $this->assertStringStartsWith('https://', (string) $redirect['location']);
            // Cashier login through the gateway (secure cookie), then the POS page and the ONE health page.
            $base = 'https://127.0.0.1:' . $this->httpsPort;
            $jar = $this->scratch . '\\cookies.txt';
            $login = $this->http('GET', $base . '/edge/local/login', null, true, $jar);
            $this->assertSame(200, $login['status']);
            $this->assertSame(1, preg_match('/name="_token" value="([^"]+)"/', $login['body'], $tok), 'login form carries the CSRF token');
            $post = $this->http('POST', $base . '/edge/local/login', ['_token' => $tok[1], 'employee_code' => $this->employeeCode, 'credential' => 'CashierPass1'], true, $jar, false);
            $this->assertSame(302, $post['status'], $post['body']);
            $this->assertStringContainsString('/edge/local/status', (string) $post['location']);
            $pos = $this->http('GET', $base . '/edge/local/pos', null, true, $jar);
            $this->assertSame(200, $pos['status'], substr($pos['body'], 0, 400));
            $this->assertStringContainsString('Cashier POS', $pos['body']);
            $this->assertStringContainsString('id="health-link"', $pos['body']);
            $page = $this->http('GET', $base . '/edge/local/pos/health', null, true, $jar);
            $this->assertSame(200, $page['status'], substr($page['body'], 0, 400));
            $this->assertStringContainsString('Branch Server status', $page['body']);
            $this->assertStringContainsString('supervised takeover only', $page['body']);
            $this->assertStringNotContainsString($this->recoveryKey, $page['body']);
            $this->stopProcess('nginx', [$this->nginx, '-p', $this->dataRoot . '\\gateway', '-c', $this->dataRoot . '\\gateway\\nginx.conf', '-s', 'quit']);
            $this->report['TLS_GATEWAY'] = 'nginx :' . $this->httpsPort . ' → backend; cashier login + POS + health page over HTTPS; :' . $this->httpPort . ' redirects';
        } else {
            $this->report['TLS_GATEWAY'] = 'not exercised (no nginx binary on this box)';
        }
        $this->stopProcess('web1');

        // 8. Backup (a pending outbox event planted so its preservation is provable), then RESTORE into a FRESH database B.
        $pdo->exec("insert into edge_sync_outbox (sale_uuid, envelope_schema_version, config_revision, activation_epoch, envelope, content_hash, state, created_at, updated_at) values ('" . Str::ulid() . "', 'edge-sale', 1, 1, '{}', '" . str_repeat('a', 64) . "', 'pending', now(), now())");
        [$code, $out] = $this->edge(['edge:local:backup', '--json', '--no-interaction']);
        $this->assertSame(0, $code, $out);
        $backup = json_decode(substr($out, (int) strpos($out, '{')), true);
        $backupPath = (string) ($backup['path'] ?? '');
        $this->assertFileExists($backupPath);
        $this->assertStringStartsWith(strtolower($this->dataRoot . '\\backups'), strtolower($backupPath));
        $this->report['BACKUP'] = 'encrypted backup ' . basename($backupPath) . ' under <DataRoot>\\backups';
        $dbB = ['EDGE_DB_DATABASE' => $this->installDb2];
        [$code, $out] = $this->edge(['edge:local:db-init', '--no-interaction'], $dbB);
        $this->assertSame(0, $code, $out);
        // The recovery contract: the Cloud config (products, users, printers …) is re-pulled with the SAME device identity
        // BEFORE the local state is restored — the restore precheck refuses dangling references otherwise.
        [$code, $out] = $this->edge(['edge:local:bootstrap-pull', '--cloud-url=http://127.0.0.1:' . $this->cloudPort, '--no-interaction'], $dbB);
        $this->assertSame(0, $code, 'config bootstrap on the fresh machine: ' . $out);
        [$code, $out] = $this->edge(['edge:local:restore', $backupPath, '--branch=999', '--no-interaction'], $dbB);
        $this->assertSame(1, $code, 'a backup of another branch must be refused');
        $this->assertStringContainsString('RESTORE_WRONG_IDENTITY', $out);
        [$code, $out] = $this->edge(['edge:local:restore', $backupPath, '--branch=' . $this->branchId, '--no-interaction'], $dbB);
        $this->assertSame(0, $code, $out);
        $pdoB = $this->pdo($this->installDb2);
        $this->assertSame('bootstrapped', (string) $pdoB->query('select runtime_state from edge_local_meta where singleton_guard = 1')->fetchColumn());
        $this->assertSame($device->public_uuid, (string) $pdoB->query('select device_uuid from edge_local_meta where singleton_guard = 1')->fetchColumn());
        $this->assertSame(1, (int) $pdoB->query('select count(*) from edge_local_user_credentials where status = \'active\'')->fetchColumn(), 'local users restored');
        $this->assertSame(1, (int) $pdoB->query("select count(*) from edge_sync_outbox where state = 'pending'")->fetchColumn(), 'the pending event survives into the fresh machine');
        $this->report['FRESH_MACHINE_RESTORE'] = 'DB-A → DB-B with the recovery key; wrong branch refused; pending event preserved';

        // 9. SIGNED UPDATE to 0.2.0-edge: a tampered package is refused first; then the real update switches the pointer.
        $tamperFile = $this->pkgB . '\\app\\routes\\edge_runtime.php';
        $original = (string) file_get_contents($tamperFile);
        file_put_contents($tamperFile, $original . "\n// tampered\n");
        [$code, $out] = $this->powershell($this->pkgB . '\\scripts\\Update-EdgeAppliance.ps1', ['-InstallRoot', $this->installRoot, '-PackageRoot', $this->pkgB, '-NoServices']);
        $this->assertSame(1, $code, 'a tampered package must be refused: ' . $out);
        $this->assertStringContainsString('TAMPERED', $out);
        $this->assertSame('0.1.0-edge', trim((string) file_get_contents($this->installRoot . '\\runtime\\current')), 'the runtime pointer never moved');
        file_put_contents($tamperFile, $original);
        $backupsBefore = (int) $pdo->query('select count(*) from edge_local_backups')->fetchColumn();
        [$code, $out] = $this->powershell($this->pkgB . '\\scripts\\Update-EdgeAppliance.ps1', ['-InstallRoot', $this->installRoot, '-PackageRoot', $this->pkgB, '-NoServices']);
        $this->assertSame(0, $code, "Update-EdgeAppliance.ps1 failed:\n" . $out . "\n" . $this->applianceLogTail());
        $this->assertStringContainsString('UPDATE COMPLETE', $out);
        $this->assertSame('0.2.0-edge', trim((string) file_get_contents($this->installRoot . '\\runtime\\current')));
        $this->assertFileExists($this->installRoot . '\\runtime\\versions\\0.2.0-edge\\artisan');
        $this->assertFileExists($this->installRoot . '\\runtime\\versions\\0.1.0-edge\\artisan', 'the previous runtime stays for rollback');
        $this->assertSame($backupsBefore + 1, (int) $pdo->query('select count(*) from edge_local_backups')->fetchColumn(), 'a pre-update backup was taken');
        $this->assertSame('applied', (string) $pdo->query('select result from edge_local_updates order by id desc limit 1')->fetchColumn());
        $this->assertSame(1, (int) $pdo->query("select count(*) from edge_sync_outbox where state = 'pending'")->fetchColumn(), 'the outbox survives the update');
        [$code, $out] = $this->edge(['edge:local:health', '--json', '--no-interaction']);
        $this->assertSame(0, $code, $out);
        $health2 = json_decode(substr($out, (int) strpos($out, '{')), true);
        $this->assertSame('0.2.0-edge', $health2['update']['active_version_pointer']);
        $this->assertSame('0.2.0-edge', $health2['runtime']['edge_app_version'], 'the launcher now boots the 0.2.0 runtime, which knows its own version');
        $this->assertTrue($health2['binding']['bound'], 'binding preserved across the update');
        $this->report['SIGNED_UPDATE'] = '0.1.0-edge → 0.2.0-edge via Update-EdgeAppliance.ps1 (pre-update backup, pointer switch, outbox kept)';
        $this->report['TAMPERED_UPDATE_REFUSED'] = 'yes (package hash mismatch, pointer unchanged)';

        // 10. UNINSTALL: safe by default (runtime only) … data removal refuses while an event is unsynced … then explicit.
        $this->startEdge(['edge:local:serve', '--worker=1', '--no-interaction'], 'web2');   // an updated runtime still serves
        $this->waitPort($this->webPort, 30, 'updated web backend');
        $this->assertSame(200, $this->http('GET', 'http://127.0.0.1:' . $this->webPort . '/edge/local/health')['status']);
        $this->stopProcess('web2');
        [$code, $out] = $this->powershell($this->installRoot . '\\scripts\\Uninstall-EdgeAppliance.ps1', ['-InstallRoot', $this->installRoot, '-DropDatabase', '-NoServices']);
        $this->assertSame(1, $code, 'data removal without the typed phrase must refuse: ' . $out);
        $this->assertFileExists($this->installRoot . '\\artisan', 'nothing removed');
        [$code, $out] = $this->powershell($this->installRoot . '\\scripts\\Uninstall-EdgeAppliance.ps1', ['-InstallRoot', $this->installRoot, '-DropDatabase', '-ConfirmPhrase', 'REMOVE ALL BRANCH DATA', '-NoServices']);
        $this->assertSame(1, $code, 'dropping the database while an event is unsynced must refuse: ' . $out);
        $this->assertStringContainsString('unsynced', $out);
        $this->assertFileExists($this->installRoot . '\\artisan', 'the runtime stays when the guard refuses');
        $this->assertSame(1, (int) $this->pdo($this->installDb)->query("select count(*) from edge_sync_outbox where state = 'pending'")->fetchColumn());
        [$code, $out] = $this->powershell($this->installRoot . '\\scripts\\Uninstall-EdgeAppliance.ps1', ['-InstallRoot', $this->installRoot, '-NoServices']);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('PRESERVED', $out);
        $this->assertDirectoryDoesNotExist($this->installRoot);
        $this->assertFileExists($this->dataRoot . '\\config\\appliance.env', 'the configuration (device identity, recovery key reference) is preserved');
        $this->assertFileExists($backupPath, 'backups are preserved');
        $this->assertSame('bootstrapped', (string) $this->pdo($this->installDb)->query('select runtime_state from edge_local_meta')->fetchColumn(), 'the local database is preserved');
        $this->assertFileExists(base_path('vendor/autoload.php'), 'removing the junctioned runtime never touched the shared vendor closure');
        $this->report['SAFE_UPGRADE'] = 'upgrade preserved config/DB/outbox; uninstall preserved DB/outbox/backups/config by default';
        $this->report['CLEAN_MACHINE_INSTALL'] = 'yes — fresh install root + data root + fresh DB from the package, real Cloud over HTTP, launcher-driven commands (services/reboot: physical certification)';
    }

    // ── process helpers ─────────────────────────────────────────────────────

    private function startCloud(): void
    {
        $m = config('database.connections.master');
        $t = config('database.connections.tenant');
        $env = array_merge(getenv() ?: [], [
            'APP_ROLE' => 'cloud', 'APP_ENV' => 'testing', 'APP_DEBUG' => 'true', 'APP_KEY' => (string) config('app.key'), 'APP_URL' => 'http://127.0.0.1:' . $this->cloudPort,
            'CENTRAL_DOMAIN' => '127.0.0.1', 'TENANT_BASE_DOMAIN' => '127.0.0.1',
            'DB_CONNECTION' => 'master', 'DB_HOST' => (string) $m['host'], 'DB_PORT' => (string) $m['port'], 'DB_DATABASE' => (string) $m['database'], 'DB_USERNAME' => (string) $m['username'], 'DB_PASSWORD' => (string) ($m['password'] ?? ''),
            'TENANT_DB_HOST' => (string) $t['host'], 'TENANT_DB_PORT' => (string) $t['port'], 'TENANT_DB_USERNAME' => (string) $t['username'], 'TENANT_DB_PASSWORD' => (string) ($t['password'] ?? ''),
            'EDGE_FEATURE_ENABLED' => 'true', 'EDGE_TESTING_ASSUME_ENTITLED' => 'true',
            'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'LOG_CHANNEL' => 'stderr',
            'PULSE_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false',
        ]);
        unset($env['BINGOO_EDGE_ENV_DIR'], $env['EDGE_LOCAL_APP_KEY'], $env['EDGE_DB_DATABASE']);
        $this->startProcess([PHP_BINARY, '-S', '127.0.0.1:' . $this->cloudPort, '-t', public_path(), public_path('index.php')], base_path(), 'cloud', $env);
        $this->waitPort($this->cloudPort, 30, 'Cloud php -S');
        $up = $this->http('GET', 'http://127.0.0.1:' . $this->cloudPort . '/up');
        $this->assertSame(200, $up['status'], 'the Cloud process must answer /up: ' . substr($up['body'], 0, 300));
    }

    private function startProcess(array $cmd, string $cwd, string $name, ?array $env = null): void
    {
        $log = $this->scratch . '\\proc-' . $name . '.log';
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $cwd, $env ?? (getenv() ?: []));
        $this->assertIsResource($proc, "could not start {$name}");
        fclose($pipes[0]);
        $status = proc_get_status($proc);
        $this->procs[] = ['proc' => $proc, 'pid' => (int) $status['pid'], 'name' => $name];
    }

    private function startEdge(array $args, string $name): void
    {
        $this->startProcess(array_merge([PHP_BINARY, $this->installRoot . '\\artisan'], $args), $this->installRoot, $name, $this->applianceEnv());
    }

    private function stopProcess(string $name, ?array $gracefulCmd = null): void
    {
        if ($gracefulCmd !== null) {
            @exec(implode(' ', array_map('escapeshellarg', $gracefulCmd)) . ' 2>&1');
            usleep(800000);
        }
        foreach ($this->procs as $i => $p) {
            if ($p['name'] === $name) {
                $this->killTree($p['pid']);
                @proc_close($p['proc']);
                unset($this->procs[$i]);
            }
        }
        $this->procs = array_values($this->procs);
    }

    private function killTree(int $pid): void
    {
        if ($pid > 0) {
            @exec('taskkill /PID ' . $pid . ' /T /F >nul 2>&1');
        }
    }

    /** Run one appliance command through the INSTALLED launcher (exactly what the installer / tasks / operator run). */
    private function edge(array $args, array $extraEnv = []): array
    {
        $proc = proc_open(array_merge([PHP_BINARY, $this->installRoot . '\\artisan'], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->installRoot, array_merge($this->applianceEnv(), $extraEnv));
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        return [$code, $out . ($err !== '' ? "\nSTDERR: " . $err : '')];
    }

    /** The appliance's process environment: NOTHING from the dev tree — the launcher supplies appliance.env. */
    private function applianceEnv(): array
    {
        $env = getenv() ?: [];
        foreach (array_keys($env) as $k) {
            if (str_starts_with($k, 'APP_') || str_starts_with($k, 'DB_') || str_starts_with($k, 'EDGE_') || str_starts_with($k, 'TENANT_') || in_array($k, ['SESSION_DRIVER', 'CACHE_STORE', 'QUEUE_CONNECTION', 'LOG_CHANNEL', 'MAIL_MAILER', 'CENTRAL_DOMAIN', 'BINGOO_EDGE_ENV_DIR'], true)) {
                unset($env[$k]);
            }
        }

        return $env;
    }

    private function powershell(string $script, array $args): array
    {
        $cmd = array_merge(['powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', $script], $args);
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->scratch, $this->applianceEnv());
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        return [$code, $out . ($err !== '' ? "\nSTDERR: " . $err : '')];
    }

    /** Poll a background process log for a marker (bounded). */
    private function waitForLog(string $name, string $needle, int $seconds, string $what): void
    {
        $log = $this->scratch . '\\proc-' . $name . '.log';
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            if (is_file($log) && str_contains((string) file_get_contents($log), $needle)) {
                return;
            }
            foreach ($this->procs as $p) {
                if ($p['name'] === $name && ! proc_get_status($p['proc'])['running']) {
                    $this->fail("{$name} exited before {$what}:\n" . (string) @file_get_contents($log) . $this->applianceLogTail());
                }
            }
            sleep(2);
        }
        $this->fail("timed out waiting for {$what}\n" . (string) @file_get_contents($log) . $this->applianceLogTail());
    }

    /** Wait for a background process to exit (bounded); returns [exit code, its log]. */
    private function waitProcess(string $name, int $seconds): array
    {
        $deadline = microtime(true) + $seconds;
        foreach ($this->procs as $i => $p) {
            if ($p['name'] !== $name) {
                continue;
            }
            $code = null;
            while (microtime(true) < $deadline) {
                $status = proc_get_status($p['proc']);
                if (! $status['running']) {
                    $code = (int) $status['exitcode'];
                    break;
                }
                sleep(2);
            }
            if ($code === null) {
                $this->killTree($p['pid']);
                $code = -1;
            }
            @proc_close($p['proc']);
            unset($this->procs[$i]);
            $this->procs = array_values($this->procs);

            return [$code, (string) @file_get_contents($this->scratch . '\\proc-' . $name . '.log')];
        }

        return [-1, 'no such process ' . $name];
    }

    private function waitPort(int $port, int $seconds, string $what): void
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.5);
            if (is_resource($fp)) {
                fclose($fp);

                return;
            }
            usleep(250000);
        }
        $this->fail("{$what} did not listen on 127.0.0.1:{$port} within {$seconds}s\n" . $this->processLogs());
    }

    /** @return array{status:int, body:string, location:?string} */
    private function http(string $method, string $url, ?array $form = null, bool $follow = false, ?string $jar = null, bool $followRedirects = false): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_FOLLOWLOCATION => $followRedirects,
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/json'],
        ]);
        if ($jar !== null) {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
        }
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form ?? []));
        }
        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $error = curl_error($ch);
        curl_close($ch);
        $headers = substr($raw, 0, $headerSize);
        $location = preg_match('/^Location:\s*(.+)$/mi', $headers, $m) ? trim($m[1]) : null;

        return ['status' => $status, 'body' => substr($raw, $headerSize) . ($error !== '' ? "\nCURL: " . $error : ''), 'location' => $location];
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

    private function cleanupMasterEdgeRows(): void
    {
        try {
            $m = DB::connection('master');
            $snapshots = $m->table('edge_bootstrap_snapshots')->where('tenant_id', $this->cloudTenantId)->pluck('id')->all();
            if ($snapshots !== []) {
                $m->table('edge_bootstrap_snapshot_sections')->whereIn('snapshot_id', $snapshots)->delete();
            }
            foreach (['edge_bootstrap_snapshots', 'edge_devices', 'edge_pairing_codes', 'edge_branch_activations', 'edge_branch_config_revisions', 'edge_reconciliation_markers'] as $t) {
                if ($m->getSchemaBuilder()->hasTable($t)) {
                    $m->table($t)->where('tenant_id', $this->cloudTenantId)->delete();
                }
            }
        } catch (\Throwable) {
        }
    }

    private function applianceLogTail(): string
    {
        $out = '';
        foreach (array_merge(glob($this->dataRoot . '\\logs\\*.log') ?: [], glob($this->dataRoot . '\\gateway\\logs\\*.log') ?: []) as $f) {
            $out .= "\n--- " . basename($f) . "\n" . substr((string) file_get_contents($f), -3000);
        }

        return $out . $this->processLogs();
    }

    private function processLogs(): string
    {
        $out = '';
        foreach (glob($this->scratch . '\\proc-*.log') ?: [] as $f) {
            $out .= "\n--- " . basename($f) . "\n" . substr((string) file_get_contents($f), -2000);
        }

        return $out;
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        // Junctions (the shared vendor) are removed as links, never followed.
        @exec('cmd /c for /d /r "' . $dir . '" %d in (vendor) do @if exist "%d\\" (fsutil reparsepoint query "%d" >nul 2>&1 && rmdir "%d")');
        @exec('cmd /c rmdir /s /q "' . $dir . '" >nul 2>&1');
    }
}
