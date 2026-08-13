<?php

namespace Tests\MySql;

use App\Models\Edge\EdgeAuthAudit;
use App\Models\Edge\EdgeConsumedAssertion;
use App\Models\Edge\EdgeLocalUserCredential;
use App\Models\Master\Tenant;
use App\Models\Tenant\Branch;
use App\Models\Tenant\User;
use App\Services\Edge\EdgeBootstrapService;
use App\Services\Edge\EdgeEnrollmentConsumer;
use App\Services\Edge\EdgeEnrollmentCrypto;
use App\Services\Edge\EdgeLocalAuthService;
use App\Services\Edge\EdgePairingService;
use App\Services\Edge\OfflineEdgeEntitlementService;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;

/**
 * EDGE-LOCAL-AUTH-1 (Section 17) — authoritative MySQL auth matrix. A bootstrapped Edge-local DB (real
 * buildSections package incl. a branch-authorized user + role + permission) is imported, then Edge
 * enrollment/login/manager-reauth are exercised with a real Ed25519 test key — Cloud master pointed at
 * a nonexistent DB throughout (proves master-independence). Cases A–X (K concurrency is a separate
 * two-process test).
 */
class EdgeLocalAuthMySqlTest extends MySqlTenantTestCase
{
    private string $edgeDb;
    private array $package;
    private int $branchId;
    private int $branchBId;
    private int $userId;
    private string $employeeCode = 'EMP1';
    private array $keys;
    private static bool $edgeReady = false;

    protected function setUp(): void
    {
        parent::setUp();
        // PLATFORM TEST-ISOLATION: env-driven per-worktree Edge-local DB (never a shared literal).
        $this->edgeDb = \Tests\MySql\Support\EdgeTestDatabases::local();
        config(['app.role' => 'branch_server']);

        $this->keys = EdgeEnrollmentCrypto::generateKeypair();
        config([
            'edge.enrollment.signing_key' => $this->keys['secret'],
            'edge.enrollment.public_key' => $this->keys['public'],
            'edge.enrollment.assertion_ttl' => 900,
        ]);

        $this->seedCloudSource();
        $this->package = $this->buildRealPackage();
        $this->provisionEdgeLocalDb();
        app(EdgeEnrollmentConsumer::class); // warm container
        DB::connection('tenant')->table('edge_local_meta')->exists(); // ensure bound
        // Import so edge-local has the user/permissions + binding.
        $this->import();
        $this->userId = (int) DB::connection('tenant')->table('users')->where('employee_code', $this->employeeCode)->value('id');
    }

    protected function tearDown(): void
    {
        // Restore master + default connection (this test points master at a nonexistent DB + makes
        // tenant the default to mirror the appliance).
        config(['database.connections.master.database' => (string) env('DB_DATABASE', 'pos_test_master')]);
        DB::purge('master');
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        config(['database.connections.tenant.database' => $this->tenantDb]);
        DB::purge('tenant');
        parent::tearDown();
    }

    // ── cloud-source fixture: branch A + branch B + one branch-A user with a role+permission ──

    private function seedCloudSource(): void
    {
        $this->cleanTenant([
            'model_has_permissions', 'model_has_roles', 'role_has_permissions', 'permissions', 'roles',
            'branch_user', 'users', 'branches',
        ]);
        $c = DB::connection('tenant');
        $this->branchId = $c->table('branches')->insertGetId(['name' => 'A', 'code' => 'A', 'status' => 'active', 'timezone' => 'Asia/Karachi', 'created_at' => now(), 'updated_at' => now()]);
        $this->branchBId = $c->table('branches')->insertGetId(['name' => 'B', 'code' => 'B', 'status' => 'active', 'timezone' => 'Asia/Karachi', 'created_at' => now(), 'updated_at' => now()]);

        $uid = $c->table('users')->insertGetId([
            'name' => 'Cashier One', 'email' => 'emp1@x.test', 'password' => bcrypt('cloud-secret-unused'),
            'employee_code' => $this->employeeCode, 'status' => 'active', 'default_branch_id' => $this->branchId,
            'allowed_order_types' => json_encode(['dine_in', 'takeaway']), 'default_order_type' => 'dine_in', 'locale' => 'en',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $c->table('branch_user')->insert(['branch_id' => $this->branchId, 'user_id' => $uid, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $roleId = $c->table('roles')->insertGetId(['name' => 'Cashier', 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now()]);
        $permId = $c->table('permissions')->insertGetId(['name' => 'tenant.pos.store', 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now()]);
        $c->table('role_has_permissions')->insert(['permission_id' => $permId, 'role_id' => $roleId]);
        $c->table('model_has_roles')->insert(['role_id' => $roleId, 'model_type' => User::class, 'model_id' => $uid]);
    }

    private function buildRealPackage(): array
    {
        $svc = new class(app(OfflineEdgeEntitlementService::class), app(TenancyManager::class), app(EdgePairingService::class)) extends EdgeBootstrapService {
            public function sectionsFor(Tenant $t, Branch $b): array
            {
                return $this->buildSections($t, $b);
            }
        };
        $tenant = new Tenant(['tenant_code' => 'demo', 'business_name' => 'Demo', 'currency_code' => 'PKR']);
        $tenant->id = 42;
        $branch = Branch::on('tenant')->find($this->branchId);
        $sections = $svc->sectionsFor($tenant, $branch);
        $summary = [];
        foreach ($sections as $n => $rows) {
            $summary[$n] = ['hash' => hash('sha256', $svc->canonicalJson($rows)), 'count' => count($rows)];
        }
        $manifest = [
            'schema_version' => EdgeBootstrapService::SCHEMA_VERSION, 'snapshot_uuid' => 'snap-1',
            'tenant_code' => 'demo', 'tenant_id' => 42, 'branch_id' => $this->branchId,
            'device_public_uuid' => 'device-A', 'activation_epoch' => 1,
            'config_revision' => 1, 'config_schema_version' => EdgeBootstrapService::CONFIG_SCHEMA_VERSION,
            'source_revision' => 'rev-1', 'sections' => $summary,
        ];
        $manifest['manifest_hash'] = $svc->computeManifestHash(EdgeBootstrapService::SCHEMA_VERSION, 'snap-1', 42, $this->branchId, 'device-A', 1, 1, EdgeBootstrapService::CONFIG_SCHEMA_VERSION, $summary);

        return ['manifest' => $manifest, 'sections' => $sections];
    }

    private function provisionEdgeLocalDb(): void
    {
        $conf = config('database.connections.tenant');
        if (! self::$edgeReady) {
            $pdo = new PDO("mysql:host={$conf['host']};port={$conf['port']};charset=utf8mb4", $conf['username'], $conf['password'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
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
        // Clean import targets + auth tables between tests.
        $this->cleanTenant([
            'edge_auth_audit', 'edge_consumed_assertions', 'edge_local_user_credentials', 'edge_local_meta',
            'model_has_permissions', 'model_has_roles', 'role_has_permissions', 'permissions',
            'users', 'roles', 'terminals', 'branches',
        ]);
    }

    private function import(): void
    {
        app(\App\Services\Edge\EdgeLocalBootstrapImporter::class)->import($this->package);

        // Mirror the appliance runtime: the tenant (edge-local) connection is the DEFAULT, so Spatie's
        // Permission/Role models resolve there (IdentifyTenant/commands do this via useAsTenantConnection).
        DB::setDefaultConnection('tenant');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        // Master unavailable for the whole auth surface.
        config(['database.connections.master.database' => 'nonexistent_master_auth']);
        DB::purge('master');
    }

    private function consumer(): EdgeEnrollmentConsumer
    {
        return app(EdgeEnrollmentConsumer::class);
    }

    private function authSvc(): EdgeLocalAuthService
    {
        return app(EdgeLocalAuthService::class);
    }

    /** A signed assertion for the bound appliance, with optional payload overrides. */
    private function assertion(array $overrides = []): array
    {
        $now = time();
        $payload = array_merge([
            'version' => EdgeEnrollmentCrypto::ASSERTION_VERSION, 'purpose' => EdgeEnrollmentCrypto::PURPOSE,
            'tenant_id' => 42, 'tenant_code' => 'demo', 'branch_id' => $this->branchId,
            'device_public_uuid' => 'device-A', 'activation_epoch' => 1, 'user_id' => $this->userId,
            'jti' => (string) Str::ulid(), 'issuer_user_id' => 999, 'issued_at' => $now, 'expires_at' => $now + 300,
        ], $overrides);

        return EdgeEnrollmentCrypto::sign($payload, $this->keys['secret']);
    }

    private function enroll(string $credential = 'strongpass1'): void
    {
        $this->consumer()->consume($this->assertion(), $credential);
    }

    // ── tests ────────────────────────────────────────────────────────────────

    public function test_C_valid_assertion_enrolls_and_L_login_succeeds_with_roles_and_order_types(): void
    {
        $cred = $this->consumer()->consume($this->assertion(), 'strongpass1');
        $this->assertSame($this->userId, (int) $cred->user_id);
        $this->assertSame(1, (int) $cred->activation_epoch);

        // L: login with the Edge credential.
        $user = $this->authSvc()->verifyForLogin($this->employeeCode, 'strongpass1');
        $this->assertSame($this->userId, (int) $user->id);

        // R + S: roles/permissions + order types resolve locally after login.
        $this->assertTrue($user->hasRole('Cashier'));
        $this->assertTrue($user->can('tenant.pos.store'), 'reconstructed permission graph must resolve can()');
        $this->assertEqualsCanonicalizing(['dine_in', 'takeaway'], $user->effectiveAllowedOrderTypes());
    }

    public function test_M_cloud_password_is_never_the_verifier_and_N_wrong_credential_fails(): void
    {
        $this->enroll('strongpass1');
        // M: the Cloud password (from the source) must NOT authenticate; users.password is null locally.
        $this->assertNull(DB::connection('tenant')->table('users')->where('id', $this->userId)->value('password'));
        $this->expectException(RuntimeException::class);
        $this->authSvc()->verifyForLogin($this->employeeCode, 'cloud-secret-unused');
    }

    public function test_N_wrong_edge_credential_fails(): void
    {
        $this->enroll('strongpass1');
        $this->expectExceptionMessage('Invalid credentials');
        $this->authSvc()->verifyForLogin($this->employeeCode, 'wrongwrong');
    }

    public function test_A_no_binding_rejects_login_and_enrollment(): void
    {
        DB::connection('tenant')->table('edge_local_meta')->delete(); // remove binding
        $this->expectException(\App\Exceptions\EdgeNotBoundException::class);
        $this->authSvc()->verifyForLogin($this->employeeCode, 'x');
    }

    public function test_B_user_without_credential_fails_controlled(): void
    {
        $this->expectExceptionMessage('Invalid credentials'); // no enrollment yet
        $this->authSvc()->verifyForLogin($this->employeeCode, 'anything');
    }

    public function test_D_to_I_binding_and_signature_rejections(): void
    {
        $this->assertReject($this->assertion(['tenant_id' => 99]), 'D wrong tenant');
        $this->assertReject($this->assertion(['branch_id' => $this->branchBId]), 'E wrong branch');
        $this->assertReject($this->assertion(['device_public_uuid' => 'device-Z']), 'F wrong device');
        $this->assertReject($this->assertion(['activation_epoch' => 2]), 'G wrong epoch');
        $this->assertReject($this->assertion(['expires_at' => time() - 1]), 'H expired');
        // I: tamper the payload AFTER signing.
        $t = $this->assertion();
        $t['payload']['user_id'] = 999999;
        $this->assertReject($t, 'I tampered');
    }

    public function test_J_replay_rejected(): void
    {
        $a = $this->assertion();
        $this->consumer()->consume($a, 'strongpass1');
        try {
            $this->consumer()->consume($a, 'strongpass1'); // same jti
            $this->fail('replay must be rejected');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('replay', $e->getMessage());
        }
    }

    public function test_O_lockout_after_max_attempts(): void
    {
        $this->enroll('strongpass1');
        for ($i = 0; $i < EdgeLocalAuthService::MAX_ATTEMPTS; $i++) {
            try {
                $this->authSvc()->verifyForLogin($this->employeeCode, 'nope');
            } catch (RuntimeException $e) {
            }
        }
        // Now locked — even the correct credential is refused.
        try {
            $this->authSvc()->verifyForLogin($this->employeeCode, 'strongpass1');
            $this->fail('should be locked');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('locked', strtolower($e->getMessage()));
        }
    }

    public function test_P_inactive_and_Q_cross_branch_user_fail(): void
    {
        $this->enroll('strongpass1');
        // P: inactive user.
        DB::connection('tenant')->table('users')->where('id', $this->userId)->update(['status' => 'inactive']);
        $this->assertLoginFails('strongpass1', 'inactive');

        DB::connection('tenant')->table('users')->where('id', $this->userId)->update(['status' => 'active']);
        // Q: revoke branch access — no default branch + no active pivot for the bound branch A (branch B
        // does not even exist on this branch-A-scoped appliance).
        DB::connection('tenant')->table('branch_user')->where('user_id', $this->userId)->update(['is_active' => 0]);
        DB::connection('tenant')->table('users')->where('id', $this->userId)->update(['default_branch_id' => null]);
        $this->assertLoginFails('strongpass1', 'cross-branch');
    }

    public function test_T_rotation_invalidates_previous_credential(): void
    {
        $this->consumer()->consume($this->assertion(), 'strongpass1');
        $this->consumer()->consume($this->assertion(), 'strongpass2'); // rotate (new jti)
        // old fails, new works
        $this->assertLoginFails('strongpass1', 'old rotated credential');
        $this->assertSame($this->userId, (int) $this->authSvc()->verifyForLogin($this->employeeCode, 'strongpass2')->id);
        $this->assertSame(2, (int) EdgeLocalUserCredential::where('user_id', $this->userId)->value('credential_version'));
    }

    public function test_U_epoch_N_credential_under_N1_binding_fails(): void
    {
        $this->enroll('strongpass1');
        // Bump the bound epoch to 2 (device replacement) — the epoch-1 credential must not authenticate.
        DB::connection('tenant')->table('edge_local_meta')->update(['activation_epoch' => 2]);
        $this->assertLoginFails('strongpass1', 'stale epoch');
    }

    public function test_V_manager_reauth_records_manager_and_requires_permission(): void
    {
        $this->enroll('strongpass1');
        // Manager has the permission (Cashier role granted tenant.pos.store here) → success returns manager.
        $manager = $this->authSvc()->verifyManager($this->employeeCode, 'strongpass1', 'tenant.pos.store');
        $this->assertSame($this->userId, (int) $manager->id);
        $this->assertTrue(EdgeAuthAudit::where('event', EdgeAuthAudit::E_MGR_OK)->where('user_id', $this->userId)->exists());
        // Missing permission → rejected.
        $this->expectExceptionMessage('not authorized');
        $this->authSvc()->verifyManager($this->employeeCode, 'strongpass1', 'tenant.finance.close');
    }

    public function test_W_master_absent_login_still_works(): void
    {
        $this->enroll('strongpass1'); // master already nonexistent (set in import())
        $user = $this->authSvc()->verifyForLogin($this->employeeCode, 'strongpass1');
        $this->assertSame($this->userId, (int) $user->id);
        $this->authSvc()->logout($user->id);
        $this->assertTrue(true); // no master query threw
    }

    public function test_X_no_password_or_pin_hash_present_locally(): void
    {
        $this->enroll('strongpass1');
        // users.password is null; no manager_pins imported; the credential hash is Argon2id, not the cloud value.
        $this->assertNull(DB::connection('tenant')->table('users')->where('id', $this->userId)->value('password'));
        $hash = EdgeLocalUserCredential::where('user_id', $this->userId)->value('credential_hash');
        $this->assertStringStartsWith('$argon2id$', $hash);
    }

    public function test_assertion_time_contract(): void
    {
        $now = time();
        $this->assertReject($this->assertion(['issued_at' => $now + 3600, 'expires_at' => $now + 3900]), 'issued in future');
        $this->assertReject($this->assertion(['issued_at' => $now + 50, 'expires_at' => $now + 40]), 'exp <= iat');
        $this->assertReject($this->assertion(['issued_at' => $now, 'expires_at' => $now + 100000]), 'lifetime exceeds ttl');
    }

    public function test_ineligible_user_without_employee_code_is_refused(): void
    {
        DB::connection('tenant')->table('users')->where('id', $this->userId)->update(['employee_code' => null]);
        $this->assertReject($this->assertion(), 'null employee_code');
    }

    public function test_durable_enrollment_audit_rolls_back_the_whole_enrollment_if_audit_fails(): void
    {
        $conn = DB::connection('tenant');
        $create = $conn->select('SHOW CREATE TABLE edge_auth_audit')[0]->{'Create Table'};
        $conn->statement('DROP TABLE edge_auth_audit');
        try {
            try {
                $this->consumer()->consume($this->assertion(), 'strongpass1');
                $this->fail('enrollment must fail when the durable audit cannot be written');
            } catch (\Throwable $e) {
                // expected
            }
            // Coherent rollback: no credential, no consumed jti.
            $this->assertSame(0, EdgeLocalUserCredential::count(), 'enrollment must roll back if the durable audit fails');
            $this->assertSame(0, EdgeConsumedAssertion::count(), 'jti must not be consumed');
        } finally {
            $conn->statement($create); // restore for subsequent tests
        }
    }

    public function test_lockout_is_concurrency_safe_no_lost_update(): void
    {
        $this->enroll('strongpass1');
        // One short of the threshold: two concurrent wrong attempts MUST cross it (no lost increment).
        DB::connection('tenant')->table('edge_local_user_credentials')->where('user_id', $this->userId)
            ->update(['failed_attempts' => EdgeLocalAuthService::MAX_ATTEMPTS - 2]);

        $worker = base_path('tests/MySql/Support/edge_login_worker.php');
        $env = ['EDGE_TEST_LOCAL_DB' => $this->edgeDb, 'EDGE_ENROLLMENT_PUBLIC_KEY' => $this->keys['public'], 'APP_ENV' => 'testing', 'EDGE_LOGIN_SLEEP_MS' => '350'];
        $h1 = $this->startWorker([PHP_BINARY, $worker, $this->employeeCode, 'wrongpass'], $env);
        $h2 = $this->startWorker([PHP_BINARY, $worker, $this->employeeCode, 'wrongpass'], $env);
        $o = [$this->finishWorker($h1), $this->finishWorker($h2)];

        $cred = EdgeLocalUserCredential::where('user_id', $this->userId)->first();
        $this->assertNotNull($cred->locked_until, 'two concurrent failures from MAX-2 must lock the account (no lost update): ' . json_encode($o));
        $this->assertTrue($cred->locked_until->isFuture());
    }

    public function test_K_concurrent_jti_consumption_exactly_one_succeeds(): void
    {
        $assertion = $this->assertion();
        $file = sys_get_temp_dir() . '/edge_race_' . uniqid() . '.json';
        file_put_contents($file, json_encode($assertion));

        $worker = base_path('tests/MySql/Support/edge_enroll_worker.php');
        $env = ['EDGE_TEST_LOCAL_DB' => $this->edgeDb, 'EDGE_ENROLLMENT_PUBLIC_KEY' => $this->keys['public'], 'APP_ENV' => 'testing', 'EDGE_ENROLL_SLEEP_MS' => '300'];

        $h1 = $this->startWorker([PHP_BINARY, $worker, $file, 'strongpass1'], $env);
        $h2 = $this->startWorker([PHP_BINARY, $worker, $file, 'strongpass1'], $env);
        $out = [$this->finishWorker($h1), $this->finishWorker($h2)];
        @unlink($file);

        $successes = count(array_filter($out, fn ($o) => str_contains($o, 'SUCCESS')));
        $this->assertSame(1, $successes, 'exactly one concurrent enrollment may succeed: ' . json_encode($out));
        $this->assertSame(1, EdgeLocalUserCredential::count(), 'exactly one credential row');
        $this->assertSame(1, EdgeConsumedAssertion::count(), 'jti consumed exactly once');
    }

    private function startWorker(array $cmd, array $env): array
    {
        $pipes = [];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), array_merge(getenv() ?: [], $env));

        return ['proc' => $proc, 'pipes' => $pipes];
    }

    private function finishWorker(array $h): string
    {
        $out = trim(stream_get_contents($h['pipes'][1]));
        fclose($h['pipes'][1]);
        fclose($h['pipes'][2]);
        proc_close($h['proc']);

        return $out;
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function assertReject(array $assertion, string $label): void
    {
        try {
            $this->consumer()->consume($assertion, 'strongpass1');
            $this->fail("{$label}: should have been rejected");
        } catch (RuntimeException $e) {
            $this->assertTrue(true, $label);
        }
        // Nothing enrolled from a rejected assertion.
        $this->assertSame(0, EdgeLocalUserCredential::count(), "{$label}: no credential should exist");
        $this->assertSame(0, EdgeConsumedAssertion::count(), "{$label}: no jti should be consumed");
    }

    private function assertLoginFails(string $credential, string $label): void
    {
        try {
            $this->authSvc()->verifyForLogin($this->employeeCode, $credential);
            $this->fail("{$label}: login should fail");
        } catch (RuntimeException $e) {
            $this->assertTrue(true, $label);
        }
    }
}
