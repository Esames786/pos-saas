<?php

namespace Tests\Feature\Edge;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * EDGE-RUNTIME-BOUNDARY-HARDEN-1 (#4/#5/#6) — proves route REGISTRATION per runtime mode by booting
 * the app AS a Branch Server (APP_ROLE forced before boot). On a Branch Server the entire Cloud SaaS
 * surface (web AND the central API) is not even registered; only the Edge runtime endpoints are —
 * and they actually answer over HTTP. Cloud-side behavior (edge.local.* absent) is asserted in
 * EdgeRuntimeBoundaryTest which boots in the default cloud mode.
 */
class EdgeBranchServerRegistrationTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('APP_ROLE=branch_server');
        $_ENV['APP_ROLE'] = 'branch_server';
        $_SERVER['APP_ROLE'] = 'branch_server';
        // A real appliance always carries its own machine-local key (config/app.php resolves app.key
        // from EDGE_LOCAL_APP_KEY on a branch_server); provide one so the fail-closed boot guard passes.
        $key = 'base64:' . base64_encode(random_bytes(32));
        putenv("EDGE_LOCAL_APP_KEY={$key}");
        $_ENV['EDGE_LOCAL_APP_KEY'] = $key;
        $_SERVER['EDGE_LOCAL_APP_KEY'] = $key;
        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv('APP_ROLE');
        unset($_ENV['APP_ROLE'], $_SERVER['APP_ROLE']);
        putenv('EDGE_LOCAL_APP_KEY');
        unset($_ENV['EDGE_LOCAL_APP_KEY'], $_SERVER['EDGE_LOCAL_APP_KEY']);
        parent::tearDown();
    }

    public function test_branch_server_registers_only_edge_runtime_routes(): void
    {
        $this->assertSame('branch_server', \App\Support\EdgeRuntime::mode());

        // Edge runtime endpoints ARE registered.
        $this->assertTrue(Route::has('edge.local.health'));
        $this->assertTrue(Route::has('edge.local.ready'));
        $this->assertTrue(Route::has('edge.local.build-info'));

        // The whole Cloud SaaS surface (web) is NOT registered.
        foreach ([
            'tenant.pos.store', 'tenant.shifts.store', 'tenant.sales-orders.split-bill.store',
            'tenant.purchase-orders.index', 'tenant.finance.accounts.index',
            'tenant.manufacturing.production-orders.store', 'central.tenants.index',
            'central.tenants.provision', 'public.home',
        ] as $name) {
            $this->assertFalse(Route::has($name), "Cloud route [$name] must NOT be registered on a Branch Server.");
        }

        // The central/API surface (edge.api.*) is NOT registered either — proves the boundary is not
        // web-only; the API group simply does not exist on a Branch Server.
        $this->assertFalse(Route::has('edge.api.pair'));
        $this->assertFalse(Route::has('edge.api.bootstrap.create'));
    }

    public function test_branch_server_edge_endpoints_answer_over_http(): void
    {
        $this->getJson('/edge/local/health')->assertOk()->assertJson(['status' => 'ok', 'runtime_mode' => 'branch_server']);
        $this->getJson('/edge/local/build-info')->assertOk()->assertJson(['product' => 'Bingoo POS Edge']);

        // EDGE-LOCAL-RUNTIME-1: before the local DB is provisioned / a bootstrap is imported, readiness
        // FAILS the local-runtime checks (503) and must never claim selling readiness.
        $this->getJson('/edge/local/ready')
            ->assertStatus(503)
            ->assertJson([
                'ready' => false,
                'local_database' => 'not_ready',
                'bootstrap_binding' => 'not_ready',
                'operational_stock' => 'not_ready',
                'activation_ready' => false,
            ]);
    }

    public function test_branch_server_route_census_is_only_the_approved_surface(): void
    {
        // EXACT census: enumerate EVERY registered route URI (not just known cloud names) so a
        // framework/fallback/unnamed route can never sneak onto a Branch Server. Any new route fails
        // this test until it is deliberately added to the approved set.
        $approved = [
            'up',                    // framework health probe (non-secret) — deliberately approved
            'edge/local/health',
            'edge/local/ready',
            'edge/local/build-info',
            // EDGE-LOCAL-AUTH-1 — local login/logout/status (Edge-credential auth; NOT POS).
            'edge/local/login',
            'edge/local/logout',
            'edge/local/status',
        ];

        $uris = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
            ->map(fn ($r) => $r->uri())
            ->unique()
            ->values()
            ->all();

        $unexpected = array_values(array_diff($uris, $approved));
        $this->assertSame([], $unexpected, 'Unexpected routes registered on a Branch Server: ' . implode(', ', $unexpected));
    }

    public function test_branch_server_returns_404_for_unregistered_cloud_paths(): void
    {
        // Not registered -> 404 (routing), regardless of middleware. Proves no cloud surface exists.
        $this->get('/pos')->assertNotFound();
        $this->get('/login')->assertNotFound();
        $this->get('/api/edge/pair')->assertNotFound();
    }
}
