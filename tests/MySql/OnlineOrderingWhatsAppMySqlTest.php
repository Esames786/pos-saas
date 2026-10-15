<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\HeldSaleController;
use App\Models\Master\WhatsAppMessage;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Category;
use App\Models\Tenant\Combo;
use App\Models\Tenant\Product;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\User;
use App\Models\Tenant\WebOrderRoute;
use App\Models\Tenant\WebSetting;
use App\Services\OnlineOrdering\WebsiteOrderNotifier;
use App\Services\Permissions\PermissionSyncService;
use App\Services\Printing\PrintJobService;
use App\Services\Saas\BillingRates;
use App\Services\Sales\ShiftService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use Tests\MySql\Support\TenantFixtures;

/**
 * ONLINE-ORDERING-1 (Phase 4) — customer ka WhatsApp aur "Confirm Order" link. WhatsApp ikhtiyari (client
 * ke toggles), billing usi register me, aur kabhi order ko nahi rokta. Confirm link: aik dafa, 30 minute,
 * KOT POS ke apne print raaste se.
 */
class OnlineOrderingWhatsAppMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private string $host;

    private int $tenantId;

    private int $branchId;

    private int $terminalId;

    private int $userId;

    private Combo $deal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);
        config()->set('services.whatsapp.token', 'test-token');
        config()->set('services.whatsapp.phone_number_id', '111');
        config()->set('services.whatsapp.rate_pkr', 9.85);
        $this->host = 'oowa.'.config('tenancy.tenant_base_domain');
        $this->seedMaster();
        app(PermissionSyncService::class)->syncRouteCatalog();

        DB::connection('master')->table('whatsapp_messages')->where('tenant_id', $this->tenantId)->delete();
        DB::connection('master')->table('platform_settings')->whereIn('key', [BillingRates::WHATSAPP_RATE, BillingRates::WHATSAPP_COST])->delete();
        $this->app->forgetScopedInstances();

        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'customer_addresses', 'customers', 'sales_order_lines', 'sales_orders', 'shifts', 'web_order_routes', 'web_settings',
            'delivery_zones', 'delivery_channels', 'combo_modifier_group', 'product_modifier_group', 'modifiers', 'modifier_groups',
            'combo_components', 'combos', 'product_branch_prices', 'product_variants', 'products', 'categories', 'terminals', 'users', 'branches',
        ]);
        $this->userId = $this->makeUser(['employee_code' => 'WA'.Str::random(4)]);
        $this->branchId = $this->makeBranch();
        $this->terminalId = $this->makeTerminal($this->branchId);
        $cat = Category::create(['name' => 'Deals', 'slug' => 'd-'.uniqid(), 'sort_order' => 1, 'is_active' => true, 'show_on_website' => true]);
        $rice = Product::create(['sku' => 'R-'.uniqid(), 'name' => 'Garlic Rice', 'slug' => 'r-'.uniqid(), 'category_id' => $cat->id,
            'product_type' => 'simple', 'product_kind' => 'sale_item', 'default_selling_price' => 900, 'is_sellable' => true,
            'is_pos_visible' => true, 'status' => 'active', 'is_stock_tracked' => false, 'inventory_consumption_method' => 'none']);
        $this->deal = Combo::create(['name' => 'Midnight Deal', 'price' => 1550, 'status' => 'active', 'category_id' => $cat->id, 'show_on_website' => true]);
        $this->deal->components()->create(['product_id' => $rice->id, 'quantity' => 1, 'sort_order' => 1]);
        WebSetting::tenantDefault()->update(['is_enabled' => true, 'default_branch_id' => $this->branchId, 'contact_phone' => '0321 0000000',
            'hours' => array_map(fn () => ['open' => '00:00', 'close' => '23:59', 'closed' => false], WebSetting::DAYS)]);
        WebOrderRoute::create(['branch_id' => $this->branchId, 'order_type' => 'delivery', 'terminal_id' => $this->terminalId]);
        app(ShiftService::class)->open(Branch::find($this->branchId), Terminal::find($this->terminalId), $this->userId, 0.0);
    }

    protected function tearDown(): void
    {
        try {
            $m = DB::connection('master');
            $m->table('whatsapp_messages')->where('tenant_id', $this->tenantId)->delete();
            $m->table('tenant_domains')->where('domain', $this->host)->delete();
            $m->table('tenant_databases')->where('tenant_id', $this->tenantId)->delete();
            $m->table('subscriptions')->where('tenant_id', $this->tenantId)->delete();
            $m->table('tenants')->where('id', $this->tenantId)->delete();
        } catch (\Throwable) {
        }
        Mockery::close();
        parent::tearDown();
    }

    public function test_the_order_received_message_carries_the_confirm_link_and_is_billed_once(): void
    {
        $this->fakeMeta();
        $sale = $this->order();

        // Malik (15 Oct): order aate hi koi WhatsApp nahi — pehle admin POS par dekh kar "Confirm" kare.
        Http::assertNothingSent();
        $this->verify($sale)->assertOk()->assertJson(['already' => false, 'whatsapp' => 'sending']);
        $this->assertTrue($sale->fresh()->is_draft, 'Admin ka Confirm order ko Held nahi karta — customer ka tap karta hai.');

        Http::assertSentCount(1);
        Http::assertSent(function (HttpRequest $r) use ($sale) {
            $b = $r->data();
            $body = collect($b['template']['components'])->firstWhere('type', 'body')['parameters'];
            $button = collect($b['template']['components'])->firstWhere('type', 'button')['parameters'][0]['text'];

            return $b['to'] === '923001234567'
                && $b['template']['name'] === WebsiteOrderNotifier::TEMPLATE_RECEIVED
                && array_column($body, 'text') === ['Test Customer', 'WhatsApp Foods', $sale->sale_no, '1,550']
                && $button === 'oowa.'.$sale->web_token;
        });

        $row = WhatsAppMessage::where('tenant_id', $this->tenantId)->sole();
        $this->assertSame('website', $row->source);
        $this->assertSame('9.8500', $row->rate_charged, 'Usi rate par jo WhatsApp ka hai (yahan .env sahara).');
        $this->assertSame('accepted', $row->status);

        // Dobara Confirm / dobara bhejne ki koshish: aik hi.
        $this->verify($sale)->assertOk()->assertJson(['already' => true]);
        app(WebsiteOrderNotifier::class)->send('received', $sale->id);
        Http::assertSentCount(1);
    }

    public function test_a_switched_off_message_is_not_sent_and_a_failure_never_blocks_the_order(): void
    {
        WebSetting::tenantDefault()->update(['wa_notify_received' => false]);
        $this->fakeMeta();
        $this->verify($this->order())->assertOk()->assertJson(['whatsapp' => 'off']);
        Http::assertNothingSent();

        WebSetting::tenantDefault()->update(['wa_notify_received' => true]);
        $this->metaRejects = true;
        $sale = $this->order();
        $this->verify($sale)->assertOk();
        $sale->refresh();
        $this->assertSame('held', $sale->status, 'WhatsApp fail ho, order phir bhi bana.');
        $this->assertTrue($sale->is_draft);
        $this->assertSame('failed', WhatsAppMessage::where('tenant_id', $this->tenantId)->latest('id')->first()->status);
        $this->assertTrue((bool) data_get($sale->web_meta, 'wa.received.failed'), 'POS par "WhatsApp failed" dikhe.');
    }

    public function test_without_whatsapp_set_up_nothing_is_attempted_or_billed(): void
    {
        config()->set('services.whatsapp.token', '');
        $this->fakeMeta();
        $sale = $this->order();
        $this->verify($sale)->assertOk()->assertJson(['whatsapp' => 'off']);

        $this->assertSame('held', $sale->status);
        Http::assertNothingSent();
        $this->assertSame(0, WhatsAppMessage::where('tenant_id', $this->tenantId)->count(), 'Koshish hi nahi hui — na row, na bill.');
    }

    public function test_the_confirm_link_holds_the_order_once_and_the_kot_goes_by_the_pos_print_path(): void
    {
        $this->fakeMeta();
        $sale = $this->order();

        $print = Mockery::mock(PrintJobService::class);
        $print->shouldReceive('queueKot')->once()->withArgs(fn ($sale2) => (int) $sale2->id === $sale->id)->andReturn([]);
        $this->app->instance(PrintJobService::class, $print);

        // Admin ke Confirm se pehle link kuch nahi karta (malik: pehle admin ka control).
        $this->post($this->url('/order/confirm/'.$sale->web_token))->assertRedirect()->assertSessionHas('confirm_outcome', 'pending');
        $this->assertTrue($sale->fresh()->is_draft);
        $this->verify($sale)->assertOk();

        // GET kuch nahi badalta (bots, previews).
        $this->get($this->url('/order/confirm/'.$sale->web_token))->assertOk()->assertSee('confirm-form', false);
        $this->assertTrue($sale->fresh()->is_draft);

        $this->post($this->url('/order/confirm/'.$sale->web_token))->assertRedirect('/order/status/'.$sale->web_token);
        $sale->refresh();
        $this->assertFalse($sale->is_draft);
        $this->assertSame('held', $sale->status);

        // "Confirmed" WhatsApp gaya; dobara dabana — dobara KOT nahi (mock: once).
        Http::assertSent(fn (HttpRequest $r) => $r->data()['template']['name'] === WebsiteOrderNotifier::TEMPLATE_CONFIRMED);
        $this->post($this->url('/order/confirm/'.$sale->web_token))->assertRedirect();
        $this->get($this->url('/order/status/'.$sale->web_token))->assertSee('Order confirmed');
    }

    public function test_an_old_link_does_not_confirm(): void
    {
        $this->fakeMeta();
        $sale = $this->order();
        $this->verify($sale)->assertOk();
        $meta = $sale->fresh()->web_meta;
        $meta['verified']['at'] = now()->subMinutes(31)->toIso8601String();
        SalesOrder::whereKey($sale->id)->update(['web_meta' => json_encode($meta)]);

        $this->post($this->url('/order/confirm/'.$sale->web_token))->assertRedirect()->assertSessionHas('confirm_outcome', 'expired');
        $this->assertTrue($sale->fresh()->is_draft, '30 minute baad link nahi chalta — cashier call karega.');
    }

    public function test_the_thirty_minutes_start_when_the_admin_confirms_not_when_the_customer_ordered(): void
    {
        $this->fakeMeta();
        $print = Mockery::mock(PrintJobService::class);
        $print->shouldReceive('queueKot')->once()->andReturn([]);
        $this->app->instance(PrintJobService::class, $print);

        $sale = $this->order();
        SalesOrder::whereKey($sale->id)->update(['created_at' => now()->subMinutes(45)]);   // admin ne der se dekha
        $this->verify($sale)->assertOk();

        $this->post($this->url('/order/confirm/'.$sale->web_token))->assertRedirect()->assertSessionHas('confirm_outcome', 'confirmed');
        $this->assertFalse($sale->fresh()->is_draft);
    }

    public function test_the_cashiers_own_hold_and_a_cancel_also_tell_the_customer(): void
    {
        $this->fakeMeta();
        $sale = $this->order('pickup');

        // Cashier ne POS par recall kar ke normal Hold kiya — POS ka apna controller.
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');
        $lines = $sale->lines->sortBy('id')->values();
        $req = Request::create('/held-sales', 'POST', [
            'held_sale_id' => $sale->id, 'branch_id' => $this->branchId, 'terminal_id' => $this->terminalId,
            'order_type' => 'takeaway', 'discount_type' => 'none',
            'customer_id' => $sale->customer_id, 'customer_name' => $sale->customer_name, 'customer_phone' => $sale->customer_phone,
            'lines' => [
                ['sales_order_line_id' => $lines[0]->id, 'product_id' => $lines[0]->product_id, 'quantity' => 1, 'unit_price' => 1550,
                    'line_kind' => 'combo_header', 'combo_id' => $this->deal->id, 'line_name' => 'Midnight Deal', 'client_line_key' => 'c'],
                ['sales_order_line_id' => $lines[1]->id, 'product_id' => $lines[1]->product_id, 'quantity' => 1, 'unit_price' => 0,
                    'line_kind' => 'component', 'combo_id' => $this->deal->id, 'parent_client_line_key' => 'c'],
            ],
        ]);
        $req->headers->set('Accept', 'application/json');
        $res = app()->call([app(HeldSaleController::class), 'store'], ['request' => $req]);
        $this->assertContains($res->getStatusCode(), [200, 201], $res->getContent());
        $this->assertFalse($sale->fresh()->is_draft);
        Http::assertSent(fn (HttpRequest $r) => $r->data()['template']['name'] === WebsiteOrderNotifier::TEMPLATE_CONFIRMED);

        $other = $this->order();
        $other->update(['status' => 'cancelled']);
        Http::assertSent(fn (HttpRequest $r) => $r->data()['template']['name'] === WebsiteOrderNotifier::TEMPLATE_CANCELLED
            && collect($r->data()['template']['components'][0]['parameters'])->pluck('text')->contains($other->sale_no));

        // Counter ka apna order: koi WhatsApp nahi.
        $before = WhatsAppMessage::where('tenant_id', $this->tenantId)->count();
        SalesOrder::whereKey($sale->id)->first()->update(['order_source' => 'pos']);
        $pos = SalesOrder::find($sale->id);
        $pos->update(['status' => 'cancelled']);
        $this->assertSame($before, WhatsAppMessage::where('tenant_id', $this->tenantId)->count());
    }

    public function test_the_bingoo_link_sends_the_customer_to_their_restaurant(): void
    {
        $this->fakeMeta();
        $sale = $this->order();
        $central = 'http://'.config('tenancy.central_domain');
        $want = 'http://oowa.'.config('tenancy.tenant_base_domain').'/order/confirm/'.$sale->web_token;
        app()->forgetInstance('tenant');

        $this->get($central.'/o/oowa.'.$sale->web_token)->assertRedirect($want);
        $this->get($central.'/o/{{1}}oowa.'.$sale->web_token)->assertRedirect($want);   // Meta ka "{{1}}" saath aata hai
        $this->get($central.'/o/nobody.'.$sale->web_token)->assertNotFound();
    }

    // ── helpers ──

    /** POS par admin ka "Confirm" — asal raaste (HTTP, Hold wala haq). */
    private function verify(SalesOrder $sale)
    {
        foreach (['tenant.pos.index', 'tenant.held-sales.store'] as $p) {
            \Spatie\Permission\Models\Permission::findOrCreate($p, 'tenant');
        }
        $user = User::on('tenant')->find($this->userId);
        $user->givePermissionTo(['tenant.pos.index', 'tenant.held-sales.store']);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->actingAs($user, 'tenant')->postJson($this->url('/api/pos/website-orders/'.$sale->id.'/verify'));
    }

    private function order(string $type = 'delivery'): SalesOrder
    {
        $zone = \App\Models\Tenant\DeliveryZone::firstOrCreate(['geo_area_id' => 992001], ['area_name' => 'Gulshan', 'city' => 'Karachi',
            'branch_id' => $this->branchId, 'fee' => 0, 'is_active' => true]);

        return app(\App\Services\OnlineOrdering\WebsiteOrderService::class)->place([
            'key' => (string) Str::uuid(), 'type' => $type, 'area' => $type === 'delivery' ? $zone->id : null,
            'customer' => ['name' => 'Test Customer', 'phone' => '0300 1234567', 'address' => 'House 1'],
            'lines' => [['option' => 'combo:'.$this->deal->id, 'qty' => 1]],
        ]);
    }

    private bool $metaRejects = false;

    private function fakeMeta(): void
    {
        $n = 0;
        Http::fake(function () use (&$n) {
            $n++;

            return $this->metaRejects
                ? Http::response(['error' => ['message' => 'Template not approved']], 400)
                : Http::response(['messages' => [['id' => 'wamid.WEB'.$n]]], 200);
        });
    }

    private function url(string $p): string
    {
        return 'http://'.$this->host.$p;
    }

    private function seedMaster(): void
    {
        DB::setDefaultConnection('master');
        $m = DB::connection('master');
        $m->table('tenant_domains')->where('domain', $this->host)->delete();
        $old = $m->table('tenants')->where('tenant_code', 'oowa')->value('id');
        if ($old) {
            $m->table('whatsapp_messages')->where('tenant_id', $old)->delete();
            $m->table('subscriptions')->where('tenant_id', $old)->delete();
            $m->table('tenant_databases')->where('tenant_id', $old)->delete();
            $m->table('tenants')->where('id', $old)->delete();
        }
        $this->tenantId = $m->table('tenants')->insertGetId([
            'tenant_code' => 'oowa', 'business_name' => 'WhatsApp Foods', 'owner_name' => 'Owner',
            'owner_email' => 'owner@oowa.test', 'currency_code' => 'PKR', 'status' => 'active', 'is_demo' => 0,
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
        $planId = $m->table('plans')->where('code', 'oowa-plan')->value('id')
            ?: $m->table('plans')->insertGetId(['code' => 'oowa-plan', 'name' => 'WA', 'price' => 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $m->table('plan_modules')->where('plan_id', $planId)->delete();
        foreach ($m->table('modules')->pluck('id') as $moduleId) {
            $m->table('plan_modules')->insert(['plan_id' => $planId, 'module_id' => $moduleId, 'is_enabled' => 1]);
        }
        $m->table('subscriptions')->insert(['tenant_id' => $this->tenantId, 'plan_id' => $planId, 'status' => 'active',
            'current_period_ends_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now()]);
        // Tenant ko bind karo — service seedha chalane par notifier ko tenant chahiye.
        app()->instance('tenant', \App\Models\Master\Tenant::find($this->tenantId));
    }
}
