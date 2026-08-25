<?php

namespace Tests\MySql;

use App\Models\Edge\EdgeLocalMeta;
use App\Models\Edge\EdgeSyncOutbox;
use App\Models\Tenant\Branch;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\User;
use App\Services\Edge\EdgeLocalPosService;
use App\Services\Edge\EdgeSaleEnvelopeBuilder;
use App\Services\Sales\ShiftService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * OFFLINE-SYNC-ENGINE-1B closure — the sale envelope contract proven through the REAL Edge runtime:
 *   • CROSS-SYSTEM customer identity: walk-in is EXPLICIT; an attached customer travels by its canonical
 *     customer_uuid (+ the sale's own name/phone snapshot), NEVER by a local integer id; a customer without
 *     a valid canonical uuid fails closed.
 *   • Draft/Hold are branch-local operational state: Draft -> no outbox; Draft -> Hold -> no outbox;
 *     settlement -> exactly ONE immutable paid envelope (is_draft=false, final order attribution).
 */
class EdgeSyncEnvelopeContractMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $terminalId;
    private int $userId;
    private int $productId;
    private int $cashMethodId;
    private int $baselineId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        $this->cleanTenant([
            'edge_sync_outbox', 'edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines',
            'edge_auth_audit', 'edge_local_user_credentials', 'edge_local_meta',
            'kot_batch_lines', 'kot_batches', 'sales_ledgers', 'sale_payments', 'sales_order_lines', 'sales_orders',
            'customers', 'payment_methods', 'products', 'categories', 'shifts', 'terminals', 'branches', 'users',
        ]);
        $this->branchId = $this->makeBranch(['allow_negative_stock' => 0, 'timezone' => 'Asia/Karachi']);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'ENV' . Str::random(4)]);
        $this->terminalId = $this->makeTerminal($this->branchId);
        $this->productId = $this->makeProduct($this->makeCategory(), ['inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 100]);
        $this->cashMethodId = $this->makePaymentMethod(['method_type' => 'cash']);
        $this->bindEdgeLocalMeta($this->branchId, 1, 42, 'test-device-uuid', 10);
        $this->asBranchServerRuntime();
        $this->baselineId = (int) $this->acceptTestBaseline([['product_id' => $this->productId, 'product_variant_id' => null, 'quantity' => 50]])->id;
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');
    }

    protected function tearDown(): void
    {
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    private function openShift(): void
    {
        app(ShiftService::class)->open(Branch::on('tenant')->find($this->branchId), Terminal::on('tenant')->find($this->terminalId), $this->userId, 0.0);
    }

    private function user(): User
    {
        return User::on('tenant')->find($this->userId);
    }

    private function pos(): EdgeLocalPosService
    {
        return app(EdgeLocalPosService::class);
    }

    private function takeawaySale(): SalesOrder
    {
        return $this->pos()->completePaidSale([
            'order_type' => 'takeaway', 'client_uuid' => (string) Str::uuid(),
            'lines' => [['product_id' => $this->productId, 'quantity' => 1]],
            'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 100]],
        ], $this->user(), $this->terminalId);
    }

    public function test_envelope_customer_identity_is_explicit_and_never_a_local_pk(): void
    {
        $this->openShift();
        $sale = $this->takeawaySale();

        // Walk-in is EXPLICIT — no local id, a named kind instead.
        $env = EdgeSyncOutbox::query()->where('sale_uuid', $sale->sale_uuid)->firstOrFail()->envelopeArray();
        $this->assertSame('walk_in', $env['customer']['kind']);
        $this->assertArrayNotHasKey('customer_id', $env['customer']);

        // An attached customer travels by canonical customer_uuid (+ the sale's snapshot name), never by id.
        $builder = app(EdgeSaleEnvelopeBuilder::class);
        $meta = EdgeLocalMeta::current();
        $uuid = (string) Str::ulid();
        $customerId = DB::connection('tenant')->table('customers')->insertGetId(['customer_uuid' => $uuid, 'name' => 'Ayesha', 'phone' => '0300-1', 'created_at' => now(), 'updated_at' => now()]);
        DB::connection('tenant')->table('sales_orders')->where('id', $sale->id)->update(['customer_id' => $customerId, 'customer_name' => 'Ayesha K']);
        $built = $builder->build($sale->fresh(), $meta);
        $this->assertSame('customer', $built['customer']['kind']);
        $this->assertSame($uuid, $built['customer']['customer_uuid']);
        $this->assertSame('Ayesha K', $built['customer']['name'], 'the sale snapshot name wins');
        $this->assertArrayNotHasKey('customer_id', $built['customer']);

        // A customer row WITHOUT a valid canonical uuid (legacy/imported) can never be shipped by its local
        // id — the builder fails closed rather than inventing or leaking a PK. (A dangling id is impossible:
        // sales_orders.customer_id is FK-constrained ON DELETE SET NULL.)
        DB::connection('tenant')->table('customers')->where('id', $customerId)->update(['customer_uuid' => 'not-a-ulid']);
        try {
            $builder->build($sale->fresh(), $meta);
            $this->fail('a customer without a canonical uuid must fail closed');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ENVELOPE_UNSUPPORTED', $e->getMessage());
        }
    }

    public function test_draft_and_hold_never_sync_until_settlement_yields_exactly_one_envelope(): void
    {
        $this->openShift();
        $draft = $this->pos()->holdOrReviseSale([
            'order_type' => 'takeaway', 'save_as_draft' => true,
            'lines' => [['product_id' => $this->productId, 'quantity' => 2]],
        ], $this->user(), $this->terminalId);
        $this->assertSame(0, EdgeSyncOutbox::query()->count(), 'Draft -> no outbox');

        $held = $this->pos()->holdOrReviseSale([
            'order_type' => 'takeaway', 'held_sale_id' => $draft->id,
            'lines' => [['product_id' => $this->productId, 'quantity' => 2, 'sales_order_line_id' => $draft->lines()->first()->id]],
        ], $this->user(), $this->terminalId);
        $this->assertFalse((bool) $held->is_draft);
        $this->assertSame(0, EdgeSyncOutbox::query()->count(), 'Draft -> Hold -> still no paid outbox');

        $settled = $this->pos()->settleHeldSale($held->id, [
            'client_uuid' => (string) Str::uuid(),
            'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => (float) $held->fresh()->grand_total]],
        ], $this->user(), $this->terminalId);
        $this->assertSame(1, EdgeSyncOutbox::query()->count(), 'settlement -> exactly ONE immutable paid envelope');
        $env = EdgeSyncOutbox::query()->where('sale_uuid', $settled->sale_uuid)->firstOrFail()->envelopeArray();
        $this->assertFalse($env['local_state']['is_draft']);
        $this->assertSame('takeaway', $env['order_type']);
    }
}
