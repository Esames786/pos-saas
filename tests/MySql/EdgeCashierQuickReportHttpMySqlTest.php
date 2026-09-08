<?php

namespace Tests\MySql;

use App\Models\Tenant\PrintJob;
use App\Models\Tenant\User;
use App\Services\Edge\EdgeLocalPrintDeliveryService;
use App\Services\Reports\SalesReportDocumentService;
use App\Services\Reports\SalesReportEngine;
use App\Support\TenantClock;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * EDGE-CASHIER-UI-5 — the cashier Quick Report on the Branch Server, over REAL HTTP, on the CANONICAL report
 * authority. The oracle in every assertion is the canonical engine/document service itself (never a
 * reconstructed query and never Edge arithmetic):
 *  - VIEW renders the canonical thermal page (NET SALES, category heads, item rows) with QUICK-REPORT-OPEN-BILLS-1
 *    semantics — an open held check appears in the line sections, exactly as include_open defines it, while a
 *    Report-Center-style build (include_open=false) of the same day does not carry it;
 *  - QUICK-REPORT-BRANCH-SCOPE-1 — only the bound branch's trade is on the page;
 *  - NETWORK queues the same report bytes on the Edge print authority;
 *  - EMAIL is truthfully Internet-required (never a fake "sent"); permission is the synced Online permission.
 */
class EdgeCashierQuickReportHttpMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $otherBranchId;
    private int $terminalId;
    private int $userId;
    private int $tableId;
    private int $productP;
    private int $productQ;
    private int $productOther;
    private int $cashMethodId;

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
            'edge_local_print_deliveries', 'edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines',
            'edge_auth_audit', 'edge_local_user_credentials', 'edge_local_meta', 'edge_local_table_reservations', 'edge_sync_outbox',
            'sales_order_line_cancellations', 'kot_batch_lines', 'kot_batches', 'print_jobs',
            'category_printer_mappings', 'terminal_printer_settings', 'printers', 'pos_quick_report_settings',
            'model_has_permissions', 'permissions',
            'restaurant_table_sessions', 'restaurant_tables', 'restaurant_floors', 'restaurant_waiters',
            'sales_returns', 'sales_ledgers', 'cash_bank_account_transactions', 'journal_lines', 'journal_entries',
            'stock_ledgers', 'stock_balances', 'sale_payments', 'sales_order_lines', 'sales_orders',
            'payment_methods', 'combo_components', 'combos', 'products', 'categories', 'shifts', 'terminals', 'branches', 'users',
        ]);

        $this->branchId = $this->makeBranch(['name' => 'Edge Branch', 'allow_negative_stock' => 0, 'timezone' => 'Asia/Karachi']);
        $this->otherBranchId = $this->makeBranch(['name' => 'Other Branch', 'timezone' => 'Asia/Karachi']);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'QRP' . Str::random(4)]);
        $this->terminalId = $this->makeTerminal($this->branchId);
        $this->tableId = $this->makeTable($this->branchId, ['table_no' => 'T1', 'status' => 'available']);
        $karahi = $this->makeCategory(['name' => 'Karahi']);
        $this->productP = $this->makeProduct($karahi, ['name' => 'Chicken Karahi', 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 100]);
        $this->productQ = $this->makeProduct($karahi, ['name' => 'Roghni Naan', 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 50]);
        $this->productOther = $this->makeProduct($karahi, ['name' => 'Other Branch Special', 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 999]);
        $this->cashMethodId = $this->makePaymentMethod(['method_type' => 'cash']);

        // The synced Online permission that gates the Quick Report.
        $permId = (int) DB::connection('tenant')->table('permissions')->insertGetId(['name' => 'tenant.pos.quick-report-send', 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now()]);
        DB::connection('tenant')->table('model_has_permissions')->insert(['permission_id' => $permId, 'model_type' => User::class, 'model_id' => $this->userId]);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->bindEdgeLocalMeta($this->branchId, 1);
        $this->acceptTestBaseline([
            ['product_id' => $this->productP, 'product_variant_id' => null, 'quantity' => 20],
            ['product_id' => $this->productQ, 'product_variant_id' => null, 'quantity' => 20],
        ]);
        $this->seedEdgeCredential($this->userId, $this->branchId, 1);
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');
        $this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $this->terminalId])->assertOk();
        $this->postJson('/edge/local/pos/shift/open', ['opening_cash' => 0])->assertStatus(201);
    }

    protected function tearDown(): void
    {
        putenv('APP_ROLE');
        unset($_ENV['APP_ROLE'], $_SERVER['APP_ROLE']);
        putenv('EDGE_LOCAL_APP_KEY');
        unset($_ENV['EDGE_LOCAL_APP_KEY'], $_SERVER['EDGE_LOCAL_APP_KEY']);
        parent::tearDown();
    }

    /** One paid takeaway (1 × Karahi 100) + one OPEN dine-in check (1 × Naan 50) + a paid sale on ANOTHER branch. */
    private function tradeToday(): string
    {
        $this->postJson('/edge/local/pos/sales', [
            'order_type' => 'takeaway', 'client_uuid' => (string) Str::uuid(),
            'lines' => [['product_id' => $this->productP, 'quantity' => 1]],
            'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 100]],
        ])->assertStatus(201);
        $sessionId = $this->postJson("/edge/local/pos/restaurant/tables/{$this->tableId}/open", ['guest_count' => 2])->json('session_id');
        $this->postJson('/edge/local/pos/held-sales', [
            'order_type' => 'dine_in', 'restaurant_table_session_id' => $sessionId,
            'lines' => [['product_id' => $this->productQ, 'quantity' => 1]],
        ])->assertStatus(201);

        $date = app(TenantClock::class)->currentBusinessDate(\App\Models\Tenant\Branch::on('tenant')->find($this->branchId));
        // Another branch's paid sale on the same business day — must never reach this appliance's report.
        $other = $this->makeSale($this->otherBranchId, ['status' => 'paid', 'business_date' => $date, 'completed_at' => now(), 'grand_total' => 999, 'subtotal' => 999, 'paid_amount' => 999]);
        $this->makeSaleLine($other, $this->productOther, ['product_name' => 'Other Branch Special', 'quantity' => 1, 'unit_price' => 999, 'line_total' => 999]);

        return $date;
    }

    public function test_view_renders_the_canonical_thermal_report_with_open_bills_and_branch_scope(): void
    {
        $date = $this->tradeToday();

        // Options the modal needs (sections, business date, books, truthful email state).
        $opt = $this->getJson('/edge/local/pos/quick-report/options')->assertOk();
        $this->assertSame($date, $opt->json('date'));
        $this->assertContains('categories', $opt->json('sections'));
        $this->assertFalse($opt->json('email.available'));
        $this->assertStringContainsString('Internet required', $opt->json('email.reason'));

        // VIEW — the canonical thermal page.
        $html = $this->get('/edge/local/pos/quick-report/view?date=' . $date . '&sections[]=overview&sections[]=categories&sections[]=items')
            ->assertOk()->getContent();
        $this->assertStringContainsString('NET SALES', $html);
        $this->assertStringContainsString('KARAHI', $html, 'the category head prints on the roll');
        $this->assertStringContainsString('Chicken Karahi', $html, 'the paid item is on the report');
        // QUICK-REPORT-OPEN-BILLS-1: the OPEN held check is on the Quick Report's line sections …
        $this->assertStringContainsString('Roghni Naan', $html, 'an open check is part of the Quick Report population');
        // … while a Report-Center-style build of the same day (include_open false) does not carry it — the canonical oracle.
        $engine = app(SalesReportEngine::class);
        $closedOnly = app(SalesReportDocumentService::class)->data($engine->normalizeFilters([
            'date_from' => $date, 'date_to' => $date, 'branch_ids' => [$this->branchId], 'include_open' => false,
        ]), ['items']);
        $this->assertFalse(collect($closedOnly['items'])->contains(fn ($r) => str_contains(json_encode($r), 'Roghni Naan')), 'Report Center population excludes the open check');
        $withOpen = app(SalesReportDocumentService::class)->data($engine->normalizeFilters([
            'date_from' => $date, 'date_to' => $date, 'branch_ids' => [$this->branchId], 'include_open' => true,
        ]), ['items', 'overview']);
        $this->assertTrue(collect($withOpen['items'])->contains(fn ($r) => str_contains(json_encode($r), 'Roghni Naan')), 'the Quick Report population includes it');
        // The headline the page prints is the engine's headline (no Edge arithmetic anywhere in between).
        $this->assertStringContainsString(number_format((float) data_get($withOpen, 'overview.net_sales'), 0), $html);

        // QUICK-REPORT-BRANCH-SCOPE-1: the other branch's trade is not on this appliance's report.
        $this->assertStringNotContainsString('Other Branch Special', $html);
        $this->assertStringNotContainsString('999', $html);
    }

    public function test_network_queues_the_report_on_the_edge_print_authority_and_email_is_internet_required(): void
    {
        $date = $this->tradeToday();
        $printerId = $this->makePrinter(['name' => 'Report Printer', 'branch_id' => $this->branchId, 'printer_type' => 'network', 'print_role' => 'receipt', 'ip_address' => '127.0.0.1', 'port' => 9109, 'paper_size' => '80mm']);

        // NETWORK: the same report bytes, queued for the Edge print worker.
        $r = $this->postJson('/edge/local/pos/quick-report/network', ['printer_id' => $printerId, 'date' => $date, 'sections' => ['overview', 'categories']])->assertOk();
        $this->assertSame('Report Printer', $r->json('printer'));
        $job = PrintJob::on('tenant')->find((int) $r->json('job_id'));
        $this->assertSame('report', $job->document_type);
        $this->assertSame($printerId, (int) $job->printer_id);
        $this->assertSame((string) $this->terminalId, (string) $job->terminal_id);
        $this->assertNotSame('', (string) $job->raw_payload, 'the network report carries its ESC/POS bytes');
        $claim = app(EdgeLocalPrintDeliveryService::class)->claimNext((string) Str::uuid());
        $this->assertNotNull($claim);
        $this->assertSame((int) $job->id, (int) $claim['job_id']);
        $this->assertSame(9109, (int) $claim['port']);

        // A printer that is not a network printer with an IP is refused, controlled.
        $usb = $this->makePrinter(['name' => 'USB', 'branch_id' => $this->branchId, 'printer_type' => 'usb', 'ip_address' => null]);
        $this->postJson('/edge/local/pos/quick-report/network', ['printer_id' => $usb, 'date' => $date])->assertStatus(422);

        // EMAIL: Internet required — never a fake "sent".
        $this->postJson('/edge/local/pos/quick-report/email', ['date' => $date])->assertStatus(422)
            ->assertJsonPath('ok', false)->assertJsonPath('internet_required', true);
    }

    public function test_quick_report_requires_the_synced_online_permission(): void
    {
        DB::connection('tenant')->table('model_has_permissions')->where('model_id', $this->userId)->delete();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');

        $this->getJson('/edge/local/pos/quick-report/options')->assertStatus(403);
        $this->get('/edge/local/pos/quick-report/view')->assertStatus(403);
        $this->postJson('/edge/local/pos/quick-report/email', [])->assertStatus(403);
    }
}
