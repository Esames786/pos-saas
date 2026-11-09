<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\DashboardController;
use App\Http\Controllers\Tenant\PosQuickReportController;
use App\Http\Controllers\Tenant\Reports\SalesReportCenterController;
use App\Http\Controllers\Tenant\SalesOrderController;
use App\Models\Master\Tenant;
use App\Models\Tenant\Branch;
use App\Models\Tenant\PrintJob;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\User;
use App\Services\Reports\InventoryReportService;
use App\Services\Reports\SalesReportDocumentService;
use App\Services\Reports\SalesReportEngine;
use App\Services\Reports\SalesReportService;
use App\Services\Sales\SalesReturnService;
use App\Services\Sales\ShiftService;
use App\Support\DealSplit;
use App\Support\TenantFeatures;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\MySql\Support\TenantFixtures;

/**
 * DEAL-SPLIT-REPORT-1 — a deal's money in the reports under the items inside it.
 *
 * The owner's platter: "900 Khas = 400 biryani + 500 BBQ". With the Master switch on and the deal set to
 * "each item's rate", Categories / Items / Items by Category / By Order Type / the dashboard card / the
 * nightly PDF / the network slip / the Quick Report file 400 under Biryani and 500 under Bar-B-Q; the
 * Deals section, the overview, Net Sales, waiters and order types stay exactly as they were. Switch off:
 * the old report, untouched.
 *
 * Sales go through the REAL sale route (SalesOrderController::store), so the SalesOrderLine hook stamps
 * the parts; returns go through the real SalesReturnService. Two edge cases (a part whose parent link was
 * lost in a split bill, a three-way tie) insert their lines directly — said where they do.
 */
class DealSplitReportMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private const SECTIONS = ['overview', 'categories', 'items', 'category_items', 'deals', 'waiters', 'order_types', 'order_type_combos'];

    private ?string $previousConnection = null;
    private Tenant $tenant;
    private int $branchId;
    private int $terminalId;
    private int $cashId;
    private int $printerId;
    private int $customerId;
    private int $channelId;
    private User $user;
    private string $day;

    private int $riceCat;
    private int $bbqCat;
    private int $breadCat;
    private int $plattersCat;
    private int $rice;
    private int $bbq;
    private int $paratha;
    private int $raita;
    /** Split: 900 = rice 400 + BBQ 500 (+ 2 paratha at 0). */
    private int $khas;
    /** Not split: one rate, 1,600. */
    private int $family;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousConnection = DB::getDefaultConnection();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'print_jobs', 'sales_return_lines', 'sales_returns', 'sales_ledgers', 'sale_payments',
            'sales_order_lines', 'sales_orders', 'combo_components', 'combos', 'share_rates', 'share_partners',
            'products', 'categories', 'payment_methods', 'shifts', 'printers', 'terminal_user', 'branch_user',
            'terminals', 'model_has_permissions', 'model_has_roles', 'branches', 'users',
        ]);
        view()->share('errors', new ViewErrorBag);
        DealSplit::flush();

        $this->branchId = $this->makeBranch([
            'name' => 'Main', 'status' => 'active', 'timezone' => 'Asia/Karachi', 'allow_negative_stock' => 1,
            'manual_discount_approval_mode' => Branch::MANUAL_DISCOUNT_AUTO_APPROVE, 'delivery_charge_locked' => 0,
        ]);
        $this->terminalId = $this->makeTerminal($this->branchId, ['name' => 'Counter']);
        $this->cashId = $this->makePaymentMethod(['method_type' => 'cash']);
        // A delivery goes out through an aggregator channel (no rider of ours), to a customer on file.
        $this->customerId = (int) DB::connection('tenant')->table('customers')->insertGetId([
            'name' => 'Delivery Customer', 'phone' => '03001234567', 'address' => 'House 1', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->channelId = (int) DB::connection('tenant')->table('delivery_channels')->insertGetId([
            'name' => 'Test Aggregator ' . Str::random(4), 'type' => 'aggregator', 'commission_percent' => 0,
            'is_active' => 1, 'sort_order' => 99, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->printerId = $this->makePrinter([
            'code' => 'RPT', 'name' => 'Report Printer', 'print_role' => 'both', 'printer_type' => 'network',
            'ip_address' => '192.168.1.9', 'branch_id' => $this->branchId,
        ]);

        $this->riceCat = $this->makeCategory(['name' => 'Biryani', 'sort_order' => 1]);
        $this->bbqCat = $this->makeCategory(['name' => 'Bar-B-Q', 'sort_order' => 2]);
        $this->breadCat = $this->makeCategory(['name' => 'Breads', 'sort_order' => 3]);
        $this->plattersCat = $this->makeCategory(['name' => 'Platters', 'sort_order' => 4]);
        $this->rice = $this->sellable($this->riceCat, 'Biryani Rice', 400);
        $this->bbq = $this->sellable($this->bbqCat, 'BBQ Tikka', 500);
        $this->paratha = $this->sellable($this->breadCat, 'Paratha', 50);
        $this->raita = $this->sellable($this->breadCat, 'Raita', 100);

        $this->khas = $this->deal('Khas Platter', 900, true, [
            [$this->rice, 1, 400], [$this->bbq, 1, 500], [$this->paratha, 2, 0],
        ]);
        $this->family = $this->deal('Family Deal', 1600, false, [[$this->rice, 2, null], [$this->bbq, 1, null]]);

        $this->bindTenant();

        $uid = $this->makeUser(['default_branch_id' => $this->branchId]);
        $perms = array_merge(
            ['tenant.reports.center.index', 'tenant.pos.quick-report-send', 'tenant.dashboard.details'],
            array_map(fn ($s) => SalesReportCenterController::sectionPermission($s), SalesReportCenterController::SECTIONS),
        );
        foreach ($perms as $p) {
            Permission::findOrCreate($p, 'tenant');
        }
        User::on('tenant')->find($uid)->givePermissionTo($perms);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user = User::on('tenant')->find($uid);
        $this->actingAs($this->user, 'tenant');
        Auth::shouldUse('tenant');

        $shift = app(ShiftService::class)->open(Branch::on('tenant')->find($this->branchId), Terminal::on('tenant')->find($this->terminalId), $uid, 0.0);
        $this->day = $shift->business_date instanceof \DateTimeInterface
            ? $shift->business_date->format('Y-m-d') : (string) $shift->business_date;
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('tenant');
        $this->dropTestTenants();
        DealSplit::flush();
        if ($this->previousConnection) {
            DB::setDefaultConnection($this->previousConnection);
        }
        parent::tearDown();
    }

    /* ── fixtures ─────────────────────────────────────────────────────────────────────────────── */

    private function sellable(int $categoryId, string $name, float $price): int
    {
        return $this->makeProduct($categoryId, [
            'name' => $name, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active',
            'default_selling_price' => $price, 'is_stock_tracked' => 0, 'inventory_consumption_method' => 'none',
        ]);
    }

    /** @param list<array{0:int,1:float,2:?float}> $parts product, quantity in one deal, part (Rs) */
    private function deal(string $name, float $price, bool $byItems, array $parts): int
    {
        $c = DB::connection('tenant');
        $id = (int) $c->table('combos')->insertGetId([
            'branch_id' => $this->branchId, 'category_id' => $this->plattersCat, 'code' => 'D-' . Str::upper(Str::random(6)),
            'name' => $name, 'price' => $price, 'report_by_items' => $byItems, 'sort_order' => 1, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($parts as $i => [$productId, $qty, $amount]) {
            $c->table('combo_components')->insert([
                'combo_id' => $id, 'product_id' => $productId, 'quantity' => $qty, 'report_amount' => $amount,
                'sort_order' => $i + 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $id;
    }

    /** The master tenant the request runs as: the Master switch lives in its `features`; the plan lets the dashboard render. */
    private function bindTenant(): void
    {
        $this->dropTestTenants();
        $plan = \App\Models\Master\Plan::create(['code' => 'dsplit-' . uniqid(), 'name' => 'Deal Split Test', 'price' => 0, 'is_active' => true]);
        foreach (['pos', 'restaurant', 'reports'] as $key) {
            $module = \App\Models\Master\Module::firstOrCreate(['key' => $key], [
                'name' => ucfirst($key), 'category' => 'Test', 'description' => $key,
                'route_module_keys' => ['tenant.' . $key], 'sort_order' => 900, 'is_core' => false, 'is_active' => true,
            ]);
            \App\Models\Master\PlanModule::create(['plan_id' => $plan->id, 'module_id' => $module->id, 'is_enabled' => true]);
        }
        $this->tenant = Tenant::create([
            'tenant_code' => 'dsplit' . Str::lower(Str::random(6)), 'business_name' => 'Deal Split Kitchen',
            'status' => 'active', 'features' => [TenantFeatures::DEAL_SPLIT => false],
        ]);
        \App\Models\Master\Subscription::create(['tenant_id' => $this->tenant->id, 'plan_id' => $plan->id, 'status' => 'active', 'current_period_ends_at' => now()->addMonth()]);
        app()->instance('tenant', $this->tenant);
    }

    private function dropTestTenants(): void
    {
        $m = DB::connection('master');
        $ids = $m->table('tenants')->where('tenant_code', 'like', 'dsplit%')->pluck('id');
        $m->table('subscriptions')->whereIn('tenant_id', $ids)->delete();
        $m->table('tenants')->whereIn('id', $ids)->delete();
        $planIds = $m->table('plans')->where('code', 'like', 'dsplit-%')->pluck('id');
        $m->table('plan_modules')->whereIn('plan_id', $planIds)->delete();
        $m->table('plans')->whereIn('id', $planIds)->delete();
    }

    /** The Master switch, as the owner flips it in Bingoo Master. */
    private function switchTo(bool $on): void
    {
        $this->tenant->features = [TenantFeatures::DEAL_SPLIT => $on];
        $this->tenant->save();
        app()->instance('tenant', $this->tenant);
        $this->assertSame($on, DealSplit::active());
    }

    /**
     * A sale through the real counter route. $deals: [comboId, deals, line discount]; $items: [productId, qty, price].
     * The header sits on the deal's first item and its parts carry 0.00, exactly as the POS sends them.
     */
    private function sell(array $deals, array $items = [], string $type = 'takeaway', float $delivery = 0): int
    {
        $c = DB::connection('tenant');
        $lines = [];
        $total = 0.0;
        foreach ($deals as $n => [$comboId, $qty, $discount]) {
            $combo = $c->table('combos')->find($comboId);
            $parts = $c->table('combo_components')->where('combo_id', $comboId)->orderBy('sort_order')->orderBy('id')->get();
            $key = 'combo:' . $n;
            $lines[] = ['product_id' => $parts[0]->product_id, 'quantity' => $qty, 'unit_price' => (float) $combo->price,
                'discount_amount' => $discount, 'line_kind' => 'combo_header', 'combo_id' => $comboId,
                'line_name' => $combo->name, 'client_line_key' => $key];
            foreach ($parts as $p) {
                $lines[] = ['product_id' => $p->product_id, 'quantity' => (float) $p->quantity * $qty, 'unit_price' => 0,
                    'line_kind' => 'component', 'combo_id' => $comboId, 'parent_client_line_key' => $key];
            }
            $total += $qty * (float) $combo->price - $discount;
        }
        foreach ($items as [$productId, $qty, $price]) {
            $lines[] = ['product_id' => $productId, 'quantity' => $qty, 'unit_price' => $price];
            $total += $qty * $price;
        }
        $total += $delivery;

        $req = Request::create('/pos', 'POST', [
            'branch_id' => $this->branchId, 'terminal_id' => $this->terminalId, 'order_type' => $type,
            'order_source' => 'pos', 'discount_type' => 'none', 'client_uuid' => (string) Str::uuid(),
            'kot_print_intent' => 'skip', 'receipt_print_intent' => 'skip', 'delivery_charge_amount' => $delivery,
            'customer_id' => $type === 'delivery' ? $this->customerId : null,
            'delivery_channel_id' => $type === 'delivery' ? $this->channelId : null,
            'lines' => $lines,
            'payments' => [['payment_method_id' => $this->cashId, 'amount' => round($total, 2)]],
        ]);
        $req->headers->set('Accept', 'application/json');
        $req->setUserResolver(fn () => $this->user);
        app()->instance('request', $req);
        $resp = app()->call([app(SalesOrderController::class), 'store'], ['request' => $req]);
        $data = json_decode($resp->getContent(), true);
        $this->assertArrayHasKey('sale_id', $data ?? [], 'the sale must go through: ' . $resp->getContent());

        return (int) $data['sale_id'];
    }

    /** The day of the report: two platters, a BBQ on its own, a one-rate deal, a delivery charge. NET SALES 4,000. */
    private function sellTheDay(): void
    {
        $this->sell([[$this->khas, 1, 0]], [[$this->bbq, 1, 500]]);                                    // 1,400
        $this->sell([[$this->khas, 1, 0], [$this->family, 1, 0]], [], 'delivery', 100);               // 2,500 + 100
    }

    private function filters(array $extra = []): array
    {
        return app(SalesReportEngine::class)->normalizeFilters(array_merge([
            'date_from' => $this->day, 'date_to' => $this->day, 'branch_ids' => [$this->branchId],
        ], $extra));
    }

    private function plain($value): array
    {
        return json_decode(json_encode($value), true);
    }

    /** Every section of the report, through a fresh engine. */
    private function snapshot(array $extra = []): array
    {
        $e = app(SalesReportEngine::class);
        $f = $this->filters($extra);

        return $this->plain([
            'overview' => $e->overview($f),
            'categories' => $e->byCategory($f),
            'items' => $e->byItem($f, 'net', true),
            'items_with_deals' => $e->byItem($f),
            'category_items' => $e->byCategoryItems($f),
            'deals' => $e->byDeal($f),
            'deals_net' => $e->dealsNet($f),
            'waiters' => $e->byWaiter($f),
            'order_types' => $e->byOrderType($f),
            'order_type_combos' => $e->orderTypeCombos($f),
            'partner_share' => $e->partnerShare($f),
            'detailed' => $e->detailedQuery($f)->get(),
            'narrowed_overview' => $e->overview($this->filters($extra + ['category_id' => $this->bbqCat])),
            'narrowed_order_types' => $e->byOrderType($this->filters($extra + ['category_id' => $this->bbqCat])),
        ]);
    }

    /** name => [net qty, net value] of the root heads. */
    private function heads(array $categories): array
    {
        return collect($categories)->mapWithKeys(fn ($r) => [$r['name'] => [(float) $r['net_qty'], round((float) $r['net_value'], 2)]])->all();
    }

    /** item => [net qty, net value]. */
    private function items(iterable $rows): array
    {
        return collect($rows)->mapWithKeys(fn ($r) => [((array) $r)['item'] => [(float) ((array) $r)['net_qty'], round((float) ((array) $r)['net_value'], 2)]])->all();
    }

    /** head => [item => net value] of Items by Category. */
    private function categoryItems(array $heads): array
    {
        return collect($heads)->mapWithKeys(fn ($h) => [$h['head'] => collect($h['groups'])->flatMap(
            fn ($g) => collect($g['items'])->mapWithKeys(fn ($i) => [$i['item'] => round((float) $i['net_value'], 2)])
        )->all()])->all();
    }

    private function center(string $action, string $method, array $params)
    {
        $req = Request::create('/reports/center/' . $action, $method, $params + [
            'date_from' => $this->day, 'date_to' => $this->day, 'branch_ids' => [$this->branchId],
        ]);
        $req->headers->set('Accept', $method === 'POST' ? 'application/json' : 'text/html');
        $req->setUserResolver(fn () => auth('tenant')->user());
        app()->instance('request', $req);

        return app()->call([app(SalesReportCenterController::class), $action === 'send-to-network' ? 'sendToNetwork' : 'print'], ['request' => $req]);
    }

    /** "label => amount" for the closing lines of a rendered print. */
    private function printedBridge(string $html): array
    {
        preg_match_all('#<td(?: colspan="\d+")?>((?:Plus|Less) [^<]+|= NET SALES)</td><td class="amt">([^<]+)</td>#', $html, $m, PREG_SET_ORDER);

        return collect($m)->mapWithKeys(fn ($x) => [trim(html_entity_decode($x[1])) => (float) str_replace(',', '', $x[2])])->all();
    }

    /** The network slip of one section, as the printer will put it on paper. */
    private function slip(string $section): string
    {
        $resp = $this->center('send-to-network', 'POST', ['printer_id' => $this->printerId, 'sections' => [$section]]);
        $this->assertTrue(json_decode($resp->getContent(), true)['ok'] ?? false, $resp->getContent());
        $raw = (string) PrintJob::on('tenant')->latest('id')->value('raw_payload');

        return preg_replace(['/\x1D\x21./s', '/\x1B\x45./s', '/\x1B\x2D./s', '/[\x00-\x08\x0B-\x1F]/'], ['', '', '', ''], $raw);
    }

    private function slipBridge(string $slip): array
    {
        preg_match_all('/^((?:Plus|Less) [A-Za-z &]+?|= NET SALES) +(-?[\d,]+)$/m', $slip, $m, PREG_SET_ORDER);

        return collect($m)->mapWithKeys(fn ($x) => [trim($x[1]) => (float) str_replace(',', '', $x[2])])->all();
    }

    private function quickReport(array $sections, array $extra = []): array
    {
        $req = Request::create('/pos/quick-report', 'GET', array_merge(['date' => $this->day, 'sections' => $sections], $extra));
        $req->setUserResolver(fn () => auth('tenant')->user());
        app()->instance('request', $req);

        return app(PosQuickReportController::class)->print($req)->getData();
    }

    private function dashboardCard(): array
    {
        $req = Request::create('/dashboard', 'GET');
        app()->instance('request', $req);
        $view = app(DashboardController::class)->__invoke($req, app(SalesReportService::class), app(InventoryReportService::class));

        return $this->heads($view->getData()['categoriesToday']);
    }

    /* ── 1. switch OFF: the old report, even with parts on the bill ───────────────────────────── */

    public function test_switch_off_every_section_is_exactly_as_before_even_with_parts_on_the_bill(): void
    {
        $this->switchTo(true);        // the parts are copied onto the bill …
        $this->sellTheDay();
        $stamped = DB::connection('tenant')->table('sales_order_lines')->whereNotNull('deal_share')->count();
        $this->assertSame(8, $stamped, '2 Khas headers + 3 parts each are stamped; the one-rate deal is not');

        $this->switchTo(false);       // … and then the owner turns the switch off.
        DB::connection('tenant')->flushQueryLog();
        DB::connection('tenant')->enableQueryLog();
        $off = $this->snapshot();
        $sql = collect(DB::connection('tenant')->getQueryLog())->pluck('query')->implode("\n");
        DB::connection('tenant')->disableQueryLog();
        $this->assertNotSame('', $sql);
        foreach (['deal_share', 'l.gross', 'dsp.', 'fsp.'] as $needle) {
            $this->assertStringNotContainsString($needle, $sql, "switch off: the old code path — no [{$needle}] in any query");
        }

        // The figures the report has always printed: the deals under Platters, BBQ alone on its own row.
        $this->assertSame(['Bar-B-Q' => [1.0, 500.0], 'Platters' => [3.0, 3400.0]], $this->heads($off['categories']));
        $this->assertSame(['BBQ Tikka' => [1.0, 500.0]], $this->items($off['items']));
        $this->assertEqualsWithDelta(3400.0, $off['deals_net'], 0.001);
        $this->assertSame(['Khas Platter' => [2.0, 1800.0], 'Family Deal' => [1.0, 1600.0]], collect($off['deals'])
            ->mapWithKeys(fn ($d) => [$d['deal'] => [(float) $d['net_qty'], (float) $d['net_value']]])->all());
        $this->assertEqualsWithDelta(4000.0, $off['overview']['net_sales'], 0.001);
        $this->assertEqualsWithDelta(4.0, $off['overview']['sold_qty'], 0.001);

        // Wipe the parts off the bill — the pre-contract shape — and nothing moves.
        DB::connection('tenant')->table('sales_order_lines')->update(['deal_share' => null]);
        $this->assertSame($off, $this->snapshot(), 'switch off: the parts on the bill change nothing at all');

        // The real paths, too: Items close on Plus Deals 3,400 as before.
        $this->assertSame(['Plus Deals' => 3400.0, 'Plus Delivery Charge' => 100.0, '= NET SALES' => 4000.0],
            $this->printedBridge($this->center('print', 'GET', ['mode' => 'thermal', 'paper' => '80mm', 'sections' => ['items']])->render()));
    }

    /* ── 2. switch ON: the parts everywhere the item money is read ────────────────────────────── */

    public function test_switch_on_split_deals_file_under_their_items_in_every_report(): void
    {
        $this->switchTo(false);
        $this->sellTheDay();
        $old = $this->snapshot();      // sold before the switch: no parts on the bill
        $this->cleanTenant(['sales_ledgers', 'sale_payments', 'sales_order_lines', 'sales_orders']);

        $this->switchTo(true);
        $this->sellTheDay();
        $lines = DB::connection('tenant')->table('sales_order_lines')->where('combo_id', $this->khas)->orderBy('id')->get();
        $this->assertSame(['900.00', '400.00', '500.00', '0.00'], $lines->take(4)->pluck('deal_share')->all(), 'the hook copied the parts at the sale');
        $new = $this->snapshot();

        // CATEGORIES: 400 + 400 under Biryani, 500 + 500 + the BBQ sold alone under Bar-B-Q, only the one-rate deal under Platters.
        $expectHeads = ['Biryani' => [2.0, 800.0], 'Bar-B-Q' => [3.0, 1500.0], 'Platters' => [1.0, 1600.0]];
        $this->assertSame($expectHeads, $this->heads($new['categories']));
        $this->assertEqualsWithDelta(collect($old['categories'])->sum('net_value'), collect($new['categories'])->sum('net_value'), 0.001,
            'money changes ROW, never amount: the categories add up to what they always did');
        $this->assertArrayNotHasKey('Breads', $this->heads($new['categories']), 'the 0-part paratha never appears');

        // ITEMS: the parts merge with the same item sold on its own; the one-rate deal stays out of Items.
        $this->assertSame(['BBQ Tikka' => [3.0, 1500.0], 'Biryani Rice' => [2.0, 800.0]], $this->items($new['items']));
        $this->assertEqualsWithDelta(1600.0, $new['deals_net'], 0.001, 'Plus Deals = only the deal still sold as one rate');
        $this->assertEqualsWithDelta($new['overview']['net_sales'],
            collect($new['items'])->sum('net_value') + $new['deals_net'] + 100, 0.001, 'Items + Plus Deals + delivery = NET SALES');
        $this->assertSame(['Biryani' => ['Biryani Rice' => 800.0], 'Bar-B-Q' => ['BBQ Tikka' => 1500.0]], $this->categoryItems($new['category_items']));

        // BY ORDER TYPE: each type's categories carry their own parts; its totals still close.
        $this->assertSame(['Biryani' => [1.0, 400.0], 'Bar-B-Q' => [2.0, 1000.0]],
            collect($new['order_type_combos']['categories']['Takeaway'])->mapWithKeys(fn ($r) => [$r['label'] => [(float) $r['net_qty'], (float) $r['net_value']]])->all());
        $this->assertSame(['Biryani' => [1.0, 400.0], 'Bar-B-Q' => [1.0, 500.0], 'Platters' => [1.0, 1600.0]],
            collect($new['order_type_combos']['categories']['Delivery'])->mapWithKeys(fn ($r) => [$r['label'] => [(float) $r['net_qty'], (float) $r['net_value']]])->all());
        $this->assertSame($old['order_type_combos']['totals'], $new['order_type_combos']['totals']);
        $this->assertSame($old['order_type_combos']['waiters'], $new['order_type_combos']['waiters']);

        // UNCHANGED: Deals, overview, NET SALES, payments, waiters, order types, Partner Share, Detailed.
        foreach (['deals', 'overview', 'waiters', 'order_types', 'partner_share'] as $same) {
            $this->assertSame($old[$same], $new[$same], "{$same} must not move");
        }
        $this->assertSame(array_map(fn ($r) => array_diff_key($r, ['sale_date' => 1, 'sale_no' => 1]), $old['detailed']),
            array_map(fn ($r) => array_diff_key($r, ['sale_date' => 1, 'sale_no' => 1]), $new['detailed']), 'Detailed is the bill line by line');

        // REPORT CENTER (print → the browser and the A4) — the same rows, and Items close on NET SALES.
        $print = $this->center('print', 'GET', ['mode' => 'thermal', 'paper' => '80mm', 'sections' => self::SECTIONS]);
        $data = $print->getData();
        $this->assertSame($expectHeads, $this->heads($data['categories']));
        $this->assertSame($this->items($new['items']), $this->items($data['items']));
        $this->assertSame($new['deals'], $this->plain($data['deals']));
        $this->assertSame(['Plus Deals' => 1600.0, 'Plus Delivery Charge' => 100.0, '= NET SALES' => 4000.0],
            $this->printedBridge($this->center('print', 'GET', ['mode' => 'thermal', 'paper' => '80mm', 'sections' => ['items']])->render()));
        $this->assertSame(['Plus Deals' => 1600.0, 'Plus Delivery Charge' => 100.0, '= NET SALES' => 4000.0],
            $this->printedBridge($this->center('print', 'GET', ['mode' => 'thermal', 'paper' => '80mm', 'sections' => ['category_items']])->render()));
        $this->assertSame(['Plus Delivery Charge' => 100.0, '= NET SALES' => 4000.0],
            $this->printedBridge($this->center('print', 'GET', ['mode' => 'thermal', 'paper' => '80mm', 'sections' => ['categories']])->render()));

        // NETWORK SLIP — the controller's own data through EscPosPayloadService::buildReport.
        $items = $this->slip('items');
        $this->assertSame(['Plus Deals' => 1600.0, 'Plus Delivery Charge' => 100.0, '= NET SALES' => 4000.0], $this->slipBridge($items));
        $this->assertMatchesRegularExpression('/BBQ Tikka\n[^\n]*Qty +3 +0 +3\n[^\n]*Amt +1,500 +0 +1,500/', $items);
        $this->assertMatchesRegularExpression('/Biryani Rice\n[^\n]*Qty +2 +0 +2\n[^\n]*Amt +800 +0 +800/', $items);
        $this->assertStringNotContainsString('Khas Platter', $items);
        $this->assertStringNotContainsString('Paratha', $items);
        $categories = $this->slip('categories');
        $this->assertMatchesRegularExpression('/BIRYANI\n[^\n]*Qty +2 +0 +2\n[^\n]*Amt +800 +0 +800/', $categories);
        $this->assertMatchesRegularExpression('/BAR-B-Q\n[^\n]*Qty +3 +0 +3\n[^\n]*Amt +1,500 +0 +1,500/', $categories);
        $this->assertSame(['Plus Delivery Charge' => 100.0, '= NET SALES' => 4000.0], $this->slipBridge($categories));
        $this->assertMatchesRegularExpression('/Khas Platter\n[^\n]*Qty +2 +0 +2\n[^\n]*Amt +1,800 +0 +1,800/', $this->slip('deals'),
            'the Deals section still prints the deal × count × money');

        // POS QUICK REPORT — byte-identical rows to the Report Center.
        $quick = $this->quickReport(self::SECTIONS);
        $this->assertSame($expectHeads, $this->heads($quick['categories']));
        $this->assertSame($this->items($new['items']), $this->items($quick['items']));
        $this->assertEqualsWithDelta(1600.0, $quick['bridge']['deals_net'], 0.001);

        // NIGHTLY PDF / WHATSAPP — the document service, rendered.
        $nightlyFilters = app(SalesReportEngine::class)->normalizeFilters(['date_from' => $this->day, 'date_to' => $this->day]);
        $nightly = app(SalesReportDocumentService::class)->data($nightlyFilters, self::SECTIONS, true);
        $this->assertSame($expectHeads, $this->heads($nightly['categories']));
        $this->assertSame($this->items($new['items']), $this->items($nightly['items']));
        $html = view('tenant.reports.center.print', array_merge($nightly, ['sections' => ['items']]))->render();
        $this->assertSame(['Plus Deals' => 1600.0, 'Plus Delivery Charge' => 100.0, '= NET SALES' => 4000.0], $this->printedBridge($html));

        // DASHBOARD "Sales by Category Today".
        $this->assertSame(['Platters' => [1.0, 1600.0], 'Bar-B-Q' => [3.0, 1500.0], 'Biryani' => [2.0, 800.0]], $this->dashboardCard());
    }

    /* ── 3. a discounted split deal: proportional, exact to the paisa ─────────────────────────── */

    public function test_a_line_discount_on_a_split_deal_splits_in_proportion_and_adds_up_exactly(): void
    {
        $this->switchTo(true);
        $money = fn ($v) => number_format((float) $v, 2, '.', '');
        $e = app(SalesReportEngine::class);

        // The owner's platter with 100 off: 800 → 355.56 + 444.44 = 800.00.
        $saleId = $this->sell([[$this->khas, 1, 100]]);
        $this->assertSame('800.00', DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $saleId)
            ->where('line_kind', 'combo_header')->value('line_total'), 'the bill itself is untouched: one 800 line');
        $items = collect($e->byItem($this->filters(), 'net', true))->keyBy('item');
        $this->assertSame(['355.56', '444.44'], [$money($items['Biryani Rice']->net), $money($items['BBQ Tikka']->net)]);
        $this->assertSame('800.00', $money($items->sum('net')));
        $this->assertSame(['400.00', '500.00'], [$money($items['Biryani Rice']->gross), $money($items['BBQ Tikka']->gross)], 'gross: the part, not quantity × 0');
        $this->assertSame(['44.44', '55.56'], [$money($items['Biryani Rice']->discount), $money($items['BBQ Tikka']->discount)]);
        $this->assertSame('800.00', $money(collect($e->byCategory($this->filters()))->sum('net')));

        // Three equal parts: 800 / 3 rounds to 266.67 three times = 800.01 — the paisa must come back off the biggest
        // part (a three-way tie → the lowest line, the rice), so the parts add up to the deal exactly.
        $trio = $this->deal('Trio Platter', 900, true, [[$this->rice, 1, 300], [$this->bbq, 1, 300], [$this->raita, 1, 300]]);
        $this->sell([[$trio, 1, 100]]);
        $items = collect($e->byItem($this->filters(), 'net', true))->keyBy('item');
        $this->assertSame('622.22', $money($items['Biryani Rice']->net), 'rice: 355.56 + 266.66 (the trio part that took the remainder)');
        $this->assertSame('711.11', $money($items['BBQ Tikka']->net), 'BBQ: 444.44 + 266.67');
        $this->assertSame('266.67', $money($items['Raita']->net));
        $this->assertSame('1600.00', $money($items->sum('net')), 'the parts add up to the two discounted deals exactly');
        $this->assertSame('200.00', $money($items->sum('discount')), 'discount 100 + (33.34 + 33.33 + 33.33)');
        $this->assertSame('1800.00', $money($items->sum('gross')));
        $this->assertSame('1600.00', $money(collect($e->byCategory($this->filters()))->sum('net')));
        $this->assertSame('1600.00', $money($e->overview($this->filters())['net_sales']));
        $this->assertSame('1600.00', $money($e->overview($this->filters(['category_ids' => [$this->riceCat, $this->bbqCat, $this->breadCat]]))['net_sales']),
            'the narrowed headline adds the parts up to the same paisa');
        $this->assertEqualsWithDelta(0.0, $e->dealsNet($this->filters()), 0.0001);
    }

    /* ── 4. a split deal returned, in part and then in full ───────────────────────────────────── */

    public function test_a_partial_then_full_return_of_a_split_deal_comes_off_its_items(): void
    {
        $this->switchTo(true);
        $saleId = $this->sell([[$this->khas, 2, 0]], [[$this->bbq, 1, 500]]);   // 2 platters (1,800) + a BBQ: 2,300
        $header = DB::connection('tenant')->table('sales_order_lines')->where('sales_order_id', $saleId)
            ->where('line_kind', 'combo_header')->value('id');
        $return = fn (float $qty) => app(SalesReturnService::class)->processReturn(
            SalesOrder::on('tenant')->findOrFail($saleId), [['sales_order_line_id' => $header, 'quantity' => $qty]],
            'Test return', 'cash', null, $this->user->id,
        );

        $return(1);   // one platter back: 900
        $this->assertSame(1, DB::connection('tenant')->table('sales_return_lines')->count(), 'the return writes ONE line, on the header');
        $e = app(SalesReportEngine::class);
        $f = $this->filters();
        $items = $this->items($e->byItem($f, 'net', true));
        $this->assertSame(['BBQ Tikka' => [2.0, 1000.0], 'Biryani Rice' => [1.0, 400.0]], $items,
            'sold 3 BBQ (1,500) and 2 rice (800); back 1 of each at 500 / 400');
        $rows = collect($e->byItem($f, 'net', true))->keyBy('item');
        $this->assertEqualsWithDelta(1.0, (float) $rows['BBQ Tikka']->returned_qty, 0.0001);
        $this->assertEqualsWithDelta(500.0, (float) $rows['BBQ Tikka']->returns_amount, 0.0001);
        $this->assertSame(['Biryani' => [1.0, 400.0], 'Bar-B-Q' => [2.0, 1000.0]], $this->heads($e->byCategory($f)));
        $this->assertEqualsWithDelta(0.0, $e->dealsNet($f), 0.0001, 'a split deal and its return are inside Items, not Plus Deals');
        $deals = collect($e->byDeal($f))->keyBy('deal');
        $this->assertEqualsWithDelta(900.0, $deals['Khas Platter']['returns_amount'], 0.0001, 'Deals: the return still comes off the deal');
        $this->assertEqualsWithDelta(1.0, $deals['Khas Platter']['returned_qty'], 0.0001);
        $overview = $e->overview($f);
        $this->assertEqualsWithDelta(1400.0, $overview['net_sales'], 0.0001);
        $this->assertEqualsWithDelta($overview['net_sales'], collect($e->byItem($f, 'net', true))->sum('net_value') + $e->dealsNet($f), 0.0001);
        $this->assertEqualsWithDelta(1.0, $overview['returned_qty'], 0.0001, 'the overview counts the deal as sold: one platter back');

        $return(1);   // the other platter back too
        $f = $this->filters();
        $this->assertSame(['BBQ Tikka' => [1.0, 500.0], 'Biryani Rice' => [0.0, 0.0]], $this->items($e->byItem($f, 'net', true)));
        $this->assertSame(['Biryani' => [0.0, 0.0], 'Bar-B-Q' => [1.0, 500.0]], $this->heads($e->byCategory($f)));
        $this->assertEqualsWithDelta(500.0, $e->overview($f)['net_sales'], 0.0001);
        $this->assertEqualsWithDelta(1800.0, collect($e->byDeal($f))->firstWhere('deal', 'Khas Platter')['returns_amount'], 0.0001);
        // The narrowed report follows the parts too.
        $this->assertEqualsWithDelta(800.0, $e->overview($this->filters(['category_id' => $this->riceCat]))['returns_amount'], 0.0001);
    }

    /* ── 5. the category filter finds a deal's part ───────────────────────────────────────────── */

    public function test_a_category_filter_on_an_items_head_finds_the_split_part(): void
    {
        $this->switchTo(true);
        $this->sellTheDay();
        $e = app(SalesReportEngine::class);

        $bbq = $this->filters(['category_id' => $this->bbqCat]);
        $this->assertSame(['Bar-B-Q' => [3.0, 1500.0]], $this->heads($e->byCategory($bbq)));
        $this->assertSame(['BBQ Tikka' => [3.0, 1500.0]], $this->items($e->byItem($bbq, 'net', true)));
        $overview = $e->overview($bbq);
        $this->assertEqualsWithDelta(1500.0, $overview['net_sales'], 0.0001, 'the narrowed headline is the BBQ itself');
        $this->assertSame(2, $overview['orders'], 'both bills carry BBQ — one only inside a platter');
        $this->assertEqualsWithDelta(1500.0, collect($e->byOrderType($bbq))->sum('net_sales'), 0.0001);

        // The rice head finds the second bill, which has no rice of its own outside the deals.
        $rice = $this->filters(['category_ids' => [$this->riceCat]]);
        $this->assertSame(['Biryani' => [2.0, 800.0]], $this->heads($e->byCategory($rice)));
        $this->assertSame(2, $e->overview($rice)['orders']);

        // The deal's own head still finds its bills for the Deals section, which is unchanged.
        $platters = $this->filters(['category_id' => $this->plattersCat]);
        $this->assertSame(['Khas Platter', 'Family Deal'], collect($e->byDeal($platters))->pluck('deal')->all());
        $this->assertSame(['Platters' => [1.0, 1600.0]], $this->heads($e->byCategory($platters)), 'the split platters are filed under their items');

        // The Quick Report's multi-category pick, through its controller.
        $quick = $this->quickReport(['categories', 'items'], ['category_ids' => [$this->bbqCat]]);
        $this->assertSame(['Bar-B-Q' => [3.0, 1500.0]], $this->heads($quick['categories']));
        $this->assertSame(['BBQ Tikka' => [3.0, 1500.0]], $this->items($quick['items']));
    }

    /* ── 6. the deal edited after the sale ────────────────────────────────────────────────────── */

    public function test_editing_the_deals_parts_after_the_sale_does_not_move_the_old_report(): void
    {
        $this->switchTo(true);
        $this->sellTheDay();
        $before = $this->snapshot();

        $c = DB::connection('tenant');
        $c->table('combo_components')->where('combo_id', $this->khas)->where('product_id', $this->rice)->update(['report_amount' => 700]);
        $c->table('combo_components')->where('combo_id', $this->khas)->where('product_id', $this->bbq)->update(['report_amount' => 200]);
        $this->assertSame($before, $this->snapshot(), 'the parts were copied at the sale; a new rate is for new sales');

        $c->table('combos')->where('id', $this->khas)->update(['report_by_items' => false]);
        $this->assertSame($before, $this->snapshot(), 'even "one rate" again does not rewrite the sales already made');

        // A sale made after the edit takes the deal as it is now: one rate, under Platters.
        $this->sell([[$this->khas, 1, 0]]);
        $heads = $this->heads(app(SalesReportEngine::class)->byCategory($this->filters()));
        $this->assertSame([2.0, 800.0], $heads['Biryani']);
        $this->assertSame([2.0, 2500.0], $heads['Platters']);
    }

    /* ── 7. switch ON, nothing split ──────────────────────────────────────────────────────────── */

    public function test_switch_on_without_split_deals_reports_exactly_as_switch_off(): void
    {
        DB::connection('tenant')->table('combos')->update(['report_by_items' => false]);
        $this->switchTo(true);
        $this->sellTheDay();
        $this->assertSame(0, DB::connection('tenant')->table('sales_order_lines')->whereNotNull('deal_share')->count());

        $on = $this->snapshot();
        $this->switchTo(false);
        $off = $this->snapshot();

        $this->assertSame($off, $on, 'with nothing split the switch changes nothing');
        $this->assertSame(['Bar-B-Q' => [1.0, 500.0], 'Platters' => [3.0, 3400.0]], $this->heads($on['categories']));
    }

    /* ── edge: a part that lost its parent (Split Bill copies lines without the link) ─────────── */

    /** Inserted directly: Split Bill's copy is a header and its parts on a new bill, no parent link. */
    public function test_a_part_without_its_parent_does_not_split_and_the_header_keeps_the_money(): void
    {
        $this->switchTo(true);
        $sale = $this->makeSale($this->branchId, ['order_type' => 'takeaway', 'subtotal' => 900, 'grand_total' => 900, 'business_date' => $this->day]);
        $this->makeSaleLine($sale, $this->rice, ['product_name' => 'Khas Platter', 'line_kind' => 'combo_header', 'combo_id' => $this->khas,
            'quantity' => 1, 'unit_price' => 900, 'line_total' => 900, 'deal_share' => 900]);
        foreach ([[$this->rice, 400], [$this->bbq, 500]] as [$pid, $share]) {
            $this->makeSaleLine($sale, $pid, ['line_kind' => 'component', 'combo_id' => $this->khas, 'quantity' => 1,
                'unit_price' => 0, 'line_total' => 0, 'deal_share' => $share, 'parent_sales_order_line_id' => null]);
        }

        $e = app(SalesReportEngine::class);
        $this->assertSame(['Platters' => [1.0, 900.0]], $this->heads($e->byCategory($this->filters())));
        $this->assertSame([], $this->items($e->byItem($this->filters(), 'net', true)));
        $this->assertEqualsWithDelta(900.0, $e->dealsNet($this->filters()), 0.0001, 'unsplit, so Plus Deals carries it');
    }
}
