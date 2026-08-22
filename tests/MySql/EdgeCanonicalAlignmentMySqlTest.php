<?php

namespace Tests\MySql;

use App\Models\Edge\EdgeSyncOutbox;
use App\Models\Master\Tenant;
use App\Models\Tenant\Branch;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\User;
use App\Services\Edge\EdgeBootstrapService;
use App\Services\Edge\EdgeLocalPosService;
use App\Services\Edge\EdgePairingService;
use App\Services\Edge\OfflineEdgeEntitlementService;
use App\Services\Sales\ShiftService;
use App\Services\Tenancy\TenancyManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * EDGE canonical-alignment Batch 1 (docs/status/edge-canonical-gap-2026-08-23.md) — the offline
 * behaviours that must match modern canonical, proven through the REAL Edge runtime:
 *   • POS-DRAFT-1 offline: a draft is a held sale that never queues a KOT; promoting it (normal hold)
 *     clears the flag and the KOT then fires exactly once; settling clears the flag too.
 *   • REPORT-BUSINESS-DATE-1: an offline item-void carries the ORDER's business_date.
 *   • Catalog: a service (consumption 'none') product never decrements operational stock.
 *   • PHASE 2b: a quick sale captures a branch-validated waiter, carried in the sync envelope.
 *   • Bootstrap export carries the new routing/layout config columns (terminal_id + layout rows).
 */
class EdgeCanonicalAlignmentMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $terminalId;
    private int $userId;
    private int $productId;
    private int $serviceProductId;
    private int $cashMethodId;
    private int $waiterId;
    private int $baselineId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        $this->cleanTenant([
            'edge_sync_outbox',
            'edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines',
            'edge_auth_audit', 'edge_local_user_credentials', 'edge_local_meta',
            'sales_order_line_cancellations', 'print_jobs', 'kot_batch_lines', 'kot_batches',
            'sales_ledgers', 'sale_payments', 'sales_order_lines', 'sales_orders',
            'restaurant_table_sessions', 'restaurant_tables', 'restaurant_floors', 'restaurant_waiters',
            'category_printer_mappings', 'receipt_layout_settings', 'printers', 'void_reasons',
            'model_has_permissions', 'permissions',
            'payment_methods', 'products', 'categories', 'shifts', 'terminals', 'branches', 'users',
        ]);
        $this->branchId = $this->makeBranch(['allow_negative_stock' => 0, 'timezone' => 'Asia/Karachi', 'held_kot_cancellation_approval_mode' => 'auto_approve', 'held_kot_line_cancellation_approval_mode' => 'auto_approve']);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'CASH' . Str::random(4)]);
        $this->terminalId = $this->makeTerminal($this->branchId);
        $cat = $this->makeCategory();
        $this->productId = $this->makeProduct($cat, ['inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 100]);
        $this->serviceProductId = $this->makeProduct($cat, ['product_type' => 'service', 'product_kind' => 'service', 'inventory_consumption_method' => 'none', 'is_stock_tracked' => 0, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 50]);
        $this->cashMethodId = $this->makePaymentMethod(['method_type' => 'cash']);
        $this->waiterId = $this->makeWaiter($this->branchId);
        $this->bindEdgeLocalMeta($this->branchId, 1, 42, 'test-device-uuid', 10);
        $this->asBranchServerRuntime();
        $this->baselineId = (int) $this->acceptTestBaseline([
            ['product_id' => $this->productId, 'product_variant_id' => null, 'quantity' => 50],
        ])->id;
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');
    }

    protected function tearDown(): void
    {
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    private function openShift(): \App\Models\Tenant\Shift
    {
        return app(ShiftService::class)->open(Branch::on('tenant')->find($this->branchId), Terminal::on('tenant')->find($this->terminalId), $this->userId, 0.0);
    }

    private function user(): User
    {
        return User::on('tenant')->find($this->userId);
    }

    private function pos(): EdgeLocalPosService
    {
        return app(EdgeLocalPosService::class);
    }

    private function hold(array $overrides = []): SalesOrder
    {
        return $this->pos()->holdOrReviseSale(array_merge([
            'order_type' => 'takeaway',
            'lines' => [['product_id' => $this->productId, 'quantity' => 2]],
        ], $overrides), $this->user(), $this->terminalId);
    }

    // ── POS-DRAFT-1 offline ───────────────────────────────────────────────────

    public function test_a_draft_never_queues_a_kot_and_promotion_sends_it_exactly_once(): void
    {
        $this->openShift();
        $draft = $this->hold(['save_as_draft' => true]);

        $this->assertSame('held', $draft->status, 'a draft stays a normal held sale');
        $this->assertTrue((bool) $draft->is_draft, 'save_as_draft marks the order a draft');

        // Server-side skip (Edge POS is API-driven): a draft refuses the KOT send — nothing enqueued.
        try {
            $this->pos()->queueKotEvents($draft->id, $this->user(), $this->terminalId);
            $this->fail('a draft must not queue a KOT');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('sale', $e->errors());
        }
        $this->assertSame(0, DB::connection('tenant')->table('kot_batches')->where('sales_order_id', $draft->id)->count());

        // Promote: the same order re-held WITHOUT save_as_draft clears the flag…
        $promoted = $this->hold(['held_sale_id' => $draft->id, 'lines' => [['product_id' => $this->productId, 'quantity' => 2, 'sales_order_line_id' => $draft->lines()->first()->id]]]);
        $this->assertSame($draft->id, $promoted->id);
        $this->assertFalse((bool) $promoted->is_draft, 'a normal Hold clears the draft flag');

        // …and the KOT now fires EXACTLY once (a second send has no unsent delta).
        $this->pos()->queueKotEvents($promoted->id, $this->user(), $this->terminalId);
        $this->pos()->queueKotEvents($promoted->id, $this->user(), $this->terminalId);
        $this->assertSame(1, DB::connection('tenant')->table('kot_batches')->where('sales_order_id', $promoted->id)->count(), 'KOT business event recorded exactly once');
    }

    public function test_settling_a_draft_clears_the_flag_and_yields_a_paid_envelope(): void
    {
        $this->openShift();
        $draft = $this->hold(['save_as_draft' => true]);
        $this->assertTrue((bool) $draft->is_draft);

        $settled = $this->pos()->settleHeldSale($draft->id, [
            'client_uuid' => (string) Str::uuid(),
            'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => (float) $draft->grand_total]],
        ], $this->user(), $this->terminalId);

        $this->assertSame('paid', $settled->status);
        $this->assertFalse((bool) $settled->is_draft, 'a paid order is never a draft (canonical finalize rule)');
        $this->assertSame(1, EdgeSyncOutbox::query()->where('sale_uuid', $settled->sale_uuid)->count(), 'the settled sale has its sync envelope');
    }

    // ── REPORT-BUSINESS-DATE-1 offline voids ──────────────────────────────────

    public function test_an_offline_item_void_carries_the_orders_business_date(): void
    {
        $shift = $this->openShift();
        $sale = $this->hold();
        $this->pos()->queueKotEvents($sale->id, $this->user(), $this->terminalId); // lines are kitchen-sent

        $reasonId = DB::connection('tenant')->table('void_reasons')->insertGetId(['name' => 'Mistake', 'reason_type' => 'cancellation', 'requires_manager_approval' => 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $permId = DB::connection('tenant')->table('permissions')->insertGetId(['name' => 'tenant.pos.void-kot-item', 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now()]);
        DB::connection('tenant')->table('model_has_permissions')->insert(['permission_id' => $permId, 'model_type' => User::class, 'model_id' => $this->userId]);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->pos()->cancelHeldSale($sale->id, $reasonId, null, $this->user());

        $rows = DB::connection('tenant')->table('sales_order_line_cancellations')->where('sales_order_id', $sale->id)->get();
        $this->assertGreaterThan(0, $rows->count(), 'voiding a kitchen-sent held order records cancellations');
        foreach ($rows as $row) {
            $this->assertSame($shift->business_date->toDateString(), (string) $row->business_date, 'the void books to the ORDER business day, not the calendar date');
        }
    }

    // ── Catalog: service / non-stock semantics ────────────────────────────────

    public function test_a_service_product_never_decrements_operational_stock(): void
    {
        $this->openShift();
        $sale = $this->pos()->completePaidSale([
            'order_type' => 'takeaway', 'client_uuid' => (string) Str::uuid(),
            'lines' => [['product_id' => $this->serviceProductId, 'quantity' => 3], ['product_id' => $this->productId, 'quantity' => 1]],
            'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 250]],
        ], $this->user(), $this->terminalId);

        $this->assertSame('paid', $sale->status);
        $movements = DB::connection('tenant')->table('edge_operational_stock_movements')->where('sale_uuid', $sale->sale_uuid)->get();
        $this->assertCount(1, $movements, 'only the stock_item line moves stock');
        $this->assertSame($this->productId, (int) $movements->first()->product_id);
        $this->assertSame(49.0, $this->edgeOnHand($this->baselineId, $this->productId));
    }

    // ── PHASE 2b: quick-sale waiter attribution ───────────────────────────────

    public function test_quick_sale_captures_a_branch_validated_waiter_and_the_envelope_carries_it(): void
    {
        $this->openShift();
        $sale = $this->pos()->completePaidSale([
            'order_type' => 'quick_sale', 'client_uuid' => (string) Str::uuid(), 'vehicle_number' => 'LEA-1',
            'restaurant_waiter_id' => $this->waiterId,
            'lines' => [['product_id' => $this->productId, 'quantity' => 1]],
            'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 100]],
        ], $this->user(), $this->terminalId);

        $this->assertSame($this->waiterId, (int) $sale->restaurant_waiter_id);
        $this->assertSame('LEA-1', $sale->vehicle_number);
        $env = EdgeSyncOutbox::query()->where('sale_uuid', $sale->sale_uuid)->firstOrFail()->envelopeArray();
        $this->assertSame($this->waiterId, (int) $env['restaurant_waiter_id'], 'the envelope carries the quick-sale waiter');

        // A takeaway never carries a vehicle; a foreign/inactive waiter is refused, never silently dropped.
        $takeaway = $this->pos()->completePaidSale([
            'order_type' => 'takeaway', 'client_uuid' => (string) Str::uuid(), 'vehicle_number' => 'LEA-2',
            'lines' => [['product_id' => $this->productId, 'quantity' => 1]],
            'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 100]],
        ], $this->user(), $this->terminalId);
        $this->assertNull($takeaway->vehicle_number);

        $foreignWaiter = $this->makeWaiter($this->makeBranch(['code' => 'B2', 'name' => 'B2']));
        try {
            $this->pos()->completePaidSale([
                'order_type' => 'quick_sale', 'client_uuid' => (string) Str::uuid(), 'vehicle_number' => 'LEA-3',
                'restaurant_waiter_id' => $foreignWaiter,
                'lines' => [['product_id' => $this->productId, 'quantity' => 1]],
                'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 100]],
            ], $this->user(), $this->terminalId);
            $this->fail('another branch\'s waiter must be refused');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('restaurant_waiter_id', $e->errors());
        }
    }

    // ── Bootstrap export: new routing/layout config columns ───────────────────

    public function test_bootstrap_export_carries_terminal_routing_and_layout_row_columns(): void
    {
        $conn = DB::connection('tenant');
        $printerId = $conn->table('printers')->insertGetId(['branch_id' => $this->branchId, 'name' => 'K', 'code' => 'P-K', 'printer_type' => 'network', 'print_role' => 'kot', 'supports_reminder' => 0, 'ip_address' => '10.0.0.2', 'port' => 9100, 'is_default' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $conn->table('category_printer_mappings')->insert(['branch_id' => $this->branchId, 'terminal_id' => $this->terminalId, 'category_id' => null, 'printer_id' => $printerId, 'print_role' => 'kot', 'order_type' => 'all', 'reminder_confirm_on_addition' => 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $conn->table('receipt_layout_settings')->insert(['branch_id' => $this->branchId, 'document_type' => 'kot', 'item_font_size' => 14, 'time_font_size' => 16, 'show_column_dividers' => 1, 'show_category_header' => 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $svc = new class(app(OfflineEdgeEntitlementService::class), app(TenancyManager::class), app(EdgePairingService::class)) extends EdgeBootstrapService {
            public function sectionsFor(Tenant $t, Branch $b): array
            {
                return $this->buildSections($t, $b);
            }
        };
        $tenant = new Tenant(['tenant_code' => 'aligndemo', 'business_name' => 'Demo', 'currency_code' => 'PKR']);
        $tenant->id = 42;
        $sections = $svc->sectionsFor($tenant, Branch::on('tenant')->find($this->branchId));

        $mapping = collect($sections['category_printer_mappings'])->firstWhere('printer_id', $printerId);
        $this->assertSame($this->terminalId, (int) $mapping['terminal_id'], 'terminal-pinned routing replicates');

        $layout = collect($sections['receipt_layout_settings'])->firstWhere('document_type', 'kot');
        $this->assertSame(14, (int) $layout['item_font_size']);
        $this->assertSame(16, (int) $layout['time_font_size']);
        $this->assertSame(1, (int) $layout['show_column_dividers']);
        $this->assertSame(0, (int) $layout['show_category_header']);
    }
}
