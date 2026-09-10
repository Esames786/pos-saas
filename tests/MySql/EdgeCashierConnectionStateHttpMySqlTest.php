<?php

namespace Tests\MySql;

use App\Models\Edge\EdgeLocalMeta;
use App\Models\Tenant\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * Q — the REAL cashier browser status. The actual Branch Server cashier page and its sync summary route expose the
 * business connection states only (ONLINE · INTERNET CONNECTION UNSTABLE · INTERNET CONNECTION LOST · PREPARING LOCAL
 * MODE · LOCAL MODE ACTIVE · CONNECTION RESTORED · SYNCHRONIZING · RETURNING TO ONLINE) — never lease timestamps,
 * uuids, hashes, epochs or baseline identifiers.
 */
class EdgeCashierConnectionStateHttpMySqlTest extends MySqlTenantTestCase
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
        config(['database.connections.edge_local' => array_merge(
            config('database.connections.edge_local', []),
            ['host' => config('database.connections.tenant.host'), 'port' => config('database.connections.tenant.port'),
                'database' => $this->tenantDb, 'username' => config('database.connections.tenant.username'),
                'password' => config('database.connections.tenant.password')]
        )]);
        DB::purge('edge_local');
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        $this->cleanTenant([
            'edge_local_connection_transitions', 'edge_sync_outbox', 'edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines',
            'edge_auth_audit', 'edge_local_user_credentials', 'edge_local_meta', 'model_has_permissions', 'permissions',
            'products', 'categories', 'shifts', 'terminals', 'branches', 'users',
        ]);
        $this->branchId = $this->makeBranch();
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'CNS' . Str::random(4)]);
        $terminal = $this->makeTerminal($this->branchId, ['name' => 'Counter A']);
        $productId = $this->makeProduct($this->makeCategory(), ['inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 100]);
        $this->bindEdgeLocalMeta($this->branchId, 1, deviceUuid: 'till-device');
        DB::table('edge_local_meta')->update(['bootstrap_schema' => config('edge.bootstrap_schema'), 'config_schema_version' => config('edge.config_schema')]);
        $this->acceptTestBaseline([['product_id' => $productId, 'product_variant_id' => null, 'quantity' => 20]]);
        $this->seedEdgeCredential($this->userId, $this->branchId, 1);
        config([
            'edge.authority.heartbeat_url' => 'http://127.0.0.1:9/api/edge/authority/heartbeat',
            'edge.authority.ttl_seconds' => 60, 'edge.authority.skew_margin_seconds' => 10,
            'edge.authority.unstable_after_failures' => 2, 'edge.authority.lost_after_failures' => 4, 'edge.authority.handback_min_consecutive_acks' => 2,
            'edge.sync.device_id' => 'till-device', 'edge.sync.device_secret' => 'secret',
        ]);
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');
        $this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $terminal])->assertOk();
    }

    protected function tearDown(): void
    {
        // Restore the PROCESS env: later test classes in this run boot as the Cloud and need the Cloud routes.
        putenv('APP_ROLE');
        unset($_ENV['APP_ROLE'], $_SERVER['APP_ROLE']);
        putenv('EDGE_LOCAL_APP_KEY');
        unset($_ENV['EDGE_LOCAL_APP_KEY'], $_SERVER['EDGE_LOCAL_APP_KEY']);
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    private function facts(array $fill): void
    {
        EdgeLocalMeta::on('tenant')->firstOrFail()->forceFill($fill)->save();
    }

    private function label(): string
    {
        return (string) $this->getJson('/edge/local/pos/sync/summary')->assertOk()->json('connection');
    }

    public function test_the_till_sees_every_business_state_and_no_internals(): void
    {
        $base = ['authority_last_ack_at' => now(), 'heartbeat_consecutive_acks' => 3, 'heartbeat_consecutive_failures' => 0, 'authority_state' => 'standby', 'reconcile_clean_at' => null];
        $this->facts($base);
        $this->assertSame('ONLINE', $this->label());
        $this->facts(['heartbeat_consecutive_failures' => 1, 'authority_last_failure_at' => now()]);
        $this->assertSame('ONLINE', $this->label(), 'one failed heartbeat is not shown as a failure');
        $this->facts(['heartbeat_consecutive_failures' => 2]);
        $this->assertSame('INTERNET CONNECTION UNSTABLE', $this->label());
        $this->facts(['heartbeat_consecutive_failures' => 4]);
        $this->assertSame('INTERNET CONNECTION LOST', $this->label());
        $this->facts(['authority_last_ack_at' => now()->subSeconds(200)]);
        $this->assertSame('PREPARING LOCAL MODE', $this->label());
        $this->facts(['authority_state' => 'local_active']);
        $this->assertSame('LOCAL MODE ACTIVE', $this->label());
        $this->facts(['heartbeat_consecutive_failures' => 0, 'heartbeat_consecutive_acks' => 1, 'authority_last_ack_at' => now()]);
        $this->assertSame('CONNECTION RESTORED', $this->label());
        $this->facts(['heartbeat_consecutive_acks' => 2]);
        $this->assertSame('SYNCHRONIZING', $this->label());
        $this->facts(['authority_state' => 'handing_back']);
        $this->assertSame('RETURNING TO ONLINE', $this->label());
        $this->facts(['authority_state' => 'standby']);
        $this->assertSame('ONLINE', $this->label());

        // The summary carries no internals — only business words and counts.
        $json = $this->getJson('/edge/local/pos/sync/summary')->assertOk()->json();
        $this->assertSame(['pending_sales', 'needs_attention', 'last_synced_at', 'connection', 'state', 'message'], array_keys($json));
        $flat = json_encode($json);
        foreach (['uuid', 'epoch', 'hash', 'baseline', 'lease', 'watermark', 'revision'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $flat, "the till must never see '{$forbidden}'");
        }
    }

    public function test_the_real_cashier_page_renders_the_connection_chip(): void
    {
        $html = $this->get('/edge/local/pos')->assertOk()->getContent();
        $this->assertStringContainsString('id="sync-chip"', $html);
        $this->assertStringContainsString('s.connection', $html, 'the page reads the business connection state from the sync summary');
        $this->assertStringContainsString("'INTERNET CONNECTION LOST'", $html);
        $this->assertStringContainsString("'LOCAL MODE ACTIVE'", $html);
    }
}
