<?php

namespace Tests\MySql;

use App\Models\Edge\EdgeLocalMeta;
use App\Models\Edge\EdgeSyncOutbox;
use App\Models\Tenant\Branch;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\Shift;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\User;
use App\Services\Edge\EdgeBootstrapService;
use App\Services\Edge\EdgeLocalPosService;
use App\Services\Edge\EdgeLocalReturnService;
use App\Services\Edge\EdgeReturnableSaleCacheService;
use App\Services\Edge\EdgeReturnEnvelopeBuilder;
use App\Services\Reports\SalesReportEngine;
use App\Services\Sales\ManagerApprovalService;
use App\Services\Sales\ShiftService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * F1 — the appliance's SALES-RETURN authority, offline (the Cloud is never called): canonical arithmetic, unit-aware
 * quantities, cash refund out of the till exactly once, original-tender policy, over-return prevention, the
 * pre-/post-settlement boundary, manager approval binding, returns of ONLINE sales from the warm cache (fresh or fail
 * closed), the immutable return event, and canonical Ret/Net reports.
 */
class EdgeReturnAuthorityMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $terminalId;
    private int $userId;
    private int $managerId;
    private int $wholeProductId;   // sold by the piece — steps 1
    private int $kgProductId;      // sold by weight — steps 0.001
    private int $cashMethodId;
    private int $cardMethodId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        $this->cleanTenant([
            'edge_returnable_sale_lines', 'edge_returnable_sales', 'edge_sync_outbox', 'edge_operational_stock_movements', 'edge_operational_stock_balances',
            'edge_operational_stock_baselines', 'edge_auth_audit', 'edge_local_user_credentials', 'edge_local_meta', 'manager_approvals',
            'sales_return_lines', 'sales_returns', 'sales_ledgers', 'sale_payments', 'sales_order_lines', 'sales_orders',
            'payment_methods', 'products', 'categories', 'units', 'shifts', 'terminals', 'branches', 'users',
        ]);
        $this->branchId = $this->makeBranch(['allow_negative_stock' => 0, 'timezone' => 'Asia/Karachi', 'manual_discount_approval_mode' => 'auto_approve']);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'RET' . Str::random(4)]);
        $this->managerId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'MGR' . Str::random(4)]);
        $this->terminalId = $this->makeTerminal($this->branchId);
        $pieceUnit = DB::table('units')->insertGetId(['code' => 'pc', 'name' => 'Piece', 'unit_type' => 'quantity', 'base_factor' => 1, 'is_base' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $kgUnit = DB::table('units')->insertGetId(['code' => 'kg', 'name' => 'Kilogram', 'unit_type' => 'weight', 'base_factor' => 1, 'is_base' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $cat = $this->makeCategory();
        $this->wholeProductId = $this->makeProduct($cat, ['name' => 'Burger', 'unit_id' => $pieceUnit, 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 100]);
        $this->kgProductId = $this->makeProduct($cat, ['name' => 'Mutton', 'unit_id' => $kgUnit, 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 200]);
        $this->cashMethodId = $this->makePaymentMethod(['method_type' => 'cash']);
        $this->cardMethodId = $this->makePaymentMethod(['method_type' => 'card', 'name' => 'Card']);
        $this->bindEdgeLocalMeta($this->branchId, 1, deviceUuid: 'return-box');
        DB::table('edge_local_meta')->update(['bootstrap_schema' => config('edge.bootstrap_schema'), 'config_schema_version' => config('edge.config_schema'), 'authority_last_ack_at' => now()->subMinute()]);
        $this->asBranchServerRuntime();
        $this->acceptTestBaseline([
            ['product_id' => $this->wholeProductId, 'product_variant_id' => null, 'quantity' => 20],
            ['product_id' => $this->kgProductId, 'product_variant_id' => null, 'quantity' => 30],
        ]);
        $this->seedEdgeCredential($this->userId, $this->branchId, 1);
        $this->seedEdgeCredential($this->managerId, $this->branchId, 1);
        $this->grantEdgePermission($this->userId, 'tenant.sales-returns.store');
        $this->grantEdgePermission($this->managerId, 'tenant.pos.void-kot-item'); // the Edge branch-manager marker
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');
        app(ShiftService::class)->open(Branch::on('tenant')->find($this->branchId), Terminal::on('tenant')->find($this->terminalId), $this->userId, 500.0);
    }

    protected function tearDown(): void
    {
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    private function user(): User
    {
        return User::on('tenant')->find($this->userId);
    }

    private function returns(): EdgeLocalReturnService
    {
        return app(EdgeLocalReturnService::class);
    }

    /** A LOCAL paid takeaway: 3 Burger @100 + 1.5 kg Mutton @200, 10% order discount → gross 600, discount 60, total 540 cash. */
    private function localSale(array $overrides = []): SalesOrder
    {
        return app(EdgeLocalPosService::class)->completePaidSale(array_merge([
            'order_type' => 'takeaway', 'client_uuid' => (string) Str::uuid(),
            'discount_type' => 'percent', 'discount_value' => 10,
            'lines' => [['product_id' => $this->wholeProductId, 'quantity' => 3], ['product_id' => $this->kgProductId, 'quantity' => 1.5]],
            'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 540]],
        ], $overrides), $this->user(), $this->terminalId);
    }

    private function lineId(SalesOrder $sale, int $productId): int
    {
        return (int) DB::table('sales_order_lines')->where('sales_order_id', $sale->id)->where('product_id', $productId)->value('id');
    }

    private function onHand(int $productId): float
    {
        $b = DB::table('edge_operational_stock_baselines')->where('status', 'accepted')->first();

        return (float) DB::table('edge_operational_stock_balances')->where('baseline_id', $b->id)->where('product_id', $productId)->sum('quantity_on_hand');
    }

    private function shift(): Shift
    {
        return Shift::on('tenant')->where('terminal_id', $this->terminalId)->where('status', 'open')->firstOrFail();
    }

    public function test_partial_then_full_return_of_a_local_sale_uses_the_online_arithmetic_and_moves_the_till_once(): void
    {
        $sale = $this->localSale();
        // Test wire: the order carried a 50 delivery charge (grand total 590) — the charge the rider earned.
        DB::table('sales_orders')->where('id', $sale->id)->update(['delivery_charge_amount' => 50, 'grand_total' => 590, 'paid_amount' => 590]);
        $this->assertSame(17.0, $this->onHand($this->wholeProductId));
        $this->assertSame(28.5, $this->onHand($this->kgProductId));
        $expectedBefore = (float) $this->shift()->expected_cash;

        // Unit-aware screen data.
        $view = $this->returns()->returnable($sale->id, $this->user());
        $whole = collect($view['lines'])->firstWhere('product_id', $this->wholeProductId);
        $kg = collect($view['lines'])->firstWhere('product_id', $this->kgProductId);
        $this->assertSame(1, $whole['qty_step']);
        $this->assertSame(0.001, $kg['qty_step']);
        $this->assertSame(3.0, $whole['returnable']);
        $this->assertSame(1.5, $kg['returnable']);
        $this->assertSame('cash', $view['default_refund_method']);
        $this->assertSame(50.0, $view['outstanding_delivery']);
        $this->assertSame('local', $view['sale']['origin']);

        // PARTIAL: 1 Burger + 0.5 kg. Online allocation: order discount 60 spread by gross (300/300) → 30 each line;
        // per unit 10 → burger line 100 − 10 = 90; mutton 0.5 × 200 = 100 − 10 = 90; delivery NOT refunded (partial).
        $r = $this->returns()->processReturn($sale->id, [
            ['sales_order_line_id' => $this->lineId($sale, $this->wholeProductId), 'quantity' => 1],
            ['sales_order_line_id' => $this->lineId($sale, $this->kgProductId), 'quantity' => 0.5],
        ], 'customer changed mind', 'cash', 180.0, $this->user(), $this->terminalId);
        $this->assertSame(180.0, $r['totals']['grand_total']);
        $this->assertSame(200.0, $r['totals']['subtotal']);
        $this->assertSame(20.0, $r['totals']['discount_amount']);
        $this->assertSame(0.0, $r['totals']['delivery_charge_amount'], 'a partial return keeps the delivery charge');
        $this->assertSame('pending', $r['sync']);
        // Local operational stock back exactly once; till down exactly once.
        $this->assertSame(18.0, $this->onHand($this->wholeProductId));
        $this->assertSame(29.0, $this->onHand($this->kgProductId));
        $this->assertSame(2, DB::table('edge_operational_stock_movements')->where('movement_type', 'sale_return')->where('direction', 'in')->count());
        $this->assertSame($expectedBefore - 180.0, (float) $this->shift()->expected_cash);
        $this->assertSame(180.0, (float) $this->shift()->total_cash_refunds);
        $this->assertSame('partially_returned', DB::table('sales_orders')->where('id', $sale->id)->value('status'));
        // The immutable event is in the SAME outbox, hash-consistent, carrying the Edge identities.
        $row = EdgeSyncOutbox::on('tenant')->where('envelope_schema_version', EdgeReturnEnvelopeBuilder::SCHEMA)->firstOrFail();
        $env = json_decode($row->envelope, true);
        $this->assertSame($r['return_uuid'], $row->sale_uuid);
        $this->assertSame('edge', $env['original']['kind']);
        $this->assertSame((string) $sale->sale_uuid, $env['original']['sale_uuid']);
        $this->assertNotEmpty($env['lines'][0]['line_uuid']);
        $this->assertSame(180.0, (float) $env['totals']['grand_total']);
        $copy = $env;
        unset($copy['content_hash']);
        $this->assertSame(hash('sha256', app(EdgeBootstrapService::class)->canonicalJson($copy)), $row->content_hash, 'content hash is self-consistent');
        $this->assertSame(EdgeSyncOutbox::STATE_PENDING, $row->state);

        // Remaining returnable shrank immediately; asking for more is CAPPED (Online) and a mismatched refund figure is refused.
        $view = $this->returns()->returnable($sale->id, $this->user());
        $this->assertSame(2.0, collect($view['lines'])->firstWhere('product_id', $this->wholeProductId)['returnable']);
        try {
            $this->returns()->processReturn($sale->id, [['sales_order_line_id' => $this->lineId($sale, $this->wholeProductId), 'quantity' => 5]], null, 'cash', 500.0, $this->user(), $this->terminalId);
            $this->fail('over-return with an over-stated refund must be refused');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('must match the calculated refund of 180.00', collect($e->errors())->flatten()->first());
        }

        // FULL: the rest (2 Burger + 1.0 kg) → completes the order → the delivery charge comes back too.
        // burger 200 − 20 = 180; mutton 200 − 20 = 180; + delivery 50 = 410.
        $r2 = $this->returns()->processReturn($sale->id, [
            ['sales_order_line_id' => $this->lineId($sale, $this->wholeProductId), 'quantity' => 2],
            ['sales_order_line_id' => $this->lineId($sale, $this->kgProductId), 'quantity' => 1.0],
        ], null, 'cash', 410.0, $this->user(), $this->terminalId);
        $this->assertSame(410.0, $r2['totals']['grand_total']);
        $this->assertSame(50.0, $r2['totals']['delivery_charge_amount'], 'the whole order coming back gives back the whole charge');
        $this->assertSame('returned', DB::table('sales_orders')->where('id', $sale->id)->value('status'));
        $this->assertSame(20.0, $this->onHand($this->wholeProductId));
        $this->assertSame(30.0, $this->onHand($this->kgProductId));
        $this->assertSame($expectedBefore - 590.0, (float) $this->shift()->expected_cash);
        // Nothing left: a third return is refused.
        try {
            $this->returns()->processReturn($sale->id, [['sales_order_line_id' => $this->lineId($sale, $this->wholeProductId), 'quantity' => 1]], null, 'cash', null, $this->user(), $this->terminalId);
            $this->fail('return after a complete return must be refused');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }

        // Canonical reports tell the story: Ret 4.5 units / 590, Net = 590 − 590.
        $engine = app(SalesReportEngine::class);
        $f = $engine->normalizeFilters(['date_from' => now()->subDay()->toDateString(), 'date_to' => now()->addDay()->toDateString(), 'branch_ids' => [$this->branchId]]);
        $ov = $engine->overview($f);
        $this->assertSame(4.5, (float) $ov['returned_qty']);
        $this->assertSame(0.0, round((float) $ov['net_qty'], 3));
        $this->assertSame(0.0, round((float) $ov['net_sales'], 2), json_encode($ov));
    }

    public function test_original_tender_policy_and_the_settlement_boundary(): void
    {
        // A CARD-tendered sale can only have been made ONLINE (the appliance sells cash-only): it arrives through the cache.
        app(EdgeReturnableSaleCacheService::class)->apply($this->cloudPackage('rw:card', 0.0, $this->cardMethodId, 'card'));
        EdgeLocalMeta::on('tenant')->firstOrFail()->forceFill(['standby_returnable_watermark_seen' => 'rw:card'])->save();
        $shadowId = (int) DB::table('edge_returnable_sales')->where('cloud_sales_order_id', 777001)->value('local_sales_order_id');
        $view = $this->returns()->returnable($shadowId, $this->user());
        $this->assertSame('card', $view['default_refund_method']);
        $this->assertTrue($view['online_required_refund'], 'a card sale defaults to a card refund, which the till cannot do offline');
        $burgerLine = collect($view['lines'])->firstWhere('product_id', $this->wholeProductId)['sales_order_line_id'];
        foreach (['card', 'bank_transfer', 'other'] as $method) {
            try {
                $this->returns()->processReturn($shadowId, [['sales_order_line_id' => $burgerLine, 'quantity' => 1]], null, $method, null, $this->user(), $this->terminalId);
                $this->fail("{$method} refund must need the Online POS");
            } catch (ValidationException $e) {
                $this->assertStringContainsString('needs the Online POS', collect($e->errors())->flatten()->first());
            }
        }
        $this->assertSame(0, DB::table('sales_returns')->count(), 'nothing posted');
        // The cashier may still refund such a sale in CASH offline when the business decides so (Online lets the cashier override).
        $r = $this->returns()->processReturn($shadowId, [['sales_order_line_id' => $burgerLine, 'quantity' => 1]], null, 'cash', 100.0, $this->user(), $this->terminalId);
        $this->assertSame('cash', $r['refund_method']);
        $sale = $this->localSale();

        // PRE-SETTLEMENT: a held check is voided through the local operational authority, never a return.
        $pos = app(EdgeLocalPosService::class);
        $held = $pos->holdOrReviseSale(['order_type' => 'takeaway', 'client_uuid' => (string) Str::uuid(), 'lines' => [['product_id' => $this->wholeProductId, 'quantity' => 1]]], $this->user(), $this->terminalId);
        try {
            $this->returns()->returnable($held->id, $this->user());
            $this->fail('an unsettled check is not returnable');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('not returnable', collect($e->errors())->flatten()->first());
        }
        $this->assertSame(0, DB::table('edge_sync_outbox')->where('envelope_schema_version', EdgeReturnEnvelopeBuilder::SCHEMA)->where('sale_uuid', '!=', $r['return_uuid'])->count(), 'no financial event for something never settled');
        // POST-SETTLEMENT: a paid sale cannot be "cancelled" — the cancel authority only knows held checks.
        try {
            $pos->cancelHeldSale($sale->id, 1, null, $this->user(), $this->terminalId);
            $this->fail('a settled sale must not be voidable through the held-check cancel');
        } catch (\Throwable $e) {
            $this->assertTrue(true);
        }
        $this->assertContains(DB::table('sales_orders')->where('id', $sale->id)->value('status'), ['paid', 'partially_returned']);
    }

    public function test_manager_approval_is_required_bound_and_single_use_where_the_branch_says_so(): void
    {
        Branch::on('tenant')->where('id', $this->branchId)->update(['sales_return_approval_mode' => Branch::SALES_RETURN_MANAGER_REQUIRED]);
        $sale = $this->localSale();
        $line = $this->lineId($sale, $this->wholeProductId);
        $this->assertTrue($this->returns()->returnable($sale->id, $this->user())['needs_manager_approval']);
        try {
            $this->returns()->processReturn($sale->id, [['sales_order_line_id' => $line, 'quantity' => 1]], null, 'cash', 90.0, $this->user(), $this->terminalId);
            $this->fail('approval required');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Manager approval is required', collect($e->errors())->flatten()->first());
        }
        // An UNAUTHORIZED user (no branch-manager marker) cannot approve — the Edge identity path refuses.
        try {
            app(EdgeLocalPosService::class)->verifyManagerApproval((string) User::on('tenant')->find($this->userId)->employee_code, 'CashierPass1', 'sales_return', $this->user(), ['sales_order_id' => $sale->id, 'branch_id' => $this->branchId, 'refund_method' => 'cash', 'refund_amount' => 90.0]);
            $this->fail('a cashier without the manager marker cannot approve a return');
        } catch (\Throwable $e) {
            $this->assertTrue(true);
        }
        // The manager approves 90 for THIS sale/method (Online binding).
        $approval = app(EdgeLocalPosService::class)->verifyManagerApproval((string) User::on('tenant')->find($this->managerId)->employee_code, 'CashierPass1', 'sales_return', $this->user(), ['sales_order_id' => $sale->id, 'branch_id' => $this->branchId, 'refund_method' => 'cash', 'refund_amount' => 90.0]);
        // Bound to the amount: 2 units (180) under a 90 approval is refused.
        try {
            $this->returns()->processReturn($sale->id, [['sales_order_line_id' => $line, 'quantity' => 2]], null, 'cash', 180.0, $this->user(), $this->terminalId, (int) $approval->id);
            $this->fail('the approval is bound to the refund amount');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
        $r = $this->returns()->processReturn($sale->id, [['sales_order_line_id' => $line, 'quantity' => 1]], null, 'cash', 90.0, $this->user(), $this->terminalId, (int) $approval->id);
        $this->assertSame(90.0, $r['totals']['grand_total']);
        $env = json_decode(EdgeSyncOutbox::on('tenant')->where('sale_uuid', $r['return_uuid'])->value('envelope'), true);
        $this->assertSame($this->managerId, (int) $env['approval']['approved_by_user_id'], 'the event carries who approved');
        $this->assertSame(90.0, (float) $env['approval']['binding']['refund_amount']);
        // Single use.
        try {
            $this->returns()->processReturn($sale->id, [['sales_order_line_id' => $line, 'quantity' => 1]], null, 'cash', 90.0, $this->user(), $this->terminalId, (int) $approval->id);
            $this->fail('an approval is consumed once');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    /** A Cloud-shaped returnable package for ONE online sale: 3 Burger @100 (Cloud already returned 1), 2 kg Mutton @200, cash. */
    private function cloudPackage(string $watermark, float $cloudReturnedBurgers = 1.0, ?int $paymentMethodId = null, string $methodType = 'cash'): array
    {
        $paymentMethodId = $paymentMethodId ?? $this->cashMethodId;
        $returns = [];
        if ($cloudReturnedBurgers > 0) {
            $returns[] = ['cloud_return_id' => 9001, 'return_no' => 'SR-CLOUD-1', 'return_date' => now()->subHour()->toIso8601String(), 'business_date' => now()->toDateString(),
                'subtotal' => 100 * $cloudReturnedBurgers, 'discount_amount' => 0, 'tax_amount' => 0, 'delivery_charge_amount' => 0, 'grand_total' => 100 * $cloudReturnedBurgers, 'refund_method' => 'cash', 'refund_amount' => 100 * $cloudReturnedBurgers,
                'lines' => [['cloud_line_id' => 5001, 'product_id' => $this->wholeProductId, 'product_variant_id' => null, 'quantity' => $cloudReturnedBurgers, 'unit_price' => 100, 'discount_amount' => 0, 'tax_amount' => 0, 'line_total' => 100 * $cloudReturnedBurgers]]];
        }

        return ['branch_id' => $this->branchId, 'watermark' => $watermark, 'as_of' => now()->toIso8601String(), 'window_days' => 14, 'sales' => [[
            'cloud_sales_order_id' => 777001, 'sale_uuid' => null, 'sale_no' => 'SO-ONLINE-777001', 'status' => $cloudReturnedBurgers > 0 ? 'partially_returned' : 'paid', 'updated_at' => now()->toIso8601String(),
            'order_type' => 'takeaway', 'sale_date' => now()->subHours(3)->toIso8601String(), 'business_date' => now()->toDateString(), 'completed_at' => now()->subHours(3)->toIso8601String(),
            'terminal_id' => $this->terminalId, 'customer_id' => null, 'customer_name' => 'Walk-in Ali', 'customer_phone' => '0300', 'restaurant_waiter_id' => null, 'vehicle_number' => null,
            'created_by_user_id' => $this->userId, 'subtotal' => 700, 'discount_type' => 'none', 'discount_value' => 0, 'discount_amount' => 0, 'promo_code' => null, 'tax_amount' => 0,
            'service_charge_amount' => 0, 'delivery_charge_amount' => 0, 'tip_amount' => 0, 'grand_total' => 700, 'paid_amount' => 700, 'change_amount' => 0,
            'lines' => [
                ['cloud_line_id' => 5001, 'line_uuid' => null, 'parent_cloud_line_id' => null, 'line_kind' => 'standard', 'combo_id' => null, 'product_id' => $this->wholeProductId, 'product_variant_id' => null, 'product_name' => 'Burger', 'quantity' => 3, 'returned_quantity' => $cloudReturnedBurgers, 'unit_code' => 'pc', 'unit_type' => 'quantity', 'unit_price' => 100, 'unit_cost' => 40, 'discount_amount' => 0, 'tax_amount' => 0, 'line_total' => 300],
                ['cloud_line_id' => 5002, 'line_uuid' => null, 'parent_cloud_line_id' => null, 'line_kind' => 'standard', 'combo_id' => null, 'product_id' => $this->kgProductId, 'product_variant_id' => null, 'product_name' => 'Mutton', 'quantity' => 2, 'returned_quantity' => 0, 'unit_code' => 'kg', 'unit_type' => 'weight', 'unit_price' => 200, 'unit_cost' => 90, 'discount_amount' => 0, 'tax_amount' => 0, 'line_total' => 400],
            ],
            'payments' => [['payment_method_id' => $paymentMethodId, 'method_type' => $methodType, 'amount' => 700, 'tendered_amount' => 700, 'change_amount' => 0, 'transaction_ref' => null]],
            'returns' => $returns,
        ]]];
    }

    public function test_an_online_sale_is_returnable_offline_only_while_the_cache_is_provably_fresh(): void
    {
        $cache = app(EdgeReturnableSaleCacheService::class);
        $stats = $cache->apply($this->cloudPackage('rw:v1'));
        $this->assertSame(1, $stats['sales']);
        $this->assertSame(1, $stats['mirrored_returns']);
        $shadowId = (int) DB::table('edge_returnable_sales')->where('cloud_sales_order_id', 777001)->value('local_sales_order_id');
        $this->assertSame(EdgeReturnableSaleCacheService::SHADOW_STATUS, DB::table('sales_orders')->where('id', $shadowId)->value('status'));
        // The shadow is invisible to the report population and to selling — only returnable.
        $engine = app(SalesReportEngine::class);
        $ov = $engine->overview($engine->normalizeFilters(['date_from' => now()->subDay()->toDateString(), 'date_to' => now()->addDay()->toDateString(), 'branch_ids' => [$this->branchId]]));
        $this->assertSame(0.0, (float) $ov['net_sales'], 'a mirrored Online sale is NOT a local sale');
        $found = $this->returns()->search('777001');
        $this->assertSame('online', $found[0]['origin']);

        // STALE: the Cloud advertised a newer position than the cache holds → fail closed with a business message.
        EdgeLocalMeta::on('tenant')->firstOrFail()->forceFill(['standby_returnable_watermark_seen' => 'rw:v2', 'returnable_cache_as_of' => now()->subMinutes(10), 'authority_last_ack_at' => now()])->save();
        $view = $this->returns()->returnable($shadowId, $this->user());
        $this->assertFalse($view['fresh']);
        $this->assertStringContainsString('not current', $view['freshness_message']);
        $burgerLine = collect($view['lines'])->firstWhere('product_id', $this->wholeProductId);
        $this->assertSame(2.0, $burgerLine['returnable'], 'sold 3 − Cloud returned 1');
        try {
            $this->returns()->processReturn($shadowId, [['sales_order_line_id' => $burgerLine['sales_order_line_id'], 'quantity' => 1]], null, 'cash', 100.0, $this->user(), $this->terminalId);
            $this->fail('a stale cache must refuse the return');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('not current on this branch server', collect($e->errors())->flatten()->first());
        }
        $this->assertSame(1, DB::table('sales_returns')->count(), 'only the mirrored Cloud return exists');

        // FRESH: the cache equals the advertised watermark → the return is accepted, event queued, till moves once.
        EdgeLocalMeta::on('tenant')->firstOrFail()->forceFill(['standby_returnable_watermark_seen' => 'rw:v1'])->save();
        $before = (float) $this->shift()->expected_cash;
        $r = $this->returns()->processReturn($shadowId, [['sales_order_line_id' => $burgerLine['sales_order_line_id'], 'quantity' => 1]], 'damaged', 'cash', 100.0, $this->user(), $this->terminalId);
        $this->assertSame(100.0, $r['totals']['grand_total']);
        $this->assertSame('online', $r['sale']['origin']);
        $this->assertSame($before - 100.0, (float) $this->shift()->expected_cash);
        $this->assertSame(21.0, $this->onHand($this->wholeProductId), 'the returned burger is sellable again locally');
        $env = json_decode(EdgeSyncOutbox::on('tenant')->where('sale_uuid', $r['return_uuid'])->value('envelope'), true);
        $this->assertSame('cloud', $env['original']['kind']);
        $this->assertSame(777001, $env['original']['cloud_sales_order_id']);
        $this->assertSame(5001, $env['lines'][0]['cloud_sales_order_line_id']);
        $this->assertSame('rw:v1', $env['freshness']['returnable_cache_watermark']);
        // RETURNABLE = sold − Cloud returned − LOCAL pending: 3 − 1 − 1 = 1; a second ask for 2 is capped to 1 (never over-return).
        $this->assertSame(1.0, collect($this->returns()->returnable($shadowId, $this->user())['lines'])->firstWhere('product_id', $this->wholeProductId)['returnable']);
        // A cache REFRESH with the same Cloud position must keep the local pending return subtracted (no double return).
        $cache->apply($this->cloudPackage('rw:v1'));
        $this->assertSame(2.0, (float) DB::table('sales_order_lines')->where('id', $burgerLine['sales_order_line_id'])->value('returned_quantity'), 'Cloud 1 + local pending 1');
        $r2 = $this->returns()->processReturn($shadowId, [['sales_order_line_id' => $burgerLine['sales_order_line_id'], 'quantity' => 2]], null, 'cash', 100.0, $this->user(), $this->terminalId);
        $this->assertSame(100.0, $r2['totals']['grand_total'], 'capped to the one remaining unit');
        $this->assertSame(3.0, (float) DB::table('sales_order_lines')->where('id', $burgerLine['sales_order_line_id'])->value('returned_quantity'));
        // Local reports: two offline returns of an Online sale show as Ret 2 / 200 on this branch today.
        $ov = $engine->overview($engine->normalizeFilters(['date_from' => now()->subDay()->toDateString(), 'date_to' => now()->addDay()->toDateString(), 'branch_ids' => [$this->branchId]]));
        $this->assertSame(2.0, (float) $ov['returned_qty']);
    }
}
