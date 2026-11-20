<?php

namespace Tests\MySql;

use App\Http\Controllers\Edge\EdgeLocalAuthorityControlController;
use App\Models\Tenant\User;
use App\Services\Edge\EdgeAuthorityService;
use App\Services\Edge\EdgeHandbackOrchestrator;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * KHATRI OWNER CONTROL (21 Nov 2026) — "Switch to Offline" / "Return to Online" on the operator status page are a thin,
 * guarded surface over the EXISTING engine: explicit permission, confirm field, lock, idempotency, audit; every refusal is the
 * engine's own; no stale override reaches the engine from HTTP; the CLI mechanism is untouched.
 */
class EdgeLocalAuthorityControlHttpMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

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
        $this->cleanTenant(['edge_sync_outbox', 'edge_local_user_credentials', 'edge_local_print_worker_state', 'edge_local_authority_worker_state',
            'print_jobs', 'printers', 'terminals', 'branches', 'users']);
        config(['app.role' => 'branch_server', 'app.key' => config('app.key') ?: 'base64:' . base64_encode(random_bytes(32)), 'cache.default' => 'array']);
        $this->branchId = $this->makeBranch(['name' => 'Control Branch']);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'CT' . Str::random(4), 'name' => 'Control Owner']);
        $this->bindEdgeLocalMeta($this->branchId, 1, 42, 'control-device-uuid', 1);
        DB::connection('tenant')->table('edge_local_meta')->update(['bootstrap_schema' => config('edge.bootstrap_schema'), 'config_schema_version' => config('edge.config_schema_version'), 'authority_state' => 'standby']);
        $this->seedEdgeCredential($this->userId, $this->branchId, 1);
        config(['edge.sync.device_id' => 'control-device-uuid', 'edge.sync.device_secret' => 'x', 'edge.update.install_root' => sys_get_temp_dir() . '/edge-ctl-' . uniqid()]);
    }

    protected function tearDown(): void
    {
        putenv('APP_ROLE');
        unset($_ENV['APP_ROLE'], $_SERVER['APP_ROLE']);
        putenv('EDGE_LOCAL_APP_KEY');
        unset($_ENV['EDGE_LOCAL_APP_KEY'], $_SERVER['EDGE_LOCAL_APP_KEY']);
        parent::tearDown();
    }

    private function user(bool $withPermission): User
    {
        $user = User::on('tenant')->findOrFail($this->userId);
        if ($withPermission) {
            $perm = Permission::findOrCreate(EdgeLocalAuthorityControlController::PERMISSION, 'tenant');
            $user->givePermissionTo($perm);
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function asLocal(User $user)
    {
        return $this->actingAs($user, 'tenant')->withSession(['edge_login_epoch' => 1, 'edge_credential_version' => 1]);
    }

    private function authorityState(): string
    {
        return (string) DB::connection('tenant')->table('edge_local_meta')->value('authority_state');
    }

    public function test_the_routes_are_registered_inside_the_guarded_group_with_csrf(): void
    {
        foreach (['edge.local.pos.authority.switch-offline', 'edge.local.pos.authority.return-online'] as $name) {
            $this->assertTrue(Route::has($name), $name);
            $route = Route::getRoutes()->getByName($name);
            $this->assertSame(['POST'], $route->methods());
            $raw = $route->middleware();
            $this->assertContains('edge.auth', $raw, "{$name} requires the enrolled local session");
            $this->assertContains('edge.branch', $raw, "{$name} requires the bound branch");
            $this->assertContains('throttle:6,1', $raw, "{$name} is throttled");
            // CSRF: the web group validates tokens by default; bootstrap/app.php exempts only '*/api/print-agent/*' and 'api/edge/*'
            // (installer/device calls). These browser actions are neither.
            $this->assertContains('web', $route->gatherMiddleware(), "{$name} runs in the web group");
            $this->assertStringStartsWith('edge/local/pos/authority/', $route->uri());
            $this->assertStringNotContainsString('api/', $route->uri(), "{$name} must not fall under a CSRF-exempt API path");
            $this->assertTrue(\App\Support\EdgeRouteManifest::isAllowed($name), "{$name} is on the Edge runtime route allowlist");
            // The health page (same group) and the actions share one guard set — no weaker group for the actions.
            $this->assertSame(Route::getRoutes()->getByName('edge.local.pos.health.view')->middleware(), array_values(array_diff($raw, ['throttle:6,1'])));
        }
    }

    public function test_unauthenticated_and_unauthorized_requests_never_reach_the_engine(): void
    {
        $this->post('/edge/local/pos/authority/switch-offline', ['confirm' => 'yes'])->assertRedirect('/edge/local/login');
        $this->post('/edge/local/pos/authority/return-online', ['confirm' => 'yes'])->assertRedirect('/edge/local/login');

        $plain = $this->user(false);
        $this->asLocal($plain)->post('/edge/local/pos/authority/switch-offline', ['confirm' => 'yes'])->assertForbidden();
        $this->asLocal($plain)->post('/edge/local/pos/authority/return-online', ['confirm' => 'yes'])->assertForbidden();
        $this->assertSame('standby', $this->authorityState());

        // The page shows the two controls only to a permitted user.
        $html = $this->asLocal($plain)->get('/edge/local/pos/health');
        if ($html->getStatusCode() === 200) {
            $html->assertSee('Selling authority')->assertDontSee('id="btn-switch-offline"', false);
        }
        $html = $this->asLocal($this->user(true))->get('/edge/local/pos/health');
        if ($html->getStatusCode() === 200) {
            $html->assertSee('id="btn-switch-offline"', false)->assertSee('id="btn-return-online"', false)->assertSee('Who sells now');
        }
    }

    public function test_without_the_confirm_field_nothing_happens(): void
    {
        $r = $this->asLocal($this->user(true))->post('/edge/local/pos/authority/switch-offline', []);
        $r->assertRedirect('/edge/local/pos/health');
        $this->assertFalse(session('authority_control')['ok']);
        $this->assertStringContainsString('Not confirmed', session('authority_control')['message']);
        $this->assertSame('standby', $this->authorityState());
    }

    public function test_switch_to_offline_is_refused_by_the_engine_while_the_cloud_still_holds_the_branch(): void
    {
        // Real engine, no mocks: standby + no lapsed lease → the engine's own refusal is shown verbatim, state unchanged.
        $r = $this->asLocal($this->user(true))->post('/edge/local/pos/authority/switch-offline', ['confirm' => 'yes']);
        $r->assertRedirect('/edge/local/pos/health');
        $ctl = session('authority_control');
        $this->assertFalse($ctl['ok']);
        $this->assertStringStartsWith('Switch to Offline refused: ', $ctl['message']);
        $this->assertSame('standby', $this->authorityState(), 'a refused takeover never moves the authority');
    }

    public function test_return_to_online_in_standby_is_idempotent_and_the_cli_contract_is_reused_for_blockers(): void
    {
        $r = $this->asLocal($this->user(true))->post('/edge/local/pos/authority/return-online', ['confirm' => 'yes']);
        $r->assertRedirect('/edge/local/pos/health');
        $this->assertTrue(session('authority_control')['ok']);
        $this->assertStringContainsString('nothing to return', session('authority_control')['message']);

        // Local writer, REAL orchestrator (same object the CLI uses): with zero acknowledged heartbeats its assessment names
        // CONNECTION_NOT_STABLE (needs 2 consecutive acks) → shown verbatim as the blocker, authority untouched, nothing bypassed.
        DB::connection('tenant')->table('edge_local_meta')->update(['authority_state' => 'local_active', 'heartbeat_consecutive_acks' => 0]);
        $r = $this->asLocal($this->user(true))->post('/edge/local/pos/authority/return-online', ['confirm' => 'yes']);
        $ctl = session('authority_control');
        $this->assertIsArray($ctl, 'status ' . $r->getStatusCode());
        $this->assertFalse($ctl['ok'], $ctl['message'] ?? '');
        $this->assertStringStartsWith('Return to Online is BLOCKED: ', $ctl['message']);
        $this->assertStringContainsString('CONNECTION_NOT_STABLE', $ctl['message']);
        $this->assertSame('local_active', $this->authorityState(), 'a blocked handback never moves the authority');
    }

    public function test_switch_to_offline_calls_the_engine_with_confirmation_and_never_with_a_stale_override(): void
    {
        $this->mock(EdgeAuthorityService::class, function ($m) {
            $m->shouldReceive('state')->andReturn('standby');
            $m->shouldReceive('takeOver')->once()
                ->withArgs(fn ($confirmed, $by, $acceptStale, $reason) => $confirmed === true && str_contains((string) $by, 'Control Owner') && $acceptStale === false && $reason === null)
                ->andReturn(['state' => 'local_active', 'gates' => [], 'already' => false]);
        });
        // A stale override smuggled into the form is ignored: the engine is still called with acceptStale=false.
        $r = $this->asLocal($this->user(true))->post('/edge/local/pos/authority/switch-offline', ['confirm' => 'yes', 'accept_stale' => '1', 'reason' => 'x']);
        $r->assertRedirect('/edge/local/pos/health');
        $this->assertTrue(session('authority_control')['ok']);
        $this->assertStringContainsString('OFFLINE', session('authority_control')['message']);
    }

    public function test_a_concurrent_click_waits_and_an_already_offline_appliance_is_idempotent(): void
    {
        $lock = Cache::lock(EdgeLocalAuthorityControlController::LOCK, 30);
        $this->assertTrue($lock->get());
        try {
            $r = $this->asLocal($this->user(true))->post('/edge/local/pos/authority/switch-offline', ['confirm' => 'yes']);
            $this->assertFalse(session('authority_control')['ok']);
            $this->assertStringContainsString('already in progress', session('authority_control')['message']);
        } finally {
            $lock->release();
        }

        DB::connection('tenant')->table('edge_local_meta')->update(['authority_state' => 'local_active']);
        $r = $this->asLocal($this->user(true))->post('/edge/local/pos/authority/switch-offline', ['confirm' => 'yes']);
        $this->assertTrue(session('authority_control')['ok']);
        $this->assertStringContainsString('already OFFLINE', session('authority_control')['message']);
        $this->assertSame('local_active', $this->authorityState());
    }
}
