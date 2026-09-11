<?php

namespace Tests\MySql;

use App\Models\Tenant\User;
use App\Services\Edge\EdgeLocalPurchaseReturnService;
use App\Services\Edge\EdgePurchaseReturnEnvelopeBuilder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\EdgePurchaseReturnFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * OFFLINE EDGE — F3 REAL EDGE UI PROOFS (branch_server-booted app, real routes → middleware → controller → services → Blade):
 * the Purchase Returns page carries the Online UX (Source GRN, Received Lines: Received / Already Returned / Returnable /
 * Return Qty / Unit Cost / Line Total / Reason, header reason, return total, post), the cashier header entry point, the
 * real JSON routes, and server-side authorization: a cashier gets 403; a store-only user may look but not post.
 */
class EdgePurchaseReturnHttpMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;
    use EdgePurchaseReturnFixture;

    private int $branchId;
    private int $terminalId;
    private int $operatorId;
    private int $cashierId;
    private int $storeOnlyId;

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
            'database' => $this->tenantDb, 'username' => config('database.connections.tenant.username'), 'password' => config('database.connections.tenant.password'),
        ])]);
        DB::purge('edge_local');
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        $this->cleanTenant(array_merge(self::PR_EDGE_TABLES, self::PR_TABLES, [
            'edge_sync_outbox', 'edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines', 'edge_auth_audit', 'edge_local_user_credentials', 'edge_local_meta',
            'model_has_permissions', 'permissions', 'shifts', 'payment_methods', 'products', 'categories', 'units', 'terminals', 'branches', 'users',
        ]));
        $this->branchId = $this->makeBranch(['name' => 'Return Branch', 'timezone' => 'Asia/Karachi']);
        $this->operatorId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'OP' . Str::random(4)]);
        $this->cashierId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'CA' . Str::random(4)]);
        $this->storeOnlyId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'SO' . Str::random(4)]);
        $this->terminalId = $this->makeTerminal($this->branchId, ['name' => 'Counter A']);
        $this->makePaymentMethod(['method_type' => 'cash']);
        $unit = DB::table('units')->insertGetId(['code' => 'pc', 'name' => 'Piece', 'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $product = $this->makeProduct($this->makeCategory(), ['name' => 'Raw Item', 'unit_id' => $unit, 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_purchasable' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 500]);
        $this->seedCloudPurchaseReturnTruth($this->branchId, $product, $unit);
        $this->bindEdgeLocalMeta($this->branchId, 1, deviceUuid: 'pr-http-box');
        DB::table('edge_local_meta')->update(['bootstrap_schema' => config('edge.bootstrap_schema'), 'config_schema_version' => config('edge.config_schema'), 'authority_last_ack_at' => now()->subMinute()]);
        $this->acceptTestBaseline([['product_id' => $product, 'product_variant_id' => null, 'quantity' => 20]]);
        foreach ([$this->operatorId, $this->cashierId, $this->storeOnlyId] as $u) {
            $this->seedEdgeCredential($u, $this->branchId, 1);
        }
        $this->grantEdgePermission($this->operatorId, EdgeLocalPurchaseReturnService::PERM_STORE);
        $this->grantEdgePermission($this->operatorId, EdgeLocalPurchaseReturnService::PERM_POST);
        $this->grantEdgePermission($this->storeOnlyId, EdgeLocalPurchaseReturnService::PERM_STORE);
        $this->projectPurchaseReturnsToAppliance($this->branchId);
        $this->actingAs(User::on('tenant')->find($this->operatorId), 'tenant');
        Auth::shouldUse('tenant');
        $this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $this->terminalId])->assertOk();
    }

    protected function tearDown(): void
    {
        putenv('APP_ROLE');
        unset($_ENV['APP_ROLE'], $_SERVER['APP_ROLE']);
        putenv('EDGE_LOCAL_APP_KEY');
        unset($_ENV['EDGE_LOCAL_APP_KEY'], $_SERVER['EDGE_LOCAL_APP_KEY']);
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    public function test_the_real_page_carries_the_online_purchase_return_ux(): void
    {
        $pos = $this->get('/edge/local/pos')->assertOk()->getContent();
        $this->assertStringContainsString('id="purchase-returns-link"', $pos);
        $html = $this->get('/edge/local/pos/purchase-returns')->assertOk()->getContent();
        foreach (['Purchase Returns', 'Source GRN', 'Received Lines', 'Already Returned', 'Returnable', 'Return Qty', 'Unit Cost', 'Line Total', 'Header reason', 'Return Date', 'Return Total',
            'Post Return (pending sync)', 'needs the Online POS', '/purchase-returns/options', "/purchase-returns/grns/' + id", "'/purchase-returns'", 'PENDING SYNC', 'Dr Accounts Payable / Cr Inventory Asset'] as $needle) {
            $this->assertStringContainsString($needle, $html, "the Purchase Returns page must carry {$needle}");
        }
    }

    public function test_the_operator_returns_goods_through_the_real_routes(): void
    {
        $options = $this->getJson('/edge/local/pos/purchase-returns/options')->assertOk()->json();
        $this->assertTrue($options['freshness']['ok'], json_encode($options['freshness']));
        $this->assertSame(['can_view' => true, 'can_post' => true], $options['permissions']);
        $this->assertContains('damaged', $options['rules']['reason_codes']);
        $this->assertCount(1, $options['grns']);
        $this->assertSame(10.0, (float) $options['grns'][0]['returnable_total']);
        $this->assertSame('PB-PR-1', $options['grns'][0]['bill_no']);
        $grn = $this->getJson('/edge/local/pos/purchase-returns/grns/' . $this->prGrnId)->assertOk()->json();
        $this->assertSame(10.0, (float) $grn['lines'][0]['returnable']);
        $this->assertSame(300.0, (float) $grn['lines'][0]['unit_cost']);
        $this->assertSame(20.0, (float) $grn['lines'][0]['local_on_hand']);

        $this->postJson('/edge/local/pos/purchase-returns', ['cloud_grn_id' => $this->prGrnId, 'lines' => [['cloud_grn_line_id' => $this->prGrnLineId, 'quantity' => 7]]])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains((string) $m, 'reason is required'));
        $ev = $this->postJson('/edge/local/pos/purchase-returns', ['cloud_grn_id' => $this->prGrnId, 'reason_code' => 'damaged', 'notes' => 'crushed', 'lines' => [['cloud_grn_line_id' => $this->prGrnLineId, 'quantity' => 7]]])
            ->assertStatus(201)->json('event');
        $this->assertSame('pending', $ev['sync']['state']);
        $this->assertSame(2100.0, (float) $ev['grand_total']);
        $this->assertSame($this->terminalId, json_decode((string) DB::table('edge_sync_outbox')->where('sale_uuid', $ev['event_uuid'])->value('envelope'), true)['actor']['terminal_id']);
        $this->assertSame(13.0, (float) DB::table('edge_operational_stock_balances')->sum('quantity_on_hand'));
        $grn = $this->getJson('/edge/local/pos/purchase-returns/grns/' . $this->prGrnId)->assertOk()->json();
        $this->assertSame(['pending_local_quantity' => 7.0, 'returnable' => 3.0], ['pending_local_quantity' => (float) $grn['lines'][0]['pending_local_quantity'], 'returnable' => (float) $grn['lines'][0]['returnable']]);
        $this->postJson('/edge/local/pos/purchase-returns', ['cloud_grn_id' => $this->prGrnId, 'reason_code' => 'damaged', 'lines' => [['cloud_grn_line_id' => $this->prGrnLineId, 'quantity' => 4]]])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains((string) $m, 'only 3.000 returnable'));
        $this->getJson('/edge/local/pos/purchase-returns/' . $ev['event_uuid'])->assertOk()->assertJsonPath('event.event_type', EdgePurchaseReturnEnvelopeBuilder::EVENT);
        $this->assertSame(1, count($this->getJson('/edge/local/pos/purchase-returns/options')->assertOk()->json('recent_events')));
    }

    public function test_permissions_are_canonical_a_cashier_gets_nothing_and_a_store_only_user_cannot_post(): void
    {
        $this->actingAs(User::on('tenant')->find($this->cashierId), 'tenant');
        $this->assertStringNotContainsString('id="purchase-returns-link"', $this->get('/edge/local/pos')->assertOk()->getContent());
        $this->get('/edge/local/pos/purchase-returns')->assertStatus(403);
        $this->getJson('/edge/local/pos/purchase-returns/options')->assertStatus(403);
        $this->getJson('/edge/local/pos/purchase-returns/grns/' . $this->prGrnId)->assertStatus(403);
        $this->postJson('/edge/local/pos/purchase-returns', ['cloud_grn_id' => $this->prGrnId, 'reason_code' => 'damaged', 'lines' => [['cloud_grn_line_id' => $this->prGrnLineId, 'quantity' => 1]]])->assertStatus(403);

        $this->actingAs(User::on('tenant')->find($this->storeOnlyId), 'tenant');
        $this->assertStringContainsString('id="purchase-returns-link"', $this->get('/edge/local/pos')->assertOk()->getContent());
        $this->getJson('/edge/local/pos/purchase-returns/options')->assertOk()->assertJsonPath('permissions.can_post', false);
        $this->postJson('/edge/local/pos/purchase-returns', ['cloud_grn_id' => $this->prGrnId, 'reason_code' => 'damaged', 'lines' => [['cloud_grn_line_id' => $this->prGrnLineId, 'quantity' => 1]]])->assertStatus(403);
        $this->assertSame(0, DB::table('edge_sync_outbox')->count());
        $this->assertSame(20.0, (float) DB::table('edge_operational_stock_balances')->sum('quantity_on_hand'));
    }
}
