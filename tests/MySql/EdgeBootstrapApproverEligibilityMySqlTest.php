<?php

namespace Tests\MySql;

use App\Models\Edge\EdgeLocalMeta;
use App\Models\Master\Tenant;
use App\Models\Tenant\Branch;
use App\Models\Tenant\User;
use App\Services\Edge\EdgeBootstrapService;
use App\Services\Edge\EdgeLocalAuthService;
use App\Services\Edge\EdgeLocalBootstrapImporter;
use App\Services\Edge\EdgePairingService;
use App\Services\Edge\OfflineEdgeEntitlementService;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

/**
 * PHASE 3 SECURITY — the bootstrap / config-refresh side of the approver-eligibility contract (edge-bootstrap-v8),
 * proven with the REAL buildSections against a Cloud-source tenant DB and the REAL importer / refresh applier against a
 * fresh Edge-local DB (the EdgeLocalAuthMySqlTest pattern):
 *
 *   - users[].may_approve_pos is TRUE only for an ACTIVE user with an ACTIVE manager_pins row; a disabled PIN, a
 *     deactivated PIN holder and a user without a PIN export FALSE; no PIN hash ever ships;
 *   - the importer stores the flag per user (Edge-only users.may_approve_pos) and verifyManager honours it;
 *   - a manager PIN disabled on the Cloud moves the watermark (new config revision), and the next applied refresh
 *     revokes the offline approval right: verifyManager refuses; re-enabling it restores the right with the next revision.
 */
class EdgeBootstrapApproverEligibilityMySqlTest extends MySqlTenantTestCase
{
    private string $edgeDb;
    private int $branchId;
    private int $branchB;
    private int $mgr;
    private int $cashier;
    private int $disabledPinMgr;
    private int $inactiveMgr;
    private int $otherBranchMgr;
    private object $svc;
    private static bool $edgeReady = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->edgeDb = \Tests\MySql\Support\EdgeTestDatabases::local('approver');
        config(['app.role' => 'branch_server']);
        $this->svc = new class(app(OfflineEdgeEntitlementService::class), app(TenancyManager::class), app(EdgePairingService::class)) extends EdgeBootstrapService {
            public function sectionsFor(Tenant $t, Branch $b): array
            {
                return $this->buildSections($t, $b);
            }

            public function watermark(Branch $b): string
            {
                return $this->sourceRevision($b);
            }
        };
        $this->seedCloudSource();
    }

    protected function tearDown(): void
    {
        config(['database.connections.tenant.database' => $this->tenantDb]);
        DB::purge('tenant');
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        parent::tearDown();
    }

    // ── Cloud source ─────────────────────────────────────────────────────────────────────────────────────────

    private function seedCloudSource(): void
    {
        $this->cleanTenant(['manager_pins', 'model_has_permissions', 'model_has_roles', 'role_has_permissions', 'permissions', 'roles', 'branch_user', 'users', 'branches']);
        $c = DB::connection('tenant');
        $now = now();
        $this->branchId = $c->table('branches')->insertGetId(['name' => 'A', 'code' => 'A', 'status' => 'active', 'timezone' => 'Asia/Karachi', 'created_at' => $now, 'updated_at' => $now]);
        $this->branchB = $c->table('branches')->insertGetId(['name' => 'B', 'code' => 'B', 'status' => 'active', 'timezone' => 'Asia/Karachi', 'created_at' => $now, 'updated_at' => $now]);
        $user = fn (string $code, string $status, int $branch) => (int) $c->table('users')->insertGetId([
            'name' => $code, 'email' => strtolower($code) . '@x.test', 'password' => bcrypt('cloud-secret-unused'), 'employee_code' => $code,
            'status' => $status, 'default_branch_id' => $branch, 'locale' => 'en', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->mgr = $user('APMGR', 'active', $this->branchId);
        $this->cashier = $user('APCSH', 'active', $this->branchId);
        $this->disabledPinMgr = $user('APDIS', 'active', $this->branchId);
        $this->inactiveMgr = $user('APINA', 'inactive', $this->branchId);
        $this->otherBranchMgr = $user('APOTH', 'active', $this->branchB);
        $pin = fn (int $uid, int $active) => $c->table('manager_pins')->insert(['user_id' => $uid, 'pin_hash' => bcrypt('1234'), 'is_active' => $active, 'created_at' => $now, 'updated_at' => $now]);
        $pin($this->mgr, 1);
        $pin($this->disabledPinMgr, 0);
        $pin($this->inactiveMgr, 1);
        $pin($this->otherBranchMgr, 1);

        // the action permission every exported user holds (the approver must hold the ACTION's permission as well)
        $permId = $c->table('permissions')->insertGetId(['name' => 'tenant.pos.store', 'guard_name' => 'tenant', 'created_at' => $now, 'updated_at' => $now]);
        foreach ([$this->mgr, $this->cashier, $this->disabledPinMgr, $this->inactiveMgr, $this->otherBranchMgr] as $uid) {
            $c->table('model_has_permissions')->insert(['permission_id' => $permId, 'model_type' => User::class, 'model_id' => $uid]);
        }
    }

    private function package(int $revision = 1, string $snapshot = 'snap-1'): array
    {
        $tenant = new Tenant(['tenant_code' => 'approverdemo', 'business_name' => 'Demo', 'currency_code' => 'PKR']);
        $tenant->id = 42;
        $branch = Branch::on('tenant')->find($this->branchId);
        $sections = $this->svc->sectionsFor($tenant, $branch);
        $summary = [];
        foreach ($sections as $name => $rows) {
            $summary[$name] = ['hash' => hash('sha256', $this->svc->canonicalJson($rows)), 'count' => count($rows)];
        }
        $manifest = [
            'schema_version' => EdgeBootstrapService::SCHEMA_VERSION, 'snapshot_uuid' => $snapshot, 'tenant_code' => 'approverdemo', 'tenant_id' => 42,
            'branch_id' => $this->branchId, 'device_public_uuid' => 'device-A', 'activation_epoch' => 1, 'config_revision' => $revision,
            'config_schema_version' => EdgeBootstrapService::CONFIG_SCHEMA_VERSION, 'source_revision' => 'rev-' . $revision, 'sections' => $summary,
        ];
        $manifest['manifest_hash'] = $this->svc->computeManifestHash(EdgeBootstrapService::SCHEMA_VERSION, $snapshot, 42, $this->branchId, 'device-A', 1, $revision, EdgeBootstrapService::CONFIG_SCHEMA_VERSION, $summary);

        return ['manifest' => $manifest, 'sections' => $sections];
    }

    /** Run $mutations against the CLOUD source, build a package with the given revision, then point back at the Edge DB. */
    private function cloudPackage(int $revision, string $snapshot, ?callable $mutations = null): array
    {
        config(['database.connections.tenant.database' => $this->tenantDb]);
        DB::purge('tenant');
        if ($mutations) {
            $mutations(DB::connection('tenant'));
        }
        $package = $this->package($revision, $snapshot);
        $this->toEdgeDb(false);

        return $package;
    }

    private function cloudWatermark(): string
    {
        config(['database.connections.tenant.database' => $this->tenantDb]);
        DB::purge('tenant');
        $wm = $this->svc->watermark(Branch::on('tenant')->find($this->branchId));
        $this->toEdgeDb(false);

        return $wm;
    }

    // ── Edge-local DB ────────────────────────────────────────────────────────────────────────────────────────

    private function toEdgeDb(bool $clean = true): void
    {
        $c = config('database.connections.tenant');
        if (! self::$edgeReady) {
            $pdo = new PDO("mysql:host={$c['host']};port={$c['port']};charset=utf8mb4", $c['username'], $c['password'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec("DROP DATABASE IF EXISTS `{$this->edgeDb}`");
            $pdo->exec("CREATE DATABASE `{$this->edgeDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            config(['database.connections.tenant.database' => $this->edgeDb, 'database.connections.edge_local.database' => $this->edgeDb]);
            DB::purge('tenant');
            Artisan::call('migrate', ['--database' => 'tenant', '--path' => 'database/migrations/tenant', '--force' => true]);
            Artisan::call('migrate', ['--database' => 'tenant', '--path' => 'database/migrations/edge', '--force' => true]);
            self::$edgeReady = true;
        } else {
            config(['database.connections.tenant.database' => $this->edgeDb, 'database.connections.edge_local.database' => $this->edgeDb]);
            DB::purge('tenant');
        }
        if ($clean) {
            $this->cleanTenant([
                'edge_auth_audit', 'edge_local_user_credentials', 'edge_local_meta', 'manager_approvals', 'manager_pins',
                'model_has_permissions', 'model_has_roles', 'role_has_permissions', 'permissions', 'users', 'roles', 'terminals', 'branches',
            ]);
        }
        // the appliance runtime: the Edge-local DB IS the default connection (Spatie resolves there) and the master is dead
        DB::setDefaultConnection('tenant');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function importer(): EdgeLocalBootstrapImporter
    {
        return app(EdgeLocalBootstrapImporter::class);
    }

    private function credential(int $userId, string $password): void
    {
        DB::connection('tenant')->table('edge_local_user_credentials')->insert([
            'user_id' => $userId, 'branch_id' => $this->branchId, 'activation_epoch' => 1,
            'credential_hash' => password_hash($password, PASSWORD_ARGON2ID), 'credential_type' => 'password', 'credential_version' => 1,
            'status' => 'active', 'enrolled_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function flag(int $userId): int
    {
        return (int) DB::connection('tenant')->table('users')->where('id', $userId)->value('may_approve_pos');
    }

    // ── tests ────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_bootstrap_exports_the_flag_only_for_active_users_with_an_active_manager_pin_and_never_a_hash(): void
    {
        $this->assertSame('edge-bootstrap-v8', EdgeBootstrapService::SCHEMA_VERSION);
        $users = collect($this->package()['sections']['users'])->keyBy('id');

        $this->assertTrue($users[$this->mgr]['may_approve_pos'], 'active user + active PIN → eligible');
        $this->assertFalse($users[$this->cashier]['may_approve_pos'], 'no PIN → not eligible');
        $this->assertFalse($users[$this->disabledPinMgr]['may_approve_pos'], 'disabled PIN → not eligible');
        $this->assertFalse($users[$this->inactiveMgr]['may_approve_pos'], 'deactivated PIN holder → not eligible');
        $this->assertArrayNotHasKey($this->otherBranchMgr, $users->all(), "another branch's manager is not exported at all");
        foreach ($users as $row) {
            $this->assertIsBool($row['may_approve_pos']);
            foreach (['pin', 'pin_hash', 'manager_pin', 'password'] as $secret) {
                $this->assertArrayNotHasKey($secret, $row, "no secret [$secret] in a user record");
            }
        }
        $this->assertArrayNotHasKey('manager_pins', $this->package()['sections'], 'manager_pins never ship as a section');
    }

    public function test_importer_stores_the_flag_and_verify_manager_honours_it(): void
    {
        $package = $this->package();
        $this->toEdgeDb();
        $this->importer()->import($package);

        $this->assertSame(1, $this->flag($this->mgr));
        $this->assertSame(0, $this->flag($this->cashier));
        $this->assertSame(0, $this->flag($this->disabledPinMgr));
        $this->assertSame(0, $this->flag($this->inactiveMgr));
        $this->assertSame(0, DB::connection('tenant')->table('manager_pins')->count(), 'no PIN hash on the appliance');

        $this->credential($this->mgr, 'MgrPass1');
        $this->credential($this->cashier, 'CashPass1');
        app()->forgetInstance(\App\Services\Edge\EdgeBranchContext::class); // the binding may be memoised from before the import
        $auth = app(EdgeLocalAuthService::class);

        $manager = $auth->verifyManager('APMGR', 'MgrPass1', 'tenant.pos.store', $this->cashier);
        $this->assertSame($this->mgr, (int) $manager->id);

        try {
            $auth->verifyManager('APCSH', 'CashPass1', 'tenant.pos.store', $this->mgr);
            $this->fail('a user exported without the flag must not approve');
        } catch (RuntimeException $e) {
            $this->assertSame('This user is not an approving manager (no active manager PIN on the Cloud).', $e->getMessage());
        }
        try {
            $auth->verifyManager('APMGR', 'MgrPass1', 'tenant.pos.store', $this->mgr);
            $this->fail('self-approval must be refused');
        } catch (RuntimeException $e) {
            $this->assertSame('You cannot approve your own request. Ask another manager to approve.', $e->getMessage());
        }
    }

    public function test_a_pin_removed_on_the_cloud_revokes_offline_approval_with_the_next_refresh(): void
    {
        $first = $this->package();
        $before = $this->cloudWatermark();
        $this->toEdgeDb();
        $this->importer()->import($first);
        $this->credential($this->mgr, 'MgrPass1');
        app()->forgetInstance(\App\Services\Edge\EdgeBranchContext::class); // the binding may be memoised from before the import
        $auth = app(EdgeLocalAuthService::class);
        $this->assertSame($this->mgr, (int) $auth->verifyManager('APMGR', 'MgrPass1', 'tenant.pos.store', $this->cashier)->id);

        // Cloud: the manager's PIN is disabled → the watermark moves → revision 2 carries may_approve_pos = false
        $second = $this->cloudPackage(2, 'snap-2', function ($conn) {
            $conn->table('manager_pins')->where('user_id', $this->mgr)->update(['is_active' => 0, 'updated_at' => now()->addSecond()]);
        });
        $this->assertNotSame($before, $this->cloudWatermark(), 'a manager PIN change mints a new config revision');
        $this->assertFalse(collect($second['sections']['users'])->keyBy('id')[$this->mgr]['may_approve_pos']);

        $meta = $this->importer()->import($second);
        $this->assertSame(2, (int) $meta->last_applied_config_revision);
        $this->assertSame(0, $this->flag($this->mgr), 'the refresh applier rewrote the flag');
        $this->assertSame(1, DB::connection('tenant')->table('edge_local_user_credentials')->where('user_id', $this->mgr)->where('status', 'active')->count(), 'the Edge credential itself survives the refresh');
        try {
            $auth->verifyManager('APMGR', 'MgrPass1', 'tenant.pos.store', $this->cashier);
            $this->fail('after the refresh the former approver must be refused');
        } catch (RuntimeException $e) {
            $this->assertSame('This user is not an approving manager (no active manager PIN on the Cloud).', $e->getMessage());
        }

        // Cloud: PIN re-enabled → revision 3 restores the right (and nothing else about the user changed)
        $third = $this->cloudPackage(3, 'snap-3', function ($conn) {
            $conn->table('manager_pins')->where('user_id', $this->mgr)->update(['is_active' => 1, 'updated_at' => now()->addSeconds(2)]);
        });
        $this->importer()->import($third);
        $this->assertSame(1, $this->flag($this->mgr));
        $this->assertSame($this->mgr, (int) $auth->verifyManager('APMGR', 'MgrPass1', 'tenant.pos.store', $this->cashier)->id);
        $this->assertSame(EdgeBootstrapService::SCHEMA_VERSION, EdgeLocalMeta::current()->bootstrap_schema);
    }
}
