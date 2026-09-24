<?php

namespace Tests\MySql;

use App\Models\Tenant\PrintJob;
use App\Models\Tenant\User;
use App\Support\TenantClock;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * W4 (Team 4) — QUICK REPORT parity over the REAL branch_server routes (audit R5.1–R5.5; Online #quickReportModal +
 * PosQuickReportController):
 *  - R5.2 the modal books (items / waiters / order types) and the filters narrow the WHOLE report on the canonical engine
 *    (item selection with All-items off, order-type filter), the per-user SAVED SELECTION round-trips (local table);
 *  - R5.3 the report header prints the business name the Online controller would (tenant name when bound, else branch);
 *  - R5.4 send-to-network carries the same filters; R5.5 e-mail stays a truthful Internet-required 422;
 *  - R5.1 the permission gates settings / save-settings too; the page carries the Online modal ids.
 */
class EdgeCashierQuickReportParityHttpMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $terminalId;
    private int $userId;
    private int $productP;
    private int $productQ;
    private int $cashMethodId;
    private int $waiterId;

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
        $this->cleanTenant([
            'edge_local_print_deliveries', 'edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines',
            'edge_auth_audit', 'edge_local_user_credentials', 'edge_local_meta', 'edge_sync_outbox', 'print_jobs',
            'category_printer_mappings', 'terminal_printer_settings', 'printers', 'pos_quick_report_settings', 'model_has_permissions', 'permissions',
            'restaurant_waiters', 'sales_returns', 'sales_ledgers', 'cash_bank_account_transactions', 'journal_lines', 'journal_entries',
            'stock_ledgers', 'stock_balances', 'sale_payments', 'sales_order_lines', 'sales_orders',
            'payment_methods', 'combo_components', 'combos', 'products', 'categories', 'shifts', 'terminals', 'branches', 'users',
        ]);
        $this->branchId = $this->makeBranch(['name' => 'QR Branch', 'allow_negative_stock' => 0, 'timezone' => 'Asia/Karachi']);
        $this->userId = $this->makeUser(['default_branch_id' => $this->branchId, 'employee_code' => 'QRX' . Str::random(4)]);
        $this->terminalId = $this->makeTerminal($this->branchId);
        $this->waiterId = $this->makeWaiter($this->branchId, ['name' => 'Waiter Imran']);
        $cat = $this->makeCategory(['name' => 'Karahi']);
        $this->productP = $this->makeProduct($cat, ['name' => 'Chicken Karahi', 'sku' => 'CK-1', 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 100]);
        $this->productQ = $this->makeProduct($cat, ['name' => 'Roghni Naan', 'sku' => 'RN-1', 'inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 50]);
        $this->cashMethodId = $this->makePaymentMethod(['method_type' => 'cash']);
        $this->bindEdgeLocalMeta($this->branchId, 1);
        $this->acceptTestBaseline([
            ['product_id' => $this->productP, 'product_variant_id' => null, 'quantity' => 20],
            ['product_id' => $this->productQ, 'product_variant_id' => null, 'quantity' => 20],
        ]);
        $this->seedEdgeCredential($this->userId, $this->branchId, 1);
        $this->grantEdgePermission($this->userId, 'tenant.pos.quick-report-send');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
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
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    private function trade(): string
    {
        foreach ([[$this->productP, 100], [$this->productQ, 50]] as [$pid, $amt]) {
            $this->postJson('/edge/local/pos/sales', ['order_type' => 'takeaway', 'client_uuid' => (string) Str::uuid(),
                'lines' => [['product_id' => $pid, 'quantity' => 1]], 'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => $amt]]])->assertStatus(201);
        }

        return app(TenantClock::class)->currentBusinessDate(\App\Models\Tenant\Branch::on('tenant')->find($this->branchId));
    }

    public function test_modal_books_filters_and_business_name_on_the_canonical_engine(): void
    {
        $date = $this->trade();
        $o = $this->getJson('/edge/local/pos/quick-report/options')->assertOk();
        $this->assertTrue(collect($o->json('items'))->contains(fn ($i) => $i['name'] === 'Chicken Karahi' && $i['sku'] === 'CK-1'), 'item picker book');
        $this->assertTrue(collect($o->json('waiters'))->contains(fn ($w) => (int) $w['id'] === $this->waiterId), 'waiter book');
        $this->assertArrayHasKey('dine_in', $o->json('order_types'));
        $this->assertSame('QR Branch', $o->json('business_name'), 'no bound tenant on the appliance → the bound branch name (never an invented label)');

        $base = '/edge/local/pos/quick-report/view?date=' . $date . '&sections[]=overview&sections[]=items';
        $all = $this->get($base . '&all_items=1')->assertOk()->getContent();
        $this->assertStringContainsString('Chicken Karahi', $all);
        $this->assertStringContainsString('Roghni Naan', $all);
        $this->assertStringContainsString('QR Branch', $all, 'report header');
        // Item selection (All items OFF) narrows the whole report.
        $onlyP = $this->get($base . '&all_items=0&product_ids[]=' . $this->productP)->assertOk()->getContent();
        $this->assertStringContainsString('Chicken Karahi', $onlyP);
        $this->assertStringNotContainsString('Roghni Naan', $onlyP);
        // All items ON ignores a stale item selection (Online rule).
        $this->assertStringContainsString('Roghni Naan', $this->get($base . '&all_items=1&product_ids[]=' . $this->productP)->assertOk()->getContent());
        // Order-type filter: nothing was sold dine-in.
        $dine = $this->get($base . '&all_items=1&order_types[]=dine_in')->assertOk()->getContent();
        $this->assertStringNotContainsString('Chicken Karahi', $dine);
        // Waiter filter: no sale carried this waiter.
        $this->assertStringNotContainsString('Roghni Naan', $this->get($base . '&all_items=1&waiter_ids[]=' . $this->waiterId)->assertOk()->getContent());

        // R5.4 send-to-network carries the same filters (stored on the job payload + bytes queued), R5.5 email truthful.
        $printer = $this->makePrinter(['name' => 'Report Printer', 'branch_id' => $this->branchId, 'printer_type' => 'network', 'print_role' => 'receipt', 'ip_address' => '127.0.0.1', 'port' => 9109, 'paper_size' => '80mm']);
        $r = $this->postJson('/edge/local/pos/quick-report/network', ['printer_id' => $printer, 'date' => $date, 'sections' => ['items'], 'product_ids' => [$this->productP], 'all_items' => false])->assertOk();
        $job = PrintJob::on('tenant')->find((int) $r->json('job_id'));
        $this->assertSame('report', $job->document_type);
        $this->assertStringContainsString('QR BRANCH', (string) $job->raw_payload, 'network header = the same business name');
        $this->postJson('/edge/local/pos/quick-report/email', ['date' => $date])->assertStatus(422)->assertJsonPath('internet_required', true);
    }

    public function test_saved_selection_round_trips_per_user_on_the_branch_server_and_is_permission_gated(): void
    {
        $this->getJson('/edge/local/pos/quick-report/settings')->assertOk()->assertJsonPath('settings', null);
        $this->postJson('/edge/local/pos/quick-report/save-settings', [
            'sections' => ['overview', 'items', 'not-a-section'], 'category_ids' => ['3'], 'product_ids' => [$this->productP],
            'waiter_ids' => [$this->waiterId], 'order_types' => ['takeaway'], 'all_items' => false,
        ])->assertOk()->assertJsonPath('stored', 'branch_server');
        $s = $this->getJson('/edge/local/pos/quick-report/settings')->assertOk()->json('settings');
        $this->assertSame(['overview', 'items'], $s['sections'], 'unknown sections are dropped (Online rule)');
        $this->assertSame([3], $s['category_ids']);
        $this->assertSame([$this->productP], $s['product_ids']);
        $this->assertSame(['takeaway'], $s['order_types']);
        $this->assertFalse($s['all_items']);
        $this->assertSame(1, DB::table('pos_quick_report_settings')->where('user_id', $this->userId)->count());

        $html = $this->get('/edge/local/pos')->assertOk()->getContent();
        foreach (['quickReportModalLabel', "'qr-save'", 'qr-panel-items', 'qr-all-items', 'qr-item-picker', 'qr-item-search', 'qr-item-suggest', 'qr-item-chips', 'qr-panel-waiters', 'qr-panel-order_types', "'qr-print'", 'id="qr-branch"', '/quick-report/save-settings', '/quick-report/settings', 'w.print()'] as $needle) {
            $this->assertStringContainsString($needle, $html, "the Quick Report dialog must carry {$needle}");
        }

        DB::table('model_has_permissions')->where('model_id', $this->userId)->where('permission_id', DB::table('permissions')->where('name', 'tenant.pos.quick-report-send')->value('id'))->delete();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        $this->getJson('/edge/local/pos/quick-report/settings')->assertForbidden();
        $this->postJson('/edge/local/pos/quick-report/save-settings', ['sections' => ['overview']])->assertForbidden();
    }
}
