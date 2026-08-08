<?php

namespace Tests\Feature\Edge;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * EDGE-LOCAL-POS-1 — route census (Cloud side): the branch-local POS surface is ABSENT on APP_ROLE=cloud —
 * the routes are never registered (routes/web.php loads edge_runtime.php only on a branch_server), so the
 * paths are genuine 404s, not merely blocked.
 */
class EdgeLocalPosRouteCensusCloudTest extends TestCase
{
    public function test_pos_routes_are_absent_on_cloud(): void
    {
        $this->assertFalse(\App\Support\EdgeRuntime::isBranchServer(), 'this census runs as Cloud');
        foreach ([
            'edge.local.pos.terminals',
            'edge.local.pos.terminal.select',
            'edge.local.pos.shift.status',
            'edge.local.pos.shift.open',
            'edge.local.pos.shift.close',
            'edge.local.pos.sales.store',
            'edge.local.pos.restaurant.board',
            'edge.local.pos.restaurant.table.open',
            'edge.local.pos.restaurant.session.close',
            'edge.local.pos.held.store',
            'edge.local.pos.held.kot',
            'edge.local.pos.held.settle',
            'edge.local.pos.held.cancel',
            'edge.local.pos.manager.verify',
        ] as $name) {
            $this->assertFalse(Route::has($name), "Cloud must NOT register [$name]");
        }
        $this->get('/edge/local/pos/terminals')->assertStatus(404);
        $this->post('/edge/local/pos/sales')->assertStatus(404);
    }
}
