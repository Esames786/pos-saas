<?php

namespace Tests\MySql;

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * EDGE 0.9 (stream B) — the setup wizard's appliance commands:
 *   edge:local:users              the Users & PINs list (bootstrapped users, roles, enrolled for the CURRENT epoch, offline control)
 *   edge:local:users --assertions K3 bulk assertions to files; the setup token file is consumed; bad Cloud entries skipped
 *   edge:local:report-status      K4 phases, best effort (no endpoint / old Cloud never fails the wizard)
 *   edge:local:report-lan-profile K5 public material only (CA certificate, never a private key, never a non-CA certificate)
 */
class EdgeWizardCommandsMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private string $dir;

    protected function setUp(): void
    {
        putenv('APP_ROLE=branch_server');
        $_ENV['APP_ROLE'] = $_SERVER['APP_ROLE'] = 'branch_server';
        parent::setUp();
        config(['database.connections.edge_local' => array_merge(config('database.connections.edge_local', []), [
            'host' => config('database.connections.tenant.host'), 'port' => config('database.connections.tenant.port'),
            'database' => config('database.connections.tenant.database'), 'username' => config('database.connections.tenant.username'), 'password' => config('database.connections.tenant.password'),
        ])]);
        DB::purge('edge_local');
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        $this->cleanTenant(['edge_local_user_credentials', 'model_has_roles', 'model_has_permissions', 'users', 'branches']);
        config(['app.role' => 'branch_server']);
        $this->branchId = $this->makeBranch(['name' => 'Wizard Branch']);
        $this->bindEdgeLocalMeta($this->branchId, 3, 42, 'wizard-device-uuid', 1);
        config([
            'edge.sync.device_id' => 'wizard-device-uuid', 'edge.sync.device_secret' => 'device-secret-xyz',
            'edge.auto_update.cloud_base_url' => 'https://cloud.test',
        ]);
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'edge-wiz-' . Str::lower(Str::random(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        putenv('APP_ROLE');
        unset($_ENV['APP_ROLE'], $_SERVER['APP_ROLE']);
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    public function test_users_lists_every_bootstrapped_user_with_roles_enrollment_for_the_current_epoch_and_offline_control(): void
    {
        $owner = $this->makeUser(['name' => 'Owner One', 'employee_code' => 'OWNER1', 'default_branch_id' => $this->branchId]);
        $cashier = $this->makeUser(['name' => 'Counter Two', 'employee_code' => 'C2', 'default_branch_id' => $this->branchId]);
        $stale = $this->makeUser(['name' => 'Old Epoch', 'employee_code' => 'OLD', 'default_branch_id' => $this->branchId]);
        $roleId = DB::connection('tenant')->table('roles')->insertGetId(['name' => 'Owner', 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now()]);
        DB::connection('tenant')->table('model_has_roles')->insert(['role_id' => $roleId, 'model_type' => \App\Models\Tenant\User::class, 'model_id' => $owner]);
        $this->grantEdgePermission($owner, 'tenant.offline-edge.security');
        $this->seedEdgeCredential($owner, $this->branchId, 3);
        $this->seedEdgeCredential($stale, $this->branchId, 2); // a credential of an EARLIER activation epoch is not "enrolled"

        $code = Artisan::call('edge:local:users', ['--json' => true]);
        $raw = Artisan::output();
        $this->assertSame(0, $code, $raw);
        $doc = json_decode(trim($raw), true);
        $this->assertSame($this->branchId, $doc['branch_id']);
        $byId = collect($doc['users'])->keyBy('id');
        $this->assertSame(['Owner'], $byId[$owner]['roles']);
        $this->assertTrue($byId[$owner]['enrolled']);
        $this->assertTrue($byId[$owner]['offline_edge_security']);
        $this->assertFalse($byId[$cashier]['enrolled']);
        $this->assertFalse($byId[$cashier]['offline_edge_security']);
        $this->assertFalse($byId[$stale]['enrolled'], 'enrolled means: for the CURRENT activation epoch');
        $this->assertSame('C2', $byId[$cashier]['employee_code']);
        $this->assertStringNotContainsString('credential_hash', $raw);
        $this->assertStringNotContainsString('$argon2', $raw, 'never credential material');
    }

    public function test_users_fetches_k3_assertions_into_files_consumes_the_token_file_and_skips_bad_entries(): void
    {
        $assertion = fn (int $uid) => ['payload' => ['user_id' => $uid, 'jti' => 'j' . $uid, 'expires_at' => time() + 900], 'signature' => 'sig' . $uid];
        Http::fake(['https://cloud.test/api/edge/setup/enrollment-assertions' => Http::response([
            'assertions' => [
                ['user_id' => 1, 'employee_code' => 'OWNER1', 'name' => 'Owner', 'assertion' => $assertion(1)],
                ['user_id' => 3, 'employee_code' => 'C3', 'name' => 'Three', 'assertion' => $assertion(99)],   // payload names another user
                ['user_id' => 8, 'employee_code' => 'X8', 'name' => 'Not asked', 'assertion' => $assertion(8)], // never requested
            ],
            'skipped' => [['user_id' => 4, 'reason' => 'no_employee_code']],
        ])]);
        $tokenFile = $this->dir . DIRECTORY_SEPARATOR . 'setup.token';
        file_put_contents($tokenFile, "setup-token-SECRET-123\r\n");

        $code = Artisan::call('edge:local:users', ['--assertions-dir' => $this->dir, '--setup-token-file' => $tokenFile, '--ids' => '1,3,4', '--json' => true]);
        $out = Artisan::output();
        $this->assertSame(0, $code, $out);
        $this->assertFileDoesNotExist($tokenFile, 'the setup token file is read then deleted');
        $this->assertStringNotContainsString('setup-token-SECRET-123', $out);
        $doc = json_decode(trim($out), true);
        $this->assertSame([1], array_column($doc['assertions'], 'user_id'));
        $this->assertSame($assertion(1), json_decode((string) file_get_contents($doc['assertions'][0]['file']), true));
        $this->assertEqualsCanonicalizing([4, 3, 8], array_column($doc['skipped'], 'user_id'));
        $this->assertFileDoesNotExist($this->dir . DIRECTORY_SEPARATOR . 'assertion-8.json');
        Http::assertSent(function (HttpRequest $r) {
            return $r->url() === 'https://cloud.test/api/edge/setup/enrollment-assertions'
                && $r->hasHeader('X-Edge-Setup-Token', 'setup-token-SECRET-123')
                && $r->hasHeader('X-Edge-Device-ID', 'wizard-device-uuid')
                && $r->hasHeader('Authorization', 'Bearer device-secret-xyz')
                && $r['user_ids'] === [1, 3, 4];
        });
    }

    public function test_users_assertions_refuse_without_a_token_file_and_report_a_cloud_refusal(): void
    {
        $this->assertSame(1, Artisan::call('edge:local:users', ['--assertions-dir' => $this->dir, '--ids' => '1']));
        Http::fake(['*' => Http::response(['code' => 'SETUP_TOKEN_EXPIRED'], 403)]);
        $tokenFile = $this->dir . DIRECTORY_SEPARATOR . 't';
        file_put_contents($tokenFile, 'tok');
        $this->assertSame(1, Artisan::call('edge:local:users', ['--assertions-dir' => $this->dir, '--setup-token-file' => $tokenFile, '--all-active' => true, '--json' => true]));
        $this->assertStringContainsString('SETUP_TOKEN_EXPIRED', Artisan::output());
        $this->assertFileDoesNotExist($tokenFile);
        Http::assertSent(fn (HttpRequest $r) => $r['all_active'] === true);
    }

    public function test_report_status_posts_k4_and_is_best_effort(): void
    {
        Http::fake(['https://cloud.test/api/edge/device/status' => Http::response(['ok' => true])]);
        $this->assertSame(0, Artisan::call('edge:local:report-status', ['phase' => 'ready', '--message' => '6 users enrolled', '--json' => true]));
        $this->assertTrue(json_decode(trim(Artisan::output()), true)['sent']);
        Http::assertSent(fn (HttpRequest $r) => $r['phase'] === 'ready' && $r['ok'] === true && $r['message'] === '6 users enrolled' && $r['version'] === config('edge.app_version') && isset($r['at']));

        $this->assertSame(1, Artisan::call('edge:local:report-status', ['phase' => 'exploded']), 'unknown phase');

        config(['edge.auto_update.status_url' => 'https://old-cloud.test/api/edge/device/status']);
        Http::fake(['https://old-cloud.test/*' => Http::response([], 404)]); // an older Cloud without the endpoint
        $this->assertSame(0, Artisan::call('edge:local:report-status', ['phase' => 'update_failed', '--failed' => true, '--json' => true]));
        $this->assertFalse(json_decode(trim(Artisan::output()), true)['sent']);

        config(['edge.sync.device_secret' => '']);
        $this->assertSame(0, Artisan::call('edge:local:report-status', ['phase' => 'installing', '--json' => true]));
        $this->assertSame('device_not_paired', json_decode(trim(Artisan::output()), true)['reason']);
    }

    public function test_report_lan_profile_sends_public_material_only(): void
    {
        $pem = $this->khatriCaPem();
        $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $pem));
        $derFile = $this->dir . DIRECTORY_SEPARATOR . 'ca.crt';
        file_put_contents($derFile, $der); // Export-Certificate -Type CERT writes DER
        Http::fake(['https://cloud.test/api/edge/device/lan-profile' => Http::response(['ok' => true])]);
        $this->assertSame(0, Artisan::call('edge:local:report-lan-profile', ['--ca-file' => $derFile, '--lan-ip' => '192.168.100.52', '--https-port' => 8443, '--hostname' => 'bingoo-edge.local', '--json' => true]), Artisan::output());
        Http::assertSent(function (HttpRequest $r) use ($der) {
            return $r['lan_ip'] === '192.168.100.52' && $r['https_port'] === 8443 && $r['lan_hostname'] === 'bingoo-edge.local'
                && str_starts_with($r['ca_certificate_pem'], '-----BEGIN CERTIFICATE-----') && $r['ca_sha256'] === hash('sha256', $der)
                && count($r->data()) === 5;
        });

        $keyFile = $this->dir . DIRECTORY_SEPARATOR . 'oops.pem';
        file_put_contents($keyFile, $pem . "\n-----BEGIN PRIVATE KEY-----\nMIIB\n-----END PRIVATE KEY-----\n");
        $this->assertSame(1, Artisan::call('edge:local:report-lan-profile', ['--ca-file' => $keyFile]));
        $this->assertStringContainsString('PRIVATE KEY', Artisan::output());
        $this->assertSame(1, Artisan::call('edge:local:report-lan-profile', ['--lan-ip' => '999.1.1.1']));
    }

    /** The PUBLIC Khatri branch CA certificate the counter script embeds (a real CA:TRUE certificate; public material). */
    private function khatriCaPem(): string
    {
        $src = (string) file_get_contents(base_path('docs/operator-khatri/Setup-KhatriCounter.ps1'));
        $this->assertSame(1, preg_match('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $src, $m));

        return $m[0];
    }
}
