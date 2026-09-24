<?php

namespace Tests\MySql;

use App\Models\Tenant\User;
use App\Services\Edge\EdgeLocalPurchaseReturnService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\EdgePurchaseReturnFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * W4 (Team 4) — R9.4 Purchase Returns list + detail on the Branch Server over the REAL routes (Online
 * PurchaseReturnController@index/show; `tenant.purchase-returns.index` / `.show`). No Edit / Cancel Draft (R9.2 owner-dependent).
 */
class EdgePurchaseReturnHttpListsMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;
    use EdgePurchaseReturnFixture;

    private int $branchId;
    private int $terminalId;
    private int $operatorId;

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
        $this->operatorId = $this->makeUser(['name' => 'Store Keeper Ali', 'default_branch_id' => $this->branchId, 'employee_code' => 'OP' . Str::random(4)]);
        $this->terminalId = $this->makeTerminal($this->branchId, ['name' => 'Counter A']);
        $this->makePaymentMethod(['method_type' => 'cash']);
        $unit = DB::table('units')->insertGetId(['code' => 'pc', 'name' => 'Piece', 'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $product = $this->makeProduct($this->makeCategory(), ['name' => 'Raw Item', 'sku' => 'RAW-1', 'unit_id' => $unit, 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_purchasable' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 500]);
        $this->seedCloudPurchaseReturnTruth($this->branchId, $product, $unit);
        $this->bindEdgeLocalMeta($this->branchId, 1, deviceUuid: 'pr-lists-box');
        DB::table('edge_local_meta')->update(['bootstrap_schema' => config('edge.bootstrap_schema'), 'config_schema_version' => config('edge.config_schema'), 'authority_last_ack_at' => now()->subMinute()]);
        $this->acceptTestBaseline([['product_id' => $product, 'product_variant_id' => null, 'quantity' => 20]]);
        $this->seedEdgeCredential($this->operatorId, $this->branchId, 1);
        foreach ([EdgeLocalPurchaseReturnService::PERM_STORE, EdgeLocalPurchaseReturnService::PERM_POST, 'tenant.purchase-returns.index', 'tenant.purchase-returns.show'] as $p) {
            $this->grantEdgePermission($this->operatorId, $p);
        }
        $this->projectPurchaseReturnsToAppliance($this->branchId);
        $this->login();
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

    private function login(): void
    {
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs(User::on('tenant')->find($this->operatorId), 'tenant');
        Auth::shouldUse('tenant');
    }

    public function test_purchase_return_list_and_detail_screens(): void
    {
        $ev = $this->postJson('/edge/local/pos/purchase-returns', ['cloud_grn_id' => $this->prGrnId, 'reason_code' => 'damaged', 'notes' => 'crushed box',
            'lines' => [['cloud_grn_line_id' => $this->prGrnLineId, 'quantity' => 2]]])->assertStatus(201)->json('event');

        $list = $this->get('/edge/local/pos/purchase-return-list')->assertOk()->getContent();
        foreach (['id="purchase-return-table"', 'Return No', 'Source GRN', 'Lines', '600.00', 'PENDING', 'Store Keeper Ali', 'id="purchase-return-view-' . $ev['event_uuid'] . '"', 'id="supplier_id"'] as $n) {
            $this->assertStringContainsString($n, $list, "the purchase-return list must carry {$n}");
        }
        $this->assertStringNotContainsString('600.00', $this->get('/edge/local/pos/purchase-return-list?supplier_id=999999')->assertOk()->getContent(), 'supplier filter');
        $show = $this->get('/edge/local/pos/purchase-return-list/' . $ev['event_uuid'])->assertOk()->getContent();
        foreach (['Source GRN', 'Damaged', 'crushed box', 'RAW-1 — Raw Item', '2.000', '300.0000', 'Grand Total', '600.00', 'PENDING SYNC'] as $n) {
            $this->assertStringContainsString($n, $show, "the purchase-return detail must carry {$n}");
        }
        $this->assertStringNotContainsString('Edit Draft', $show);
        $this->assertStringNotContainsString('Cancel Draft', $show);

        $revoke = function (string $p) {
            DB::table('model_has_permissions')->where('model_id', $this->operatorId)->where('permission_id', DB::table('permissions')->where('name', $p)->value('id'))->delete();
            $this->login();
        };
        $revoke('tenant.purchase-returns.show');
        $this->get('/edge/local/pos/purchase-return-list/' . $ev['event_uuid'])->assertForbidden();
        $revoke('tenant.purchase-returns.index');
        $this->get('/edge/local/pos/purchase-return-list')->assertForbidden();
    }
}
