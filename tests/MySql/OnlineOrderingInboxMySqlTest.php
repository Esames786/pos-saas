<?php

namespace Tests\MySql;

use App\Models\Tenant\Branch;
use App\Models\Tenant\Category;
use App\Models\Tenant\Combo;
use App\Models\Tenant\DeliveryChannel;
use App\Models\Tenant\DeliveryRider;
use App\Models\Tenant\DeliveryZone;
use App\Models\Tenant\Product;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\User;
use App\Models\Tenant\WebOrderRoute;
use App\Models\Tenant\WebSetting;
use App\Services\OnlineOrdering\WebsiteOrderService;
use App\Services\Permissions\PermissionSyncService;
use App\Services\Sales\ShiftService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\MySql\Support\ParsesInlineScripts;
use Tests\MySql\Support\TenantFixtures;

/**
 * ONLINE-ORDERING-1 (Phase 3) — POS par website orders: ghanti/inbox, Hold (rider ke saath), auto-cancel.
 * Malik: "POS pe jo order modal hoga wahan to bs order receive hore hon gay"; "rider usi modal me";
 * "auto cancel off karde to auto cancel na ho"; aur sab se upar: "POS kharab na ho".
 */
class OnlineOrderingInboxMySqlTest extends MySqlTenantTestCase
{
    use ParsesInlineScripts;
    use TenantFixtures;

    private string $host;

    private int $tenantId;

    private int $branchId;

    private int $t1;

    private int $t2;

    private int $userId;

    private int $zoneId;

    private Combo $deal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);
        $this->host = 'ooinbox.'.config('tenancy.tenant_base_domain');
        $this->seedMaster();
        $this->seedPlan(true);
        app(PermissionSyncService::class)->syncRouteCatalog();

        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'customer_addresses', 'customers', 'sales_order_lines', 'sales_orders', 'shifts', 'web_order_routes', 'web_settings',
            'delivery_zones', 'delivery_riders', 'delivery_channels', 'combo_modifier_group', 'product_modifier_group', 'modifiers',
            'modifier_groups', 'combo_components', 'combos', 'product_branch_prices', 'product_variants', 'products', 'categories',
            'model_has_permissions', 'model_has_roles', 'terminals', 'users', 'branches',
        ]);
        $this->userId = $this->makeUser(['employee_code' => 'IB'.Str::random(4)]);
        $this->branchId = $this->makeBranch();
        $this->t1 = $this->makeTerminal($this->branchId);
        $this->t2 = $this->makeTerminal($this->branchId);

        $cat = Category::create(['name' => 'Deals', 'slug' => 'deals-'.uniqid(), 'sort_order' => 1, 'is_active' => true, 'show_on_website' => true]);
        $rice = Product::create(['sku' => 'GR-'.uniqid(), 'name' => 'Garlic Rice', 'slug' => 'gr-'.uniqid(), 'category_id' => $cat->id,
            'product_type' => 'simple', 'product_kind' => 'sale_item', 'default_selling_price' => 900, 'is_sellable' => true,
            'is_pos_visible' => true, 'status' => 'active', 'is_stock_tracked' => false, 'inventory_consumption_method' => 'none']);
        $this->deal = Combo::create(['name' => 'Midnight Deal', 'price' => 1550, 'status' => 'active', 'category_id' => $cat->id, 'show_on_website' => true]);
        $this->deal->components()->create(['product_id' => $rice->id, 'quantity' => 1, 'sort_order' => 1]);

        WebSetting::tenantDefault()->update(['is_enabled' => true, 'default_branch_id' => $this->branchId,
            'hours' => array_map(fn () => ['open' => '00:00', 'close' => '23:59', 'closed' => false], WebSetting::DAYS)]);
        WebOrderRoute::create(['branch_id' => $this->branchId, 'order_type' => 'delivery', 'terminal_id' => $this->t1]);
        DeliveryChannel::create(['name' => 'Website', 'type' => 'own', 'commission_percent' => 0, 'is_active' => true, 'sort_order' => 3]);
        $this->zoneId = DeliveryZone::create(['geo_area_id' => 991001, 'area_name' => 'Gulshan Block 2', 'city' => 'Karachi',
            'branch_id' => $this->branchId, 'fee' => 50, 'is_active' => true])->id;
        app(ShiftService::class)->open(Branch::find($this->branchId), Terminal::find($this->t1), $this->userId, 0.0);

        $this->grant(['tenant.pos.index', 'tenant.held-sales.store']);
    }

    protected function tearDown(): void
    {
        try {
            $m = DB::connection('master');
            $m->table('tenant_domains')->where('domain', $this->host)->delete();
            $m->table('tenant_databases')->where('tenant_id', $this->tenantId)->delete();
            $m->table('subscriptions')->where('tenant_id', $this->tenantId)->delete();
            $m->table('tenants')->where('id', $this->tenantId)->delete();
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    public function test_new_website_orders_ring_only_on_the_routed_counter(): void
    {
        $sale = $this->webOrder();

        $j = $this->cashier()->getJson($this->url('/api/pos/website-orders?branch_id='.$this->branchId.'&terminal_id='.$this->t1))->assertOk()->json();
        $this->assertTrue($j['routed'], 'T1 par ghanti bajni chahiye.');
        $this->assertSame([$sale->id], array_column($j['orders'], 'id'));
        $row = $j['orders'][0];
        $this->assertTrue($row['is_draft']);
        $this->assertSame('new', $row['status']);
        $this->assertSame(['new' => 1, 'confirmed' => 0, 'paid' => 0, 'cancelled' => 0, 'no_rider' => 0], $j['counts']);
        $this->assertTrue($row['needs_rider']);
        $this->assertSame('Gulshan Block 2', $row['area']);
        $this->assertSame('Midnight Deal', $row['items'][0]['name'], 'Deal ka naam, components nahi.');
        $this->assertCount(1, $row['items']);

        $j2 = $this->cashier()->getJson($this->url('/api/pos/website-orders?branch_id='.$this->branchId.'&terminal_id='.$this->t2))->assertOk()->json();
        $this->assertFalse($j2['routed'], 'Doosre counter par ghanti nahi.');

        // Malik: multi-select — dono chune terminals par ghanti.
        WebOrderRoute::where('branch_id', $this->branchId)->update(['terminal_ids' => json_encode([$this->t1, $this->t2])]);
        $this->assertTrue($this->cashier()->getJson($this->url('/api/pos/website-orders?branch_id='.$this->branchId.'&terminal_id='.$this->t2))->json('routed'));

        // Plan me module nahi: kuch nahi.
        $this->seedPlan(false);
        $this->cashier()->getJson($this->url('/api/pos/website-orders?branch_id='.$this->branchId.'&terminal_id='.$this->t1))
            ->assertOk()->assertJson(['enabled' => false, 'orders' => []]);
    }

    public function test_holding_a_website_order_needs_a_rider_and_the_right_to_hold(): void
    {
        $sale = $this->webOrder();
        $rider = DeliveryRider::create(['branch_id' => $this->branchId, 'name' => 'Rider One', 'phone' => '03000000000', 'status' => 'active']);

        // POS ka usool: apne delivery channel par rider lazmi.
        $this->cashier()->postJson($this->url('/api/pos/website-orders/'.$sale->id.'/hold'), [])->assertStatus(422);
        $this->assertTrue($sale->fresh()->is_draft);

        $res = $this->cashier()->postJson($this->url('/api/pos/website-orders/'.$sale->id.'/hold'), ['delivery_rider_id' => $rider->id])->assertOk();
        $this->assertTrue($res->json('send_kot'), 'Draft → Held par KOT POS ke mojooda raaste se.');
        $sale->refresh();
        $this->assertFalse($sale->is_draft);
        $this->assertSame('held', $sale->status);
        $this->assertSame($rider->id, (int) $sale->delivery_rider_id);

        // Hold ka haq nahi to nahi.
        $other = $this->webOrder();
        DB::connection('tenant')->table('model_has_permissions')->where('model_id', $this->userId)->delete();
        $this->grant(['tenant.pos.index']);
        $this->cashier()->postJson($this->url('/api/pos/website-orders/'.$other->id.'/hold'), ['delivery_rider_id' => $rider->id])->assertForbidden();
        $this->assertTrue($other->fresh()->is_draft);
    }

    public function test_an_order_already_confirmed_only_takes_a_rider_and_sends_no_second_kot(): void
    {
        $sale = $this->webOrder();
        $sale->update(['is_draft' => false]);   // WhatsApp link se Held (Phase 4) — rider baqi
        $rider = DeliveryRider::create(['branch_id' => $this->branchId, 'name' => 'Rider Two', 'phone' => '03000000001', 'status' => 'active']);

        $j = $this->cashier()->getJson($this->url('/api/pos/website-orders?branch_id='.$this->branchId.'&terminal_id='.$this->t1))->json();
        $row = collect($j['orders'])->firstWhere('id', $sale->id);
        $this->assertFalse($row['is_draft']);
        $this->assertSame('confirmed', $row['status']);
        $this->assertTrue($row['missing_rider']);
        $this->assertSame(1, $j['counts']['no_rider'], 'Badge me ginti: rider baqi.');

        $res = $this->cashier()->postJson($this->url('/api/pos/website-orders/'.$sale->id.'/hold'), ['delivery_rider_id' => $rider->id])->assertOk();
        $this->assertFalse($res->json('send_kot'), 'KOT pehle ja chuki — dobara nahi.');

        $j = $this->cashier()->getJson($this->url('/api/pos/website-orders?branch_id='.$this->branchId.'&terminal_id='.$this->t1))->json();
        $row = collect($j['orders'])->firstWhere('id', $sale->id);
        $this->assertSame('confirmed', $row['status'], 'Paid hone tak Confirmed tab me.');
        $this->assertFalse($row['missing_rider']);
        $this->assertSame('Rider Two', $row['rider']);
        $this->assertSame(0, $j['counts']['no_rider'], 'Rider lag gaya — badge se nikal gaya.');
    }

    public function test_paid_and_cancelled_orders_have_their_own_tabs_for_today(): void
    {
        $new = $this->webOrder();
        $paid = $this->webOrder();
        $cancelled = $this->webOrder();
        $counter = $this->webOrder();
        SalesOrder::whereKey($paid->id)->update(['status' => 'paid', 'is_draft' => false]);
        SalesOrder::whereKey($cancelled->id)->update(['status' => 'cancelled']);
        SalesOrder::whereKey($counter->id)->update(['status' => 'paid', 'order_source' => 'pos']);
        $q = '/api/pos/website-orders?branch_id='.$this->branchId.'&terminal_id='.$this->t1;

        $j = $this->cashier()->getJson($this->url($q))->assertOk()->json();
        $this->assertSame([$new->id], array_column($j['orders'], 'id'), 'Poll sirf khule (New + Confirmed).');
        $this->assertSame(1, $j['counts']['paid']);
        $this->assertSame(1, $j['counts']['cancelled']);

        $paidTab = $this->cashier()->getJson($this->url($q.'&tab=paid'))->assertOk()->json();
        $this->assertSame('paid', $paidTab['tab']);
        $this->assertSame([$paid->id], array_column($paidTab['orders'], 'id'), 'Counter ka order website tab me nahi.');
        $this->assertSame('paid', $paidTab['orders'][0]['status']);

        $cancelTab = $this->cashier()->getJson($this->url($q.'&tab=cancelled'))->assertOk()->json();
        $this->assertSame([$cancelled->id], array_column($cancelTab['orders'], 'id'));

        // Kal ka paid order aaj ke tab me nahi.
        SalesOrder::whereKey($paid->id)->update(['business_date' => now()->subDays(2)->toDateString()]);
        $this->assertSame([], $this->cashier()->getJson($this->url($q.'&tab=paid'))->json('orders'));
    }

    public function test_auto_cancel_only_takes_old_drafts_and_never_a_held_order(): void
    {
        $old = $this->webOrder();
        $young = $this->webOrder();
        $heldOld = $this->webOrder();
        SalesOrder::whereKey([$old->id, $heldOld->id])->update(['created_at' => now()->subMinutes(30)]);
        $heldOld->update(['is_draft' => false]);

        // OFF (default): kuch nahi.
        $this->artisan('online-ordering:auto-cancel', ['--tenant' => 'ooinbox'])->assertSuccessful();
        $this->assertSame('held', $old->fresh()->status);

        WebSetting::tenantDefault()->update(['auto_cancel_minutes' => 20]);
        $this->artisan('online-ordering:auto-cancel', ['--tenant' => 'ooinbox'])->assertSuccessful();

        $this->assertSame('cancelled', $old->fresh()->status);
        $this->assertSame('auto', $old->fresh()->web_meta['cancelled_by']);
        $this->assertSame('held', $young->fresh()->status, '20 minute abhi nahi hue.');
        $this->assertSame('held', $heldOld->fresh()->status, 'Held (confirmed) order kabhi auto-cancel nahi.');
    }

    public function test_the_pos_screen_carries_the_website_inbox_only_with_the_module_and_still_parses(): void
    {
        $html = $this->cashier()->get($this->url('/pos'))->assertOk()->getContent();
        $this->assertStringContainsString('id="web-orders-btn"', $html);
        $this->assertStringContainsString('window.posWebBridge', $html);
        // Recall bina reload — Held Orders wala hi raasta bridge par.
        $this->assertStringContainsString('recallHeldSale: recallHeldSale', $html);
        $this->assertStringContainsString('bridge().recallHeldSale(sale)', $html);
        $this->assertInlineScriptsParse($html, 'POS with website inbox', 'web-orders-btn');

        $this->seedPlan(false);
        $html = $this->cashier()->get($this->url('/pos'))->assertOk()->getContent();
        $this->assertStringNotContainsString('id="web-orders-btn"', $html, 'Module ke baghair POS bilkul waisa.');
        $this->assertInlineScriptsParse($html, 'POS without website inbox', 'renderCart');
    }

    // ── helpers ──

    private function webOrder(): SalesOrder
    {
        return app(WebsiteOrderService::class)->place([
            'key' => (string) Str::uuid(), 'type' => 'delivery', 'area' => $this->zoneId,
            'customer' => ['name' => 'Test Customer', 'phone' => '0300 1234567', 'address' => 'House 1', 'landmark' => 'Park'],
            'lines' => [['option' => 'combo:'.$this->deal->id, 'qty' => 1]],
        ]);
    }

    private function cashier()
    {
        return $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
    }

    private function url(string $p): string
    {
        return 'http://'.$this->host.$p;
    }

    private function grant(array $names): void
    {
        foreach ($names as $n) {
            Permission::findOrCreate($n, 'tenant');
        }
        User::on('tenant')->find($this->userId)->givePermissionTo($names);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function seedMaster(): void
    {
        DB::setDefaultConnection('master');
        $m = DB::connection('master');
        $m->table('tenant_domains')->where('domain', $this->host)->delete();
        $old = $m->table('tenants')->where('tenant_code', 'ooinbox')->value('id');
        if ($old) {
            $m->table('subscriptions')->where('tenant_id', $old)->delete();
            $m->table('tenant_databases')->where('tenant_id', $old)->delete();
            $m->table('tenants')->where('id', $old)->delete();
        }
        $this->tenantId = $m->table('tenants')->insertGetId([
            'tenant_code' => 'ooinbox', 'business_name' => 'Inbox Foods', 'owner_name' => 'Owner',
            'owner_email' => 'owner@ooinbox.test', 'currency_code' => 'PKR', 'status' => 'active', 'is_demo' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $m->table('tenant_databases')->where('db_database', $this->tenantDb)->delete();
        $m->table('tenant_databases')->insert([
            'tenant_id' => $this->tenantId, 'db_connection' => 'tenant',
            'db_host' => config('database.connections.tenant.host'), 'db_port' => (int) config('database.connections.tenant.port'),
            'db_database' => $this->tenantDb, 'db_username' => config('database.connections.tenant.username'), 'db_password' => null,
            'migration_status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $m->table('tenant_domains')->insert([
            'tenant_id' => $this->tenantId, 'domain' => $this->host, 'is_primary' => 1, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedPlan(bool $withModule): void
    {
        $m = DB::connection('master');
        $planId = $m->table('plans')->where('code', 'ooinbox-plan')->value('id')
            ?: $m->table('plans')->insertGetId(['code' => 'ooinbox-plan', 'name' => 'Inbox', 'price' => 0,
                'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $m->table('plan_modules')->where('plan_id', $planId)->delete();
        foreach ($m->table('modules')->get(['id', 'key']) as $module) {
            if ($module->key === 'online_ordering' && ! $withModule) {
                continue;
            }
            $m->table('plan_modules')->insert(['plan_id' => $planId, 'module_id' => $module->id, 'is_enabled' => 1]);
        }
        $m->table('subscriptions')->where('tenant_id', $this->tenantId)->delete();
        $m->table('subscriptions')->insert(['tenant_id' => $this->tenantId, 'plan_id' => $planId, 'status' => 'active',
            'current_period_ends_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now()]);
        if (app()->bound('tenant')) {
            app()->forgetInstance('tenant');
        }
    }
}
