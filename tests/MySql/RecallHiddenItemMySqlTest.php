<?php

namespace Tests\MySql;

use App\Models\Master\Module;
use App\Models\Tenant\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\MySql\Support\ParsesInlineScripts;
use Tests\MySql\Support\TenantFixtures;

/**
 * RECALL-HIDDEN-ITEM-1 — a website order must come back to the POS with EVERY item on it.
 *
 * 14 Nov: a website order held Singaporean Rice (Khass) 2,900 + a pasta 1,250 + delivery 350 = 4,500. The POS
 * recalled only the pasta and showed 1,600. The rice is hidden from the POS (no tile). The POS page carries
 * an item that sits on an open bill only if that bill was already open WHEN THE PAGE LOADED, and a website
 * order arrives later. So recall could not find the rice and left it out without a word.
 *
 * The fix: every active, sellable item hidden from the POS rides in a list of its own (`recallProducts`),
 * which only recall reads, and recall never opens half a bill.
 *
 * These tests run the order of events from that evening: the POS page loads FIRST, the website order is saved
 * AFTER, the bill comes back through the same held-sales API the website window uses, and then the POS's
 * own recallHeldSale() runs in node with the page's own lists.
 *
 * Plan: docs/plans/recall-hidden-item-2026-11-14.md
 */
class RecallHiddenItemMySqlTest extends MySqlTenantTestCase
{
    use ParsesInlineScripts;
    use TenantFixtures;

    private string $host;
    private int $tenantId;
    private int $ownerId;
    private int $branchId;
    private int $categoryId;
    private int $riceId;     // hidden from the POS, active, sellable — the 14 Nov item
    private int $pastaId;    // an ordinary POS item

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ValidateCsrfToken::class, VerifyCsrfToken::class]);

        $this->host = 'recallhid.' . config('tenancy.tenant_base_domain');
        $this->seedMaster();
        $this->seedSubscription();
        $this->seedTenant();
    }

    protected function tearDown(): void
    {
        try {
            $m = DB::connection('master');
            $m->table('tenant_domains')->where('domain', $this->host)->delete();
            $m->table('tenant_databases')->where('db_database', $this->tenantDb)->where('tenant_id', $this->tenantId)->delete();
            $m->table('subscriptions')->where('tenant_id', $this->tenantId)->delete();
            $m->table('tenants')->where('tenant_code', 'recallhid')->delete();
        } catch (\Throwable) {
            // best effort; never mask the real outcome
        }
        parent::tearDown();
    }

    // ── what the page carries ─────────────────────────────────────────────────────────────────────────

    /** The hidden item rides in the recall list ONLY — the grid / search / scan list stays as it was. */
    public function test_a_hidden_item_rides_in_the_recall_list_and_nowhere_else(): void
    {
        $page = $this->loadPos();

        $this->assertContains($this->riceId, $this->ids($page['recall']), 'the hidden item must be loadable for a recall');
        $this->assertNotContains($this->riceId, $this->ids($page['products']),
            'the hidden item must NOT join the list the grid, search and barcode scan read — it would become sellable');
        $this->assertContains($this->pastaId, $this->ids($page['products']));
        $this->assertNotContains($this->pastaId, $this->ids($page['recall']), 'a POS item is listed once');

        $rice = collect($page['recall'])->firstWhere('id', $this->riceId);
        $this->assertSame(2900.0, (float) $rice['price'], 'it carries the same fields a grid item carries');
        $this->assertFalse($rice['pos_grid_visible']);
    }

    /** Switched-off and not-for-sale items stay out of both lists — the outer gates do not move. */
    public function test_switched_off_or_not_for_sale_items_stay_out_of_both_lists(): void
    {
        $off = $this->makeProduct($this->categoryId, ['name' => 'Old Platter', 'status' => 'inactive', 'is_pos_visible' => 0]);
        $raw = $this->makeProduct($this->categoryId, ['name' => 'Raw Rice (kg)', 'is_sellable' => 0, 'is_pos_visible' => 0]);

        $page = $this->loadPos();
        foreach ([$off, $raw] as $id) {
            $this->assertNotContains($id, $this->ids($page['products']));
            $this->assertNotContains($id, $this->ids($page['recall']));
        }
    }

    /** An item already in the POS list because it sits in a combo is not listed twice. */
    public function test_a_combo_part_stays_where_it_was_and_is_not_listed_twice(): void
    {
        $c = DB::connection('tenant');
        $part = $this->makeProduct($this->categoryId, ['name' => 'Arabic Rice', 'is_pos_visible' => 0]);
        $comboId = $c->table('combos')->insertGetId(['name' => 'Platter', 'code' => 'PL1', 'price' => 4400,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $c->table('combo_components')->insert(['combo_id' => $comboId, 'product_id' => $part, 'quantity' => 1,
            'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);

        $page = $this->loadPos();
        $this->assertContains($part, $this->ids($page['products']), 'combo parts ride in the POS list as before');
        $this->assertNotContains($part, $this->ids($page['recall']));
    }

    // ── recall, run for real ──────────────────────────────────────────────────────────────────────────

    /** The 14 Nov evening, end to end: page first, website order after, recall brings back BOTH items. */
    public function test_a_website_order_saved_after_the_page_loaded_comes_back_whole(): void
    {
        $page = $this->loadPos();                       // the cashier's POS, open since earlier
        $sale = $this->saveWebsiteOrder($this->riceId); // the website order lands afterwards

        $this->assertNotContains($this->riceId, $this->ids($page['products']),
            'precondition: the POS list does not have the rice — exactly the 14 Nov state');

        $out = $this->runRecall($page, $sale, ['cart = [{ key: "old", name: "Earlier order" }];']);

        $this->assertSame([], $out['swal'], 'no warning: everything on the bill was found');
        $this->assertSame(['Singaporean Rice (Khass)', 'Chicken Red Sauce Pasta (1 Person)'], array_column($out['cart'], 'name'),
            'both items come back — before the fix only the pasta did');
        $this->assertEquals(4150, array_sum(array_map(fn ($l) => $l['unit_price'] * $l['quantity'], $out['cart'])),
            'items total 4,150 (+350 delivery = 4,500), not 1,250');
        $this->assertSame($sale['id'], $out['heldId']);
    }

    /** An item switched off in Products: the bill is NOT opened half — the cart stays, a clear message shows. */
    public function test_a_bill_with_a_switched_off_item_is_never_opened_half(): void
    {
        DB::connection('tenant')->table('products')->where('id', $this->riceId)->update(['status' => 'inactive']);
        $page = $this->loadPos();                       // switched off, so the page carries it in neither list
        $sale = $this->saveWebsiteOrder($this->riceId); // and an older order still has it

        $this->assertNotContains($this->riceId, $this->ids($page['recall']), 'precondition: nowhere on the page');

        $out = $this->runRecall($page, $sale, ['cart = [{ key: "old", name: "Earlier order" }];']);

        $this->assertSame(['Earlier order'], array_column($out['cart'], 'name'), 'the cart is left exactly as it was');
        $this->assertNull($out['heldId'], 'the bill was not taken up');
        $this->assertCount(1, $out['swal'], 'the cashier is told — nothing vanishes without a word');
        $this->assertStringContainsString('Singaporean Rice (Khass)', $out['swal'][0]['html']);
        $this->assertStringContainsString($sale['sale_no'], $out['swal'][0]['titleText']);
        $this->assertStringNotContainsString('Chicken Red Sauce Pasta', $out['swal'][0]['html'], 'only the missing item is named');
    }

    /** Opening a bill by its address (?held_sale_id=) follows the same rule and the same lookup. */
    public function test_opening_a_bill_by_its_address_uses_the_same_rule(): void
    {
        $sale = $this->saveWebsiteOrder($this->riceId);
        $html = $this->getPos('?held_sale_id=' . $sale['id'])->assertOk()->getContent();

        $start = strpos($html, '/* preload held sale (page-load recall via ?held_sale_id=) */');
        $end = strpos($html, '/* initial render */', (int) $start);
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $block = substr($html, $start, $end - $start);

        $this->assertStringContainsString('heldBillMissingItems(heldSale.lines)', $block, 'the page-load recall checks every item first');
        $this->assertStringContainsString('productForHeldLine(line)', $block, 'and finds items in both lists');
        $this->assertStringNotContainsString('products.find(', $block, 'no second, older lookup left behind');
    }

    /** Every inline script on the POS still parses — a dead script block would hide all of the above. */
    public function test_the_pos_scripts_still_parse(): void
    {
        $this->assertInlineScriptsParse($this->getPos()->assertOk()->getContent(), 'POS', 'RECALL-HIDDEN-ITEM-1');
    }

    // ── helpers ───────────────────────────────────────────────────────────────────────────────────────

    private function getPos(string $query = '')
    {
        return $this->actingAs(User::on('tenant')->find($this->ownerId), 'tenant')
            ->get('http://' . $this->host . '/pos' . $query);
    }

    /** @return array{html:string, products:array, recall:array} */
    private function loadPos(): array
    {
        $res = $this->getPos();
        $this->assertSame(200, $res->getStatusCode(), 'the POS screen must load; got ' . $res->getStatusCode()
            . ' — ' . Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags($res->getContent()))), 300));
        $html = $res->getContent();

        $this->assertSame(1, preg_match('/const products\s*=\s*(\[.*?\]);\n/s', $html, $p), 'POS products list not found');
        $this->assertSame(1, preg_match('/const recallProducts\s*=\s*(\[.*?\]);\n/s', $html, $r), 'recall list not found');

        return ['html' => $html, 'products' => json_decode($p[1], true), 'recall' => json_decode($r[1], true)];
    }

    private function ids(array $list): array
    {
        return array_map('intval', array_column($list, 'id'));
    }

    /** A held website delivery order, read back through the held-sales API exactly as the website window does. */
    private function saveWebsiteOrder(int $riceId): array
    {
        $saleId = $this->makeSale($this->branchId, [
            'sale_no' => 'HS-' . now()->format('YmdHis') . '-' . random_int(100, 999),
            'status' => 'held', 'order_type' => 'delivery', 'order_source' => 'website',
            'subtotal' => 4150, 'delivery_charge_amount' => 350, 'grand_total' => 4500,
        ]);
        $this->makeSaleLine($saleId, $riceId, ['product_name' => 'Singaporean Rice (Khass)', 'quantity' => 1,
            'unit_price' => 2900, 'line_total' => 2900, 'kot_sent' => 1, 'kot_sent_quantity' => 1]);
        $this->makeSaleLine($saleId, $this->pastaId, ['product_name' => 'Chicken Red Sauce Pasta (1 Person)', 'quantity' => 1,
            'unit_price' => 1250, 'line_total' => 1250, 'kot_sent' => 1, 'kot_sent_quantity' => 1]);

        $res = $this->actingAs(User::on('tenant')->find($this->ownerId), 'tenant')
            ->getJson('http://' . $this->host . '/api/pos/held-sales?branch_id=' . $this->branchId);
        $this->assertSame(200, $res->getStatusCode(), 'held-sales API: ' . Str::limit($res->getContent(), 300));
        $sale = collect($res->json('sales'))->firstWhere('id', $saleId);
        $this->assertNotNull($sale, 'the held-sales API must hand the order over');
        $this->assertCount(2, $sale['lines']);

        return $sale;
    }

    /**
     * Run the POS's own recallHeldSale() — taken from the rendered page together with the helpers it calls
     * and the page's own two lists — against small stand-ins for the screen around it.
     */
    private function runRecall(array $page, array $sale, array $before = []): array
    {
        $html = $page['html'];
        $source = implode("\n", array_map(fn ($fn) => $this->extractFunction($html, $fn),
            ['escapeHtml', 'productForHeldLine', 'heldBillMissingItems', 'showHeldBillNotOpened', 'recallHeldSale']));

        $script = <<<'JS'
var calls = { swal: [] };
var window = {};
var document = { querySelector: function () { return null; }, querySelectorAll: function () { return []; },
                 getElementById: function () { return null; } };
var bootstrap = { Modal: { getInstance: function () { return null; } } };
var heldSalesModalEl = null;
var Swal = { fire: function (o) { calls.swal.push(o); return { then: function (f) { if (f) f({}); return this; } }; } };
var medicalCounter = false, counterPacks = false;
var orderTypeEl = null, deliveryChannelEl = null, deliveryRiderEl = null;
var _currentHeldSaleId = null, _currentHeldSaleNo = '', _currentHeldSaleIsDraft = false;
var _manualDiscountType = 'none', _manualDiscountValue = 0;
var cart = [];
function normalizeModifiers(m) { return m || []; }
function cartKey(p, v) { return 'p' + p.id + ':' + (v ? v.id : 0); }
function productPrice(p) { return Number(p.price || 0); }
function modifierPriceDelta() { return 0; }
function enteredLabel() { return null; }
function medAdoptLine() {} function packAdoptLine() {} function rememberLastSale() {} function medRxFromNotes() {}
function updateDeliveryPanel() {} function renderCart() {} function updateSplitBillBtn() {} function updateRecalledBar() {}
function lockOrderControls() {} function toast() {}
JS;
        $script .= "\nconst products = " . json_encode($page['products']) . ";\n"
            . 'const recallProducts = ' . json_encode($page['recall']) . ";\n"
            . $source . "\n"
            . implode("\n", $before) . "\n"
            . 'recallHeldSale(' . json_encode($sale) . ");\n"
            . 'console.log(JSON.stringify({ cart: cart.map(function (i) { return { name: i.name, product_id: i.product_id,'
            . ' quantity: i.quantity, unit_price: i.unit_price }; }), heldId: _currentHeldSaleId,'
            . ' swal: calls.swal.map(function (o) { return { titleText: o.titleText || "", html: o.html || "" }; }) }));' . "\n";

        $node = $this->nodeBinary();
        $file = sys_get_temp_dir() . '/recallhid_' . Str::random(8) . '.js';
        file_put_contents($file, $script);
        $raw = shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($file) . ' 2>&1');
        @unlink($file);

        $decoded = json_decode(trim((string) $raw), true);
        $this->assertIsArray($decoded, 'node did not answer with JSON: ' . $raw);

        return $decoded;
    }

    /** `function X(...) { … }` — the whole source, by counting braces. */
    private function extractFunction(string $html, string $name): string
    {
        $start = strpos($html, 'function ' . $name . '(');
        $this->assertNotFalse($start, "[{$name}] is on the page");

        $depth = 0;
        for ($i = strpos($html, '{', $start); $i < strlen($html); $i++) {
            if ($html[$i] === '{') { $depth++; }
            elseif ($html[$i] === '}') { $depth--; if ($depth === 0) { return substr($html, $start, $i - $start + 1); } }
        }

        $this->fail("[{$name}] has no end");
    }

    private function seedMaster(): void
    {
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        $master = DB::connection('master');

        $master->table('tenant_domains')->where('domain', $this->host)->delete();
        $master->table('tenants')->where('tenant_code', 'recallhid')->delete();

        $this->tenantId = $master->table('tenants')->insertGetId([
            'tenant_code' => 'recallhid', 'business_name' => 'Recall Hidden',
            'owner_name' => 'Owner', 'owner_email' => 'owner@recallhid.test',
            'currency_code' => 'PKR', 'status' => 'active', 'is_demo' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $master->table('tenant_databases')->insert([
            'tenant_id' => $this->tenantId, 'db_connection' => 'tenant',
            'db_host' => config('database.connections.tenant.host'),
            'db_port' => (int) config('database.connections.tenant.port'),
            'db_database' => $this->tenantDb,
            'db_username' => config('database.connections.tenant.username'),
            'db_password' => null,
            'migration_status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $master->table('tenant_domains')->insert([
            'tenant_id' => $this->tenantId, 'domain' => $this->host, 'is_primary' => 1,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A live plan carrying the module that owns the POS route, found the way the middleware finds it. */
    private function seedSubscription(): void
    {
        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        $m = DB::connection('master');

        $planId = $m->table('plans')->where('code', 'recallhid-plan')->value('id')
            ?: $m->table('plans')->insertGetId([
                'code' => 'recallhid-plan', 'name' => 'Recall Hidden', 'price' => 0,
                'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        $m->table('plan_modules')->where('plan_id', $planId)->delete();

        // The POS screen's module MUST be enabled, or the page 403s and these tests prove nothing. The held-sales
        // API maps to `tenant.api` once the route catalog is synced (another test in the run may sync it) — that key
        // is always allowed by TenantSubscriptionAccessService and no module claims it, so it needs nothing here.
        foreach (['tenant.pos.index' => true, 'tenant.api.pos.held-sales' => false] as $route => $mustHaveModule) {
            $key = $m->table('route_catalogs')->where('route_name', $route)->value('module_key');
            $module = $key ? Module::forRouteModuleKey($key)->first() : null;
            if ($key && $mustHaveModule) {
                $this->assertNotNull($module, "route [{$route}] maps to [{$key}] but no module claims it");
            }
            if ($module) {
                $m->table('plan_modules')->updateOrInsert(['plan_id' => $planId, 'module_id' => $module->id], ['is_enabled' => 1]);
            }
        }

        $m->table('subscriptions')->where('tenant_id', $this->tenantId)->delete();
        $m->table('subscriptions')->insert([
            'tenant_id' => $this->tenantId, 'plan_id' => $planId, 'status' => 'active',
            'current_period_ends_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedTenant(): void
    {
        // permissions/roles come from a tenant MIGRATION — never truncate them.
        $this->cleanTenant([
            'sales_order_lines', 'sales_orders', 'combo_components', 'combos',
            'model_has_roles', 'users', 'products', 'categories', 'branches',
        ]);

        DB::setDefaultConnection('tenant');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $c = DB::connection('tenant');

        $ownerRole = $c->table('roles')->where('name', 'Owner')->where('guard_name', 'tenant')->value('id')
            ?: $c->table('roles')->insertGetId([
                'name' => 'Owner', 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now(),
            ]);

        foreach (['tenant.pos.index', 'tenant.api.pos.held-sales'] as $route) {
            $c->table('permissions')->updateOrInsert(['name' => $route, 'guard_name' => 'tenant'],
                ['created_at' => now(), 'updated_at' => now()]);
        }
        foreach ($c->table('permissions')->where('guard_name', 'tenant')->pluck('id') as $permId) {
            $c->table('role_has_permissions')->updateOrInsert(['permission_id' => $permId, 'role_id' => $ownerRole], []);
        }

        $this->ownerId = $c->table('users')->insertGetId([
            'name' => 'RecallOwner', 'email' => 'owner@recallhid.test', 'password' => bcrypt('x'),
            'employee_code' => 'RHOWN', 'status' => 'active', 'locale' => 'en',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $c->table('model_has_roles')->insert([
            'role_id' => $ownerRole, 'model_type' => User::class, 'model_id' => $this->ownerId,
        ]);

        $this->branchId = $this->makeBranch();
        $this->categoryId = $this->makeCategory(['name' => 'Rice', 'slug' => 'rice-' . Str::random(4)]);
        $this->riceId = $this->makeProduct($this->categoryId, ['name' => 'Singaporean Rice (Khass)',
            'default_selling_price' => 2900, 'is_pos_visible' => 0, 'is_sellable' => 1, 'is_stock_tracked' => 0]);
        $this->pastaId = $this->makeProduct($this->categoryId, ['name' => 'Chicken Red Sauce Pasta (1 Person)',
            'default_selling_price' => 1250, 'is_pos_visible' => 1, 'is_sellable' => 1, 'is_stock_tracked' => 0]);

        DB::setDefaultConnection(config('tenancy.master_connection', 'master'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
