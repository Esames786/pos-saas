<?php

namespace Tests\Feature\Edge;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * EDGE-LOCAL-POS-1 — route census (branch_server side): the local POS surface EXISTS on a branch_server,
 * every route is auth-gated, and the Cloud-only surfaces stay absent. The Cloud-side absence census is
 * EdgeLocalPosRouteCensusCloudTest (boots with the default cloud role).
 */
class EdgeLocalPosRouteCensusTest extends TestCase
{
    private const POS_ROUTES = [
        'edge.local.pos.terminals',
        'edge.local.pos.terminal.select',
        'edge.local.pos.shift.status',
        'edge.local.pos.shift.open',
        'edge.local.pos.sales.store',
    ];

    protected function setUp(): void
    {
        putenv('APP_ROLE=branch_server');
        $_ENV['APP_ROLE'] = $_SERVER['APP_ROLE'] = 'branch_server';
        $key = 'base64:' . base64_encode(random_bytes(32));
        putenv("EDGE_LOCAL_APP_KEY={$key}");
        $_ENV['EDGE_LOCAL_APP_KEY'] = $_SERVER['EDGE_LOCAL_APP_KEY'] = $key;
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

    public function test_pos_routes_exist_on_branch_server_and_are_auth_gated(): void
    {
        foreach (self::POS_ROUTES as $name) {
            $this->assertTrue(Route::has($name), "branch_server must register [$name]");
            $middleware = Route::getRoutes()->getByName($name)->gatherMiddleware();
            $this->assertContains('edge.auth', $middleware, "[$name] must require the local session");
            $this->assertContains('edge.branch', $middleware, "[$name] must require the bound appliance");
        }
        // unauthenticated request → redirected to the local login, never served.
        $this->get('/edge/local/pos/terminals')->assertStatus(302)->assertRedirect('/edge/local/login');
        // Cloud surfaces remain absent on the appliance.
        $this->assertFalse(Route::has('tenant.pos.index'), 'Cloud POS must not exist on a branch_server');
        $this->get('/pos')->assertStatus(404);
        $this->get('/dashboard')->assertStatus(404);
    }
}
