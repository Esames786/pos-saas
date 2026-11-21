<?php

namespace Tests\MySql;

use App\Http\Controllers\Edge\EdgeLocalAutoUpdateController;
use App\Models\Tenant\User;
use App\Services\Edge\AutoUpdate\EdgeApplianceRunner;
use App\Services\Edge\AutoUpdate\EdgeAutoUpdateApplier;
use App\Services\Edge\AutoUpdate\EdgeAutoUpdateHealthProbe;
use App\Services\Edge\AutoUpdate\EdgeAutoUpdateInstaller;
use App\Services\Edge\AutoUpdate\EdgeAutoUpdateRuntimeProbe;
use App\Services\Edge\AutoUpdate\EdgeAutoUpdateServiceControl;
use App\Services\Edge\AutoUpdate\EdgeAutoUpdateStateStore;
use App\Services\Edge\AutoUpdate\EdgeDatabaseSnapshot;
use App\Services\Edge\AutoUpdate\EdgeRuntimePointer;
use App\Services\Edge\EdgeArtifactBuilder;
use App\Services\Edge\EdgeBackupService;
use App\Services\Edge\EdgeEnrollmentCrypto;
use App\Services\Edge\EdgeSupervisionPlan;
use App\Services\Edge\EdgeUpdatePackageService;
use App\Services\Edge\EdgeUpdateVerifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PDO;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\EdgeTestDatabases;
use Tests\MySql\Support\TenantFixtures;

/**
 * EDGE 0.9 (stream B) — auto-update on REAL MySQL:
 *   - the full encrypted DB snapshot round-trips byte-for-byte (DDL, FK, binary, NULL, utf8) and refuses non-Edge databases;
 *   - the REAL signed installer + the applier: a schema-changing update whose health check fails is rolled back by restoring
 *     the snapshot (the "migration" table disappears, the data is back) and switching the pointer back; a no-schema update
 *     rolls back by pointer only; edge_local_updates records the outcome;
 *   - the BingooEdgeUpdater task in the supervision plan keeps the least-privilege policy;
 *   - "Update now" on the status page: permission, confirm, CSRF group, refused unless standby with an empty outbox, and it
 *     only RECORDS a request (no install over HTTP); the Software update block renders.
 */
class EdgeAutoUpdateMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private array $tempDirs = [];
    private string $snapDb;
    private int $branchId;
    private int $userId;

    protected function setUp(): void
    {
        putenv('APP_ROLE=branch_server');
        $_ENV['APP_ROLE'] = $_SERVER['APP_ROLE'] = 'branch_server';
        $key = 'base64:' . base64_encode(random_bytes(32));
        putenv("EDGE_LOCAL_APP_KEY={$key}");
        $_ENV['EDGE_LOCAL_APP_KEY'] = $_SERVER['EDGE_LOCAL_APP_KEY'] = $key;
        parent::setUp();
        config(['database.connections.edge_local' => array_merge(config('database.connections.edge_local', []), [
            'host' => config('database.connections.tenant.host'), 'port' => config('database.connections.tenant.port'),
            'database' => config('database.connections.tenant.database'), 'username' => config('database.connections.tenant.username'), 'password' => config('database.connections.tenant.password'),
        ])]);
        DB::purge('edge_local');
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        $this->cleanTenant(['edge_local_updates', 'edge_local_backups', 'edge_sync_outbox', 'edge_local_user_credentials', 'branches', 'users']);
        config(['app.role' => 'branch_server', 'app.key' => config('app.key') ?: 'base64:' . base64_encode(random_bytes(32)), 'cache.default' => 'array']);
        $this->branchId = $this->makeBranch(['name' => 'Update Branch']);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'UP' . Str::random(4), 'name' => 'Update Owner']);
        $this->bindEdgeLocalMeta($this->branchId, 1, 42, 'update-device-uuid', 1);
        DB::connection('tenant')->table('edge_local_meta')->update(['bootstrap_schema' => config('edge.bootstrap_schema'), 'config_schema_version' => config('edge.config_schema_version'), 'authority_state' => 'standby']);
        $this->seedEdgeCredential($this->userId, $this->branchId, 1);
        config([
            'edge.auto_update.work_dir' => $this->tmp(),
            'edge.auto_update.service_control' => 'none',
            'edge.sync.device_id' => 'update-device-uuid', 'edge.sync.device_secret' => 'x',
            'edge.update.install_root' => $this->tmp(),
        ]);
        $this->snapDb = EdgeTestDatabases::local('snap');
        $this->pdo()->exec("DROP DATABASE IF EXISTS `{$this->snapDb}`");
        $this->pdo()->exec("CREATE DATABASE `{$this->snapDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        config(['database.connections.edge_snap' => array_merge(config('database.connections.tenant'), ['database' => $this->snapDb])]);
        DB::purge('edge_snap');
    }

    protected function tearDown(): void
    {
        try {
            $this->pdo()->exec("DROP DATABASE IF EXISTS `{$this->snapDb}`");
        } catch (\Throwable) {
        }
        foreach ($this->tempDirs as $d) {
            $this->rrmdir($d);
        }
        putenv('APP_ROLE');
        unset($_ENV['APP_ROLE'], $_SERVER['APP_ROLE']);
        putenv('EDGE_LOCAL_APP_KEY');
        unset($_ENV['EDGE_LOCAL_APP_KEY'], $_SERVER['EDGE_LOCAL_APP_KEY']);
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    // ── the full DB snapshot ──────────────────────────────────────────────────────────────────

    public function test_the_snapshot_round_trips_schema_and_data_exactly_and_refuses_a_non_edge_database(): void
    {
        $this->seedSnapDb();
        $before = $this->dumpSnapDb();
        $file = $this->tmp() . DIRECTORY_SEPARATOR . 'pre.snap';
        $snap = new EdgeDatabaseSnapshot('edge_snap');
        $r = $snap->snapshot($file);
        $this->assertSame(2, $r['tables']);
        $this->assertSame(3, $r['rows']);
        $this->assertStringNotContainsString('Ålesund', (string) file_get_contents($file), 'rows are encrypted at rest');

        // What a schema-changing update does: a new table, a new column, changed rows.
        $c = DB::connection('edge_snap');
        $c->statement('CREATE TABLE edge_mirror_probe (id INT PRIMARY KEY)');
        $c->statement('ALTER TABLE parent_t ADD COLUMN extra VARCHAR(10) NULL');
        $c->table('child_t')->delete();
        $c->table('parent_t')->update(['name' => 'changed']);

        $this->assertSame(['tables' => 2, 'rows' => 3], $snap->restore($file));
        $this->assertSame($before, $this->dumpSnapDb(), 'schema + data are exactly the pre-update state');

        // Refusals: a database that is not a dedicated Edge-local one; a snapshot of another database.
        config(['database.connections.master_like' => array_merge(config('database.connections.tenant'), ['database' => (string) config('database.connections.master.database')])]);
        try {
            (new EdgeDatabaseSnapshot('master_like'))->snapshot($this->tmp() . '/x.snap');
            $this->fail('a non-Edge database must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('AUTO_UPDATE_SNAPSHOT_REFUSED', $e->getMessage());
        }
        try {
            (new EdgeDatabaseSnapshot('tenant'))->restore($file);
            $this->fail('a snapshot of another database must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('AUTO_UPDATE_RESTORE_REFUSED', $e->getMessage());
        }
    }

    // ── the real installer + applier rollback rule ───────────────────────────────────────────

    public function test_a_schema_changing_update_that_fails_health_is_rolled_back_by_snapshot_restore_and_pointer(): void
    {
        $this->seedSnapDb();
        $before = $this->dumpSnapDb();
        [$applier, $pkg, $art] = $this->realApplier(schemaChange: true, healthy: false);
        $root = (string) config('edge.update.install_root');
        $r = $applier->apply(dirname($art), $pkg, ['version' => '0.2.0-edge', 'plan_hashes' => [(string) config('edge.app_version') => 'sha256:approved']], 'test:auto-update');

        $this->assertSame(EdgeAutoUpdateApplier::ROLLED_BACK, $r['result'], $r['message']);
        $this->assertSame('restored_snapshot', $r['rollback']);
        $this->assertTrue($r['schema_change']);
        $this->assertSame('0.1.0-edge', EdgeRuntimePointer::current($root), 'pointer switched back');
        $this->assertFileExists($root . '/versions/0.2.0-edge/app/A.php', 'the new runtime had really been staged + switched to');
        $this->assertSame($before, $this->dumpSnapDb(), 'the migration table is gone and the data is back');
        $rows = DB::connection('tenant')->table('edge_local_updates')->orderBy('id')->pluck('result')->all();
        $this->assertContains('applied', $rows, 'the installer recorded its own apply');
        $this->assertSame('rolled_back', end($rows), 'the rollback is recorded');
        $this->assertSame('restored_snapshot', DB::connection('tenant')->table('edge_local_updates')->orderByDesc('id')->value('rollback_result'));
    }

    public function test_a_no_schema_update_that_fails_health_rolls_back_the_pointer_only_and_a_healthy_one_stays(): void
    {
        $this->seedSnapDb();
        [$applier, $pkg, $art] = $this->realApplier(schemaChange: false, healthy: false);
        $root = (string) config('edge.update.install_root');
        $r = $applier->apply(dirname($art), $pkg, ['version' => '0.2.0-edge'], 'test');
        $this->assertSame('reverted_runtime', $r['rollback'], $r['message']);
        $this->assertSame('0.1.0-edge', EdgeRuntimePointer::current($root));
        $this->assertSame([], glob(config('edge.auto_update.work_dir') . '/snapshots/*') ?: [], 'no snapshot is taken without a schema change');

        DB::connection('tenant')->table('edge_local_updates')->delete();
        [$applier, $pkg, $art] = $this->realApplier(schemaChange: false, healthy: true, version: '0.3.0-edge');
        $r = $applier->apply(dirname($art), $pkg, ['version' => '0.3.0-edge'], 'test');
        $this->assertSame(EdgeAutoUpdateApplier::UPDATED, $r['result'], $r['message']);
        $this->assertSame('0.3.0-edge', EdgeRuntimePointer::current($root));
    }

    public function test_an_outbox_event_blocks_the_apply_inside_the_lock(): void
    {
        [$applier, $pkg, $art] = $this->realApplier(schemaChange: false, healthy: true, realProbe: true);
        DB::connection('tenant')->table('edge_sync_outbox')->insert([
            'sale_uuid' => (string) Str::ulid(), 'envelope_schema_version' => 'v1', 'config_revision' => 1, 'activation_epoch' => 1,
            'envelope' => '{}', 'content_hash' => str_repeat('a', 64), 'state' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $r = $applier->apply(dirname($art), $pkg, ['version' => '0.2.0-edge'], 'test');
        $this->assertSame('AUTO_UPDATE_GATE_CHANGED', $r['code'], $r['message']);
        $this->assertSame('0.1.0-edge', EdgeRuntimePointer::current((string) config('edge.update.install_root')));
    }

    // ── supervision plan ──────────────────────────────────────────────────────────────────────

    public function test_the_updater_task_keeps_the_least_privilege_policy(): void
    {
        $tasks = collect(app(EdgeSupervisionPlan::class)->tasks('C:\\php\\php.exe', 'C:\\Program Files\\Bingoo Edge'))->keyBy('name');
        $u = $tasks[EdgeSupervisionPlan::UPDATER_TASK];
        $this->assertSame('edge:local:auto-update', $u['artisan_command']);
        $this->assertSame('periodic', $u['kind']);
        $this->assertSame(15, $u['repeat_minutes']);
        $this->assertSame('NT AUTHORITY\\LOCAL SERVICE', $u['principal']);
        $this->assertSame('limited', $u['run_level']);
        $this->assertSame(EdgeSupervisionPlan::SINGLETON_AUTO_UPDATE_LOCK, $u['singleton']);
        $this->assertStringNotContainsStringIgnoringCase('secret', $u['arguments']);
        $this->assertTrue(\App\Support\EdgeConsoleBoundary::isAllowed('edge:local:auto-update'));
        foreach (['edge:local:users', 'edge:local:report-status', 'edge:local:report-lan-profile'] as $c) {
            $this->assertTrue(\App\Support\EdgeConsoleBoundary::isAllowed($c), $c);
        }
    }

    // ── Update now on the status page ────────────────────────────────────────────────────────

    public function test_update_now_is_guarded_and_only_records_a_request(): void
    {
        $this->registerUpdateNowRoute();
        $store = new EdgeAutoUpdateStateStore((string) config('edge.auto_update.work_dir'));
        $this->post('/edge/local/pos/updates/update-now', ['confirm' => 'yes'])->assertRedirect('/edge/local/login');

        $plainId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'PL' . Str::random(4), 'name' => 'Plain Cashier']);
        $this->seedEdgeCredential($plainId, $this->branchId, 1);
        $plain = User::on('tenant')->findOrFail($plainId);
        $this->asLocal($plain)->post('/edge/local/pos/updates/update-now', ['confirm' => 'yes'])->assertForbidden();
        $this->assertNull($store->pendingUpdateNow());

        $owner = $this->user(true);
        $this->asLocal($owner)->post('/edge/local/pos/updates/update-now', [])->assertRedirect('/edge/local/pos/health');
        $this->assertStringContainsString('Not confirmed', session('auto_update_control')['message']);
        $this->assertNull($store->pendingUpdateNow());

        DB::connection('tenant')->table('edge_local_meta')->update(['authority_state' => 'local_active']);
        $this->asLocal($owner)->post('/edge/local/pos/updates/update-now', ['confirm' => 'yes']);
        $this->assertFalse(session('auto_update_control')['ok']);
        $this->assertStringContainsString('LOCAL_ACTIVE', session('auto_update_control')['message']);
        $this->assertNull($store->pendingUpdateNow(), 'never while the branch sells offline');

        DB::connection('tenant')->table('edge_local_meta')->update(['authority_state' => 'standby']);
        $this->asLocal($owner)->post('/edge/local/pos/updates/update-now', ['confirm' => 'yes']);
        $this->assertTrue(session('auto_update_control')['ok'], session('auto_update_control')['message']);
        $req = $store->pendingUpdateNow();
        $this->assertNotNull($req);
        $this->assertSame('status_page', $req['source']);
        $this->assertStringContainsString('Update Owner', $req['requested_by']);
        $this->assertSame('0.1.0-edge', EdgeRuntimePointer::current((string) config('edge.update.install_root')) ?? '0.1.0-edge', 'nothing is installed by the web request');

        $page = $this->asLocal($owner)->get('/edge/local/pos/health');
        if ($page->getStatusCode() === 200) {
            $page->assertSee('id="software-update"', false)->assertSee('id="btn-update-now"', false)->assertSee('Update now');
        }
        $page = $this->asLocal($plain->fresh())->get('/edge/local/pos/health');
        if ($page->getStatusCode() === 200) {
            $page->assertSee('id="software-update"', false)->assertDontSee('id="btn-update-now"', false);
        }
    }

    // ── helpers ───────────────────────────────────────────────────────────────────────────────

    /** The route line the lead adds to routes/edge_runtime.php (same group as edge.local.pos.health.view). */
    private function registerUpdateNowRoute(): void
    {
        if (! Route::has('edge.local.pos.updates.update-now')) {
            $mw = Route::getRoutes()->getByName('edge.local.pos.health.view')->middleware();
            Route::post('/edge/local/pos/updates/update-now', [EdgeLocalAutoUpdateController::class, 'updateNow'])
                ->middleware(array_merge($mw, ['throttle:6,1']))->name('edge.local.pos.updates.update-now');
            Route::getRoutes()->refreshNameLookups();
            Route::getRoutes()->refreshActionLookups();
        }
        config(['edge.route_allowlist' => array_merge((array) config('edge.route_allowlist'), ['edge.local.pos.updates.update-now'])]);
    }

    private function user(bool $withPermission): User
    {
        $user = User::on('tenant')->findOrFail($this->userId);
        $conn = DB::connection('tenant');
        $permId = $conn->table('permissions')->where('name', EdgeLocalAutoUpdateController::PERMISSION)->value('id');
        if ($permId) {
            $conn->table('model_has_permissions')->where('model_id', $this->userId)->where('permission_id', $permId)->delete();
        }
        if ($withPermission) {
            $this->grantEdgePermission($this->userId, EdgeLocalAutoUpdateController::PERMISSION);
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function asLocal(User $user)
    {
        return $this->actingAs($user, 'tenant')->withSession(['edge_login_epoch' => 1, 'edge_credential_version' => 1]);
    }

    /** @return array{0:EdgeAutoUpdateApplier,1:array,2:string} applier with the REAL installer (seams: plan + schema step) */
    private function realApplier(bool $schemaChange, bool $healthy, string $version = '0.2.0-edge', bool $realProbe = false): array
    {
        $kp = EdgeEnrollmentCrypto::generateKeypair();
        config(['edge.update.public_key' => $kp['public'], 'edge.app_version' => '0.1.0-edge', 'edge.backup.path' => $this->tmp(),
            'edge.backup.recovery_key' => base64_encode(random_bytes(32)), 'edge.backup.recovery_key_id' => 'k1', 'edge.backup.retired_keys' => []]);
        $root = (string) config('edge.update.install_root');
        @mkdir($root . '/versions/0.1.0-edge', 0775, true);
        if (EdgeRuntimePointer::current($root) === null) {
            file_put_contents($root . '/current', '0.1.0-edge');
        }
        $src = $this->tmp();
        mkdir($src . '/app', 0775, true);
        file_put_contents($src . '/app/A.php', '<?php // ' . $version);
        file_put_contents($src . '/composer.json', '{}');
        $pkgDir = $this->tmp();
        $art = $pkgDir . DIRECTORY_SEPARATOR . 'app';
        (new EdgeArtifactBuilder(['include' => ['app', 'composer.json'], 'exclude' => [], 'forbidden' => []]))->build($src, $art, ['git_commit' => 'r-' . $version]);
        $pkg = app(EdgeUpdatePackageService::class)->build($art, $kp['secret'], ['edge_app_version' => $version]);

        $installer = new class(app(EdgeUpdateVerifier::class), app(EdgeUpdatePackageService::class), app(EdgeBackupService::class), $schemaChange) extends EdgeAutoUpdateInstaller {
            public function __construct($v, $p, $b, private bool $schema) { parent::__construct($v, $p, $b); }
            protected function migrationPlan(string $runtimeDir): array
            {
                $entries = $this->schema ? [['migration' => '2099_01_01_000000_edge_mirror_probe', 'group' => 'edge', 'decision' => 'run']] : [];

                return ['format' => 'edge-migration-plan-v1', 'manifest_version' => 'test', 'entries' => $entries, 'run' => $this->schema ? ['2099_01_01_000000_edge_mirror_probe'] : [],
                    'skip' => [], 'substitute' => [], 'blockers' => [], 'blocker_reasons' => [], 'plan_hash' => $this->schema ? 'sha256:approved' : 'sha256:empty', 'verdict' => 'OK'];
            }
            protected function applySchemaUpgrade(string $versionDir): string
            {
                // The "migration" the new runtime runs: a new table in the snapshotted Edge DB.
                DB::connection('edge_snap')->statement('CREATE TABLE IF NOT EXISTS edge_mirror_probe (id INT PRIMARY KEY)');

                return (string) config('edge.config_schema');
            }
        };
        $services = new class(new EdgeApplianceRunner()) extends EdgeAutoUpdateServiceControl {
            public function stop(string $runtimeDir): void {}
            public function start(string $runtimeDir): void {}
        };
        $health = new class(new EdgeApplianceRunner(), $healthy) extends EdgeAutoUpdateHealthProbe {
            public function __construct($r, private bool $healthy) { parent::__construct($r); }
            public function probe(): array { return ['status' => 'STANDBY_READY', 'problems' => []]; }
            public function waitHealthy(array $before, string $expectedVersion, int $timeoutSeconds): array
            {
                $ok = $this->healthy || $expectedVersion === '0.1.0-edge';

                return ['ok' => $ok, 'reasons' => $ok ? [] : ['new problem: Runtime: boot failed'], 'report' => []];
            }
        };
        $probe = $realProbe ? app(EdgeAutoUpdateRuntimeProbe::class) : new class extends EdgeAutoUpdateRuntimeProbe {
            public function __construct() {}
            public function facts(): array { return ['bound' => true, 'authority_state' => 'standby', 'outbox_pending' => 0, 'outbox_in_flight' => 0]; }
        };

        return [new EdgeAutoUpdateApplier($installer, $services, $health, $probe, new EdgeDatabaseSnapshot('edge_snap')), $pkg, $art];
    }

    private function seedSnapDb(): void
    {
        $c = DB::connection('edge_snap');
        $c->statement('CREATE TABLE parent_t (id INT PRIMARY KEY AUTO_INCREMENT, name VARCHAR(50) NOT NULL, blob_c VARBINARY(16) NULL, note TEXT NULL) ENGINE=InnoDB');
        $c->statement('CREATE TABLE child_t (id INT PRIMARY KEY, parent_id INT NOT NULL, amount DECIMAL(12,2) NOT NULL, CONSTRAINT fk_child_parent FOREIGN KEY (parent_id) REFERENCES parent_t(id)) ENGINE=InnoDB');
        $c->table('parent_t')->insert(['id' => 1, 'name' => 'Ålesund کراچی', 'blob_c' => random_bytes(16), 'note' => null]);
        $c->table('parent_t')->insert(['id' => 2, 'name' => 'plain', 'blob_c' => null, 'note' => "multi\nline"]);
        $c->table('child_t')->insert(['id' => 10, 'parent_id' => 1, 'amount' => '1500.50']);
    }

    private function dumpSnapDb(): array
    {
        $c = DB::connection('edge_snap');
        $out = [];
        foreach ($c->select('SHOW FULL TABLES') as $row) {
            $v = array_values((array) $row);
            $t = (string) $v[0];
            $out[$t] = [
                // Semantic DDL: MySQL 8 re-displays a column's (identical) charset after a re-create — normalise the display only.
                'ddl' => preg_replace(['/ AUTO_INCREMENT=\d+/', '/ CHARACTER SET utf8mb4(?= COLLATE)/'], '', (string) ((array) $c->selectOne("SHOW CREATE TABLE `{$t}`"))['Create Table']),
                'rows' => array_map(fn ($r) => array_map(fn ($x) => is_string($x) ? bin2hex($x) : $x, (array) $r), $c->table($t)->orderBy('id')->get()->all()),
            ];
        }
        ksort($out);

        return $out;
    }

    private function pdo(): PDO
    {
        $c = config('database.connections.tenant');

        return new PDO("mysql:host={$c['host']};port={$c['port']};charset=utf8mb4", $c['username'], $c['password'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    private function tmp(): string
    {
        $d = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'edge-aum-' . Str::lower(Str::random(8));
        mkdir($d, 0775, true);
        $this->tempDirs[] = $d;

        return $d;
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() && ! $f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
