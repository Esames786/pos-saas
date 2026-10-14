<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\HeldSaleController;
use App\Http\Controllers\Tenant\SalesOrderController;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Category;
use App\Models\Tenant\Combo;
use App\Models\Tenant\DeliveryChannel;
use App\Models\Tenant\DeliveryZone;
use App\Models\Tenant\ModifierGroup;
use App\Models\Tenant\Product;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\User;
use App\Models\Tenant\WebOrderRoute;
use App\Models\Tenant\WebSetting;
use App\Services\Permissions\PermissionSyncService;
use App\Services\Sales\ShiftService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\TenantFixtures;

/**
 * ONLINE-ORDERING-1 (Phase 2c) — website ka order POS par DRAFT. Malik: "bas mera POS kharab na ho".
 *
 * Sab se ahem guard: wohi cart website se aur POS ke APNE "Save as draft" (HeldSaleController) se —
 * lines aur totals barabar. Website ka draft POS ke liye bilkul counter ke draft jaisa hai, is liye
 * recall, Hold (KOT), Pay, stock aur reports waise hi chalte hain.
 */
class OnlineOrderingCheckoutMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private string $host;

    private int $tenantId;

    private int $branchId;

    private int $terminalId;

    private int $userId;

    private Product $zinger;

    private Combo $deal;

    private int $pepsi;

    private int $cheese;

    private int $drinkGroup;

    private int $cheeseGroup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);
        $this->host = 'oocheck.'.config('tenancy.tenant_base_domain');
        $this->seedMaster();
        $this->seedPlan();
        app(PermissionSyncService::class)->syncRouteCatalog();

        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'customer_addresses', 'customers', 'sale_payments', 'sales_order_lines', 'sales_orders', 'shifts', 'web_order_routes', 'web_settings',
            'delivery_zones', 'combo_modifier_group', 'product_modifier_group', 'modifiers', 'modifier_groups', 'combo_components',
            'combos', 'product_branch_prices', 'product_variants', 'products', 'categories', 'delivery_channels', 'payment_methods',
            'terminals', 'users', 'branches',
        ]);
        $this->userId = $this->makeUser(['employee_code' => 'OC'.Str::random(4)]);
        $this->branchId = $this->makeBranch();
        $this->terminalId = $this->makeTerminal($this->branchId);
        $this->catalog();

        WebSetting::tenantDefault()->update([
            'is_enabled' => true, 'default_branch_id' => $this->branchId,
            'hours' => array_map(fn () => ['open' => '00:00', 'close' => '23:59', 'closed' => false], WebSetting::DAYS),
        ]);
        WebOrderRoute::create(['branch_id' => $this->branchId, 'order_type' => 'delivery', 'terminal_id' => $this->terminalId]);
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

    public function test_a_website_order_is_the_same_draft_the_counter_would_save(): void
    {
        $this->openShift();

        $web = $this->checkout(['type' => 'pickup', 'lines' => $this->cart()])->assertCreated();
        $webSale = SalesOrder::where('sale_no', $web->json('order'))->sole();

        // Wohi cart, POS ke apne "Save as draft" se — jaise cashier banata.
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');
        $mods = json_encode($webSale->lines->firstWhere('line_kind', 'standard')->modifiers);
        $components = $this->deal->components()->orderBy('sort_order')->orderBy('id')->get();
        $req = Request::create('/held-sales', 'POST', [
            'branch_id' => $this->branchId, 'terminal_id' => $this->terminalId, 'order_type' => 'takeaway',
            'discount_type' => 'none', 'save_as_draft' => 1,
            'lines' => array_merge(
                [['product_id' => $this->zinger->id, 'quantity' => 2, 'unit_price' => 680, 'client_line_key' => 'z', 'modifiers' => $mods]],
                [['product_id' => $components[0]->product_id, 'quantity' => 1, 'unit_price' => 1550, 'line_kind' => 'combo_header',
                    'combo_id' => $this->deal->id, 'line_name' => $this->deal->name, 'client_line_key' => 'combo:x']],
                $components->map(fn ($c) => ['product_id' => $c->product_id, 'quantity' => (float) $c->quantity, 'unit_price' => 0,
                    'line_kind' => 'component', 'combo_id' => $this->deal->id, 'parent_client_line_key' => 'combo:x'])->all(),
            ),
        ]);
        $req->headers->set('Accept', 'application/json');
        $res = app()->call([app(HeldSaleController::class), 'store'], ['request' => $req]);
        $this->assertContains($res->getStatusCode(), [200, 201], $res->getContent());
        $posSale = SalesOrder::latest('id')->firstOrFail();
        $this->assertNotSame($webSale->id, $posSale->id);

        $this->assertSame($this->shape($posSale), $this->shape($webSale), 'Website ka draft POS ke draft se line-by-line alag hai.');
        foreach (['status', 'is_draft', 'order_type', 'terminal_id', 'shift_id', 'subtotal', 'tax_amount', 'service_charge_amount', 'delivery_charge_amount', 'grand_total'] as $f) {
            $this->assertEquals($posSale->{$f}, $webSale->{$f}, "Field {$f} alag hai.");
        }
        $this->assertSame($posSale->business_date->toDateString(), $webSale->business_date->toDateString());

        // Website ki apni pehchan — POS par asar nahi.
        $this->assertSame('website', $webSale->order_source);
        $this->assertSame('pos', $posSale->order_source);
        $this->assertSame('held', $webSale->status);
        $this->assertTrue($webSale->is_draft, 'Draft = POS par ghanti, KOT abhi nahi.');
        $this->assertSame(0, $webSale->lines->where('kot_sent', true)->count());
        $this->assertSame('2026', substr((string) $webSale->sale_no, 3, 4));
    }

    public function test_a_delivery_order_takes_the_area_fee_the_customer_and_the_existing_website_channel(): void
    {
        $this->openShift();
        $existing = DeliveryChannel::create(['name' => 'Website', 'type' => 'own', 'commission_percent' => 0, 'is_active' => true, 'sort_order' => 3]);
        $zone = DeliveryZone::create(['geo_area_id' => 990001, 'area_name' => 'Gulshan Block 2', 'city' => 'Karachi',
            'branch_id' => $this->branchId, 'fee' => 50, 'free_delivery_above' => 5000, 'eta_minutes' => 40, 'is_active' => true]);

        $res = $this->checkout(['type' => 'delivery', 'area' => $zone->id, 'lines' => [['option' => 'product:'.$this->zinger->id, 'qty' => 1, 'addons' => [$this->pepsi]]],
            'customer' => ['name' => 'Test Customer', 'phone' => '+92 300 1234567', 'address' => 'House 12, Street 4', 'landmark' => 'Near the park', 'lat' => 24.92, 'lng' => 67.09]])
            ->assertCreated();
        $sale = SalesOrder::where('sale_no', $res->json('order'))->sole();

        $this->assertSame('delivery', $sale->order_type);
        $this->assertSame('50.00', $sale->delivery_charge_amount);
        $this->assertSame($existing->id, (int) $sale->delivery_channel_id, 'Mojooda "Website" channel — naya nahi.');
        $this->assertSame(1, DeliveryChannel::whereRaw('LOWER(name) = ?', ['website'])->count());
        $this->assertNull($sale->delivery_rider_id, 'Rider cashier POS par chunega.');
        $this->assertSame('03001234567', $sale->customer_phone);
        $this->assertNotNull($sale->customer_id);
        $this->assertStringContainsString('House 12', $sale->delivery_address);
        $this->assertSame(1, DB::connection('tenant')->table('customer_addresses')->where('customer_id', $sale->customer_id)->count());
        $this->assertSame('Near the park', $sale->web_meta['landmark']);
        $this->assertSame(40, $sale->web_meta['eta_minutes']);
        $this->assertStringContainsString('WEBSITE ORDER', $sale->notes);
        $this->assertStringContainsString('maps.google.com', $sale->notes);

        // Wohi customer dobara: naya customer nahi; "itne se upar free" par fee 0.
        $res2 = $this->checkout(['type' => 'delivery', 'area' => $zone->id, 'lines' => [['option' => 'combo:'.$this->deal->id, 'qty' => 4]],
            'customer' => ['name' => 'Someone Else', 'phone' => '03001234567', 'address' => 'House 12, Street 4', 'landmark' => 'Near the park']])->assertCreated();
        $sale2 = SalesOrder::where('sale_no', $res2->json('order'))->sole();
        $this->assertSame($sale->customer_id, $sale2->customer_id);
        $this->assertSame('0.00', $sale2->delivery_charge_amount);
        $this->assertSame(1, DB::connection('tenant')->table('customer_addresses')->where('customer_id', $sale->customer_id)->count(), 'Address dobara nahi juRta.');
    }

    public function test_the_server_prices_the_order_and_refuses_what_is_not_on_sale(): void
    {
        $this->openShift();

        // Customer qeemat bhej hi nahi sakta; jo bheje wo nazarandaz.
        $res = $this->checkout(['type' => 'pickup', 'lines' => [['option' => 'product:'.$this->zinger->id, 'qty' => 1, 'addons' => [$this->pepsi], 'price' => 1]]])->assertCreated();
        $this->assertSame('580.00', SalesOrder::where('sale_no', $res->json('order'))->sole()->lines->first()->unit_price);

        $this->checkout(['type' => 'pickup', 'lines' => [['option' => 'product:'.$this->zinger->id, 'qty' => 1]]])
            ->assertStatus(422)->assertJsonValidationErrors('lines');   // "Select Your Drink" lazmi

        $this->checkout(['type' => 'pickup', 'lines' => [['option' => 'product:'.$this->zinger->id, 'qty' => 1, 'addons' => [$this->pepsi, 999999]]]])
            ->assertStatus(422)->assertJsonValidationErrors('lines');   // gharha hua add-on

        $this->zinger->update(['status' => 'inactive']);
        $this->checkout(['type' => 'pickup', 'lines' => [['option' => 'product:'.$this->zinger->id, 'qty' => 1, 'addons' => [$this->pepsi]]]])
            ->assertStatus(422)->assertJsonValidationErrors('lines');   // ab bikta nahi

        $this->assertSame(1, SalesOrder::count());
    }

    public function test_a_minimum_order_is_enforced_and_an_unknown_area_is_refused(): void
    {
        $this->openShift();
        $zone = DeliveryZone::create(['geo_area_id' => 990002, 'area_name' => 'DHA Phase 6', 'city' => 'Karachi',
            'branch_id' => $this->branchId, 'fee' => 400, 'min_order' => 2000, 'is_active' => true]);

        $this->checkout(['type' => 'delivery', 'area' => $zone->id, 'lines' => [['option' => 'product:'.$this->zinger->id, 'qty' => 1, 'addons' => [$this->pepsi]]]])
            ->assertStatus(422)->assertJsonValidationErrors('min_order');
        $this->checkout(['type' => 'delivery', 'area' => 999999, 'lines' => [['option' => 'combo:'.$this->deal->id, 'qty' => 2]]])
            ->assertStatus(422)->assertJsonValidationErrors('area');
        $this->assertSame(0, SalesOrder::count());
    }

    public function test_a_closed_website_or_an_empty_counter_refuses_and_a_double_tap_is_one_order(): void
    {
        // Koi shift nahi: order aaye aur kahin ghanti na baje — website mana kare.
        $this->checkout(['type' => 'pickup', 'lines' => [['option' => 'combo:'.$this->deal->id, 'qty' => 1]]])
            ->assertStatus(422)->assertJsonValidationErrors('closed');

        $this->openShift();
        WebSetting::tenantDefault()->update(['is_enabled' => false]);
        $this->checkout(['type' => 'pickup', 'lines' => [['option' => 'combo:'.$this->deal->id, 'qty' => 1]]])
            ->assertStatus(422)->assertJsonValidationErrors('closed');

        WebSetting::tenantDefault()->update(['is_enabled' => true]);
        $key = (string) Str::uuid();
        $a = $this->checkout(['key' => $key, 'type' => 'pickup', 'lines' => [['option' => 'combo:'.$this->deal->id, 'qty' => 1]]])->assertCreated();
        $b = $this->checkout(['key' => $key, 'type' => 'pickup', 'lines' => [['option' => 'combo:'.$this->deal->id, 'qty' => 1]]])->assertCreated();
        $this->assertSame($a->json('order'), $b->json('order'));
        $this->assertSame(1, SalesOrder::count(), 'Do dafa "Place order" = aik order.');

        // Customer ka status safha.
        $html = $this->get($a->json('status_url'))->assertOk()->getContent();
        $this->assertStringContainsString('Order received', $html);
        $this->assertStringContainsString($a->json('order'), $html);
    }

    public function test_paying_a_website_order_at_the_counter_keeps_it_a_website_order(): void
    {
        $this->openShift();
        $res = $this->checkout(['type' => 'pickup', 'lines' => [['option' => 'product:'.$this->zinger->id, 'qty' => 1, 'addons' => [$this->pepsi]]]])->assertCreated();
        $sale = SalesOrder::where('sale_no', $res->json('order'))->sole();

        // Cashier recall kar ke paise leta hai — POS ka form hamesha order_source=pos bhejta hai.
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');
        $line = $sale->lines->first();
        $req = Request::create('/pos', 'POST', [
            'held_sale_id' => $sale->id, 'branch_id' => $this->branchId, 'terminal_id' => $this->terminalId,
            'order_type' => 'takeaway', 'order_source' => 'pos', 'discount_type' => 'none', 'client_uuid' => (string) Str::uuid(),
            'lines' => [['sales_order_line_id' => $line->id, 'product_id' => $line->product_id, 'quantity' => 1, 'unit_price' => (float) $line->unit_price,
                'modifiers' => json_encode($line->modifiers)]],
            'payments' => [['payment_method_id' => $this->makePaymentMethod(), 'amount' => (float) $sale->grand_total]],
        ]);
        $req->headers->set('Accept', 'application/json');
        $resp = app()->call([app(SalesOrderController::class), 'store'], ['request' => $req]);
        $this->assertContains($resp->getStatusCode(), [200, 201], "Pay: ".$resp->getContent());

        $sale->refresh();
        $this->assertSame('paid', $sale->status);
        $this->assertSame('website', $sale->order_source, 'Pay ke baad bhi website order — commission aur reports isi par.');
        $this->assertSame('Near', substr((string) ($sale->web_meta['landmark'] ?? 'Near'), 0, 4), 'web_meta POS ne nahi mitaya.');
    }

    // ── helpers ──

    private function checkout(array $over): \Illuminate\Testing\TestResponse
    {
        $body = array_replace([
            'key' => (string) Str::uuid(),
            'customer' => ['name' => 'Test Customer', 'phone' => '0300 1234567', 'address' => 'House 1', 'landmark' => 'Near the mosque'],
        ], $over);

        return $this->postJson('http://'.$this->host.'/order/checkout', $body);
    }

    private function cart(): array
    {
        return [
            ['option' => 'product:'.$this->zinger->id, 'qty' => 2, 'addons' => [$this->pepsi, $this->cheese]],
            ['option' => 'combo:'.$this->deal->id, 'qty' => 1],
        ];
    }

    /** Line ki wo cheezein jo POS/KOT/stock/report parhte hain — ids aur waqt ke baghair. */
    private function shape(SalesOrder $sale): array
    {
        $byId = $sale->lines->keyBy('id');

        return $sale->lines->sortBy('id')->map(fn ($l) => [
            'line_kind' => $l->line_kind, 'combo_id' => $l->combo_id, 'product_id' => $l->product_id,
            'product_name' => $l->product_name, 'unit_code' => $l->unit_code, 'quantity' => (string) $l->quantity,
            'unit_price' => (string) $l->unit_price, 'line_total' => (string) $l->line_total,
            'discount_amount' => (string) $l->discount_amount, 'tax_amount' => (string) $l->tax_amount,
            'modifiers' => $l->modifiers ?: [], 'kot_sent' => (bool) $l->kot_sent,
            'parent_kind' => $l->parent_sales_order_line_id ? $byId[$l->parent_sales_order_line_id]->line_kind : null,
        ])->values()->all();
    }

    private function openShift(): void
    {
        app(ShiftService::class)->open(Branch::find($this->branchId), Terminal::find($this->terminalId), $this->userId, 0.0);
    }

    private function catalog(): void
    {
        $cat = Category::create(['name' => 'Burgers', 'slug' => 'burgers-'.uniqid(), 'sort_order' => 1, 'is_active' => true, 'show_on_website' => true]);
        $make = fn (string $name, float $price, array $extra = []) => Product::create([
            'sku' => strtoupper(Str::slug($name)).'-'.uniqid(), 'name' => $name, 'slug' => Str::slug($name).'-'.uniqid(), 'category_id' => $cat->id,
            'product_type' => 'simple', 'product_kind' => 'sale_item', 'default_selling_price' => $price,
            'is_sellable' => true, 'is_pos_visible' => true, 'status' => 'active',
            // Restaurant ka bana hua item: stock nahi ginta (Kashif Food jaisa).
            'is_stock_tracked' => false, 'inventory_consumption_method' => 'none',
        ] + $extra);

        $this->zinger = $make('Zinger Burger', 600, ['show_on_website' => true, 'website_price' => 580]);
        $rice = $make('Garlic Rice', 900);
        $shashlik = $make('Shashlik', 800);

        $drinks = ModifierGroup::create(['name' => 'Select Your Drink', 'min_select' => 1, 'max_select' => 1, 'is_required' => true, 'sort_order' => 0, 'status' => 'active', 'channel' => 'website']);
        $this->pepsi = $drinks->modifiers()->create(['name' => 'Pepsi', 'price_delta' => 0, 'sort_order' => 0, 'status' => 'active'])->id;
        $drinks->modifiers()->create(['name' => '7up', 'price_delta' => 0, 'sort_order' => 1, 'status' => 'active']);
        $extra = ModifierGroup::create(['name' => 'Extra Cheese', 'min_select' => 0, 'max_select' => 1, 'is_required' => false, 'sort_order' => 1, 'status' => 'active', 'channel' => 'both']);
        $this->cheese = $extra->modifiers()->create(['name' => 'Cheese Slice', 'price_delta' => 100, 'sort_order' => 0, 'status' => 'active'])->id;
        $this->drinkGroup = $drinks->id;
        $this->cheeseGroup = $extra->id;
        $this->zinger->modifierGroups()->attach([$drinks->id => ['sort_order' => 0], $extra->id => ['sort_order' => 1]]);

        $this->deal = Combo::create(['name' => 'Garlic Rice With Shashlik (Midnight)', 'price' => 1550, 'status' => 'active',
            'category_id' => $cat->id, 'show_on_website' => true]);
        $this->deal->components()->create(['product_id' => $rice->id, 'quantity' => 1, 'sort_order' => 10]);
        $this->deal->components()->create(['product_id' => $shashlik->id, 'quantity' => 2, 'sort_order' => 20]);
    }

    private function seedMaster(): void
    {
        DB::setDefaultConnection('master');
        $m = DB::connection('master');
        $m->table('tenant_domains')->where('domain', $this->host)->delete();
        $old = $m->table('tenants')->where('tenant_code', 'oocheck')->value('id');
        if ($old) {
            $m->table('subscriptions')->where('tenant_id', $old)->delete();
            $m->table('tenant_databases')->where('tenant_id', $old)->delete();
            $m->table('tenants')->where('id', $old)->delete();
        }
        $this->tenantId = $m->table('tenants')->insertGetId([
            'tenant_code' => 'oocheck', 'business_name' => 'Checkout Foods', 'owner_name' => 'Owner',
            'owner_email' => 'owner@oocheck.test', 'currency_code' => 'PKR', 'status' => 'active', 'is_demo' => 0,
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

    private function seedPlan(): void
    {
        $m = DB::connection('master');
        $planId = $m->table('plans')->where('code', 'oocheck-plan')->value('id')
            ?: $m->table('plans')->insertGetId(['code' => 'oocheck-plan', 'name' => 'Checkout', 'price' => 0,
                'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $m->table('plan_modules')->where('plan_id', $planId)->delete();
        foreach ($m->table('modules')->pluck('id') as $moduleId) {
            $m->table('plan_modules')->insert(['plan_id' => $planId, 'module_id' => $moduleId, 'is_enabled' => 1]);
        }
        $m->table('subscriptions')->where('tenant_id', $this->tenantId)->delete();
        $m->table('subscriptions')->insert(['tenant_id' => $this->tenantId, 'plan_id' => $planId, 'status' => 'active',
            'current_period_ends_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now()]);
    }
}
