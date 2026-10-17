<?php

namespace Tests\MySql;

use App\Models\Master\SubscriptionInvoice;
use App\Models\Master\SubscriptionInvoiceLine;
use App\Models\Master\WebsiteOrderCharge;
use App\Models\Master\WhatsAppMessage;
use App\Models\Tenant\User;
use App\Services\Saas\BillingRates;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\MySql\Support\TenantFixtures;

/**
 * ONLINE-ORDERING-1 (Phase 4b) — Bingoo ka 1%: sirf website orders ki net sale (delivery/tax samet,
 * returns minus), usi mahana usage invoice me jis me WhatsApp hai — har service ki har din ki aik line,
 * total = SUM(lines).
 */
class OnlineOrderingCommissionMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private string $host;

    private int $tenantId;

    private int $branchId;

    private int $ownerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->host = 'oocom.'.config('tenancy.tenant_base_domain');
        config()->set('services.online_ordering.commission_percent', 1);
        $this->seedMaster();

        DB::setDefaultConnection('tenant');
        $this->cleanTenant(['sales_return_lines', 'sales_returns', 'sale_payments', 'sales_order_lines', 'sales_orders', 'model_has_roles', 'users', 'branches']);
        $this->branchId = $this->makeBranch();
        $this->seedOwner();
        DB::setDefaultConnection('master');
    }

    protected function tearDown(): void
    {
        try {
            $m = DB::connection('master');
            $m->table('whatsapp_messages')->where('tenant_id', $this->tenantId)->delete();
            $m->table('website_order_charges')->where('tenant_id', $this->tenantId)->delete();
            $m->table('subscription_invoices')->where('tenant_id', $this->tenantId)->delete();
            $m->table('tenant_domains')->where('domain', $this->host)->delete();
            $m->table('tenant_databases')->where('tenant_id', $this->tenantId)->delete();
            $m->table('subscriptions')->where('tenant_id', $this->tenantId)->delete();
            $m->table('tenants')->where('id', $this->tenantId)->delete();
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    public function test_one_percent_of_the_website_net_sale_sits_beside_whatsapp_day_by_day(): void
    {
        $this->web('2026-09-10', 1000);
        $this->web('2026-09-10', 2500);
        $partly = $this->web('2026-09-11', 4000, 'partially_returned');
        $this->refund($partly, '2026-09-12', 500);
        // Ye teeno bill me nahi: abhi confirm nahi hua, cancel hua, aur counter ka order.
        $this->web('2026-09-10', 9999, 'held');
        $this->web('2026-09-10', 7777, 'cancelled');
        $this->makeSale($this->branchId, ['business_date' => '2026-09-10', 'grand_total' => 50000]);
        $this->message('2026-09-10');
        $this->message('2026-09-10');

        $this->artisan('billing:whatsapp-invoice', ['--legacy' => true, '--yes' => true, '--month' => '2026-09'])->assertSuccessful();
        $this->artisan('billing:website-commission', ['--legacy' => true, '--yes' => true, '--month' => '2026-09', '--tenant' => 'oocom'])->assertSuccessful();

        $invoice = SubscriptionInvoice::where('tenant_id', $this->tenantId)->where('invoice_type', 'addon')->sole();
        $this->assertSame([
            ['whatsapp_messages', '2026-09-10', '2.00', null, '19.7000'],
            ['website_commission', '2026-09-10', '2.00', '3500.00', '35.0000'],
            ['website_commission', '2026-09-11', '1.00', '4000.00', '40.0000'],
            ['website_commission', '2026-09-12', '0.00', '-500.00', '-5.0000'],
        ], $this->lines($invoice));
        $this->assertSame('89.70', $invoice->fresh()->total_amount, '19.70 WhatsApp + 70.00 (7,000 net × 1%).');
        $this->assertSame('89.70', $invoice->fresh()->balance_amount);

        // Pehle WhatsApp wala command total seedha messages se likhta tha — commission mit jata. Ab nahi.
        $this->message('2026-09-11');
        $this->artisan('billing:whatsapp-invoice', ['--legacy' => true, '--yes' => true, '--month' => '2026-09'])->assertSuccessful();
        $this->assertSame('99.55', $invoice->fresh()->total_amount, 'WhatsApp ki nayi row aayi — commission (70.00) phir bhi invoice par.');
        $this->artisan('billing:website-commission', ['--legacy' => true, '--yes' => true, '--month' => '2026-09', '--tenant' => 'oocom'])->assertSuccessful();
        $this->assertSame('99.55', $invoice->fresh()->total_amount, 'Dobara chalana dugna nahi karta, aik doosre ko mitata nahi.');
        $this->assertSame(4, WebsiteOrderCharge::where('tenant_id', $this->tenantId)->count());
    }

    public function test_an_open_month_follows_the_orders_and_a_closed_month_never_moves(): void
    {
        $sale = $this->web('2026-09-10', 1000);
        $this->artisan('billing:website-commission', ['--legacy' => true, '--yes' => true, '--month' => '2026-09', '--tenant' => 'oocom'])->assertSuccessful();
        $sep = SubscriptionInvoice::where('tenant_id', $this->tenantId)->sole();
        $this->assertSame('10.00', $sep->total_amount);

        // Khula mahina: order cancel → raat ko raqam khud hat jati hai.
        DB::connection('tenant')->table('sales_orders')->where('id', $sale)->update(['status' => 'cancelled']);
        $this->artisan('billing:website-commission', ['--legacy' => true, '--yes' => true, '--month' => '2026-09', '--tenant' => 'oocom'])->assertSuccessful();
        $this->assertSame('0.00', $sep->fresh()->total_amount);
        $this->assertSame(0, SubscriptionInvoiceLine::where('invoice_id', $sep->id)->count());

        DB::connection('tenant')->table('sales_orders')->where('id', $sale)->update(['status' => 'paid']);
        $this->artisan('billing:website-commission', ['--legacy' => true, '--yes' => true, '--month' => '2026-09', '--tenant' => 'oocom'])->assertSuccessful();
        $this->artisan('billing:whatsapp-invoice', ['--legacy' => true, '--month' => '2026-09', '--close' => true, '--yes' => true])->assertSuccessful();
        $this->assertSame('issued', $sep->fresh()->status);

        // Band mahina: der se aaya September ka order, aur band hone ke baad cancel — September nahi hilta.
        $this->web('2026-09-20', 5000);
        DB::connection('tenant')->table('sales_orders')->where('id', $sale)->update(['status' => 'cancelled']);
        $this->artisan('billing:website-commission', ['--legacy' => true, '--yes' => true, '--month' => '2026-09', '--tenant' => 'oocom'])
            ->expectsOutputToContain('1 row(s) band mahine ke din par')->assertSuccessful();
        $this->assertSame('10.00', $sep->fresh()->total_amount);
        $this->assertSame(1, WebsiteOrderCharge::where('tenant_id', $this->tenantId)->count());
        DB::connection('tenant')->table('sales_orders')->where('id', $sale)->update(['status' => 'paid']);

        // Band mahine ke order ka return October me — September nahi hilta, October me minus.
        $this->refund($sale, '2026-10-02', 400);
        $this->message('2026-10-02');
        $this->artisan('billing:website-commission', ['--legacy' => true, '--yes' => true, '--month' => '2026-09', '--tenant' => 'oocom'])->assertSuccessful();
        $this->artisan('billing:whatsapp-invoice', ['--legacy' => true, '--yes' => true, '--month' => '2026-10'])->assertSuccessful();
        $this->artisan('billing:website-commission', ['--legacy' => true, '--yes' => true, '--month' => '2026-10', '--tenant' => 'oocom'])->assertSuccessful();

        $this->assertSame('10.00', $sep->fresh()->total_amount);
        $oct = SubscriptionInvoice::where('tenant_id', $this->tenantId)->where('period_start', '2026-10-01')->sole();
        $this->assertSame('5.85', $oct->total_amount, '9.85 WhatsApp − 4.00 (return 400 × 1%).');
    }

    public function test_old_whatsapp_invoices_get_their_lines_only_when_they_add_up(): void
    {
        $this->message('2026-10-05');
        $this->message('2026-10-06');
        $this->artisan('billing:whatsapp-invoice', ['--legacy' => true, '--yes' => true, '--month' => '2026-10'])->assertSuccessful();
        $invoice = SubscriptionInvoice::where('tenant_id', $this->tenantId)->sole();
        SubscriptionInvoiceLine::where('invoice_id', $invoice->id)->delete();   // jaise lines se pehle ki invoice

        // Total messages se na mile to kuch nahi likha jata.
        $invoice->update(['subtotal' => 25, 'total_amount' => 25]);
        $this->artisan('billing:usage-lines-backfill', ['--yes' => true, '--tenant' => 'oocom'])->assertFailed();
        $this->assertSame(0, SubscriptionInvoiceLine::where('invoice_id', $invoice->id)->count());

        $invoice->update(['subtotal' => 19.70, 'total_amount' => 19.70, 'balance_amount' => 19.70]);
        $this->artisan('billing:usage-lines-backfill', ['--yes' => true, '--tenant' => 'oocom'])->assertSuccessful();
        $this->assertSame(2, SubscriptionInvoiceLine::where('invoice_id', $invoice->id)->count());
        $this->assertSame('19.70', $invoice->fresh()->total_amount, 'Backfill total ko nahi chhuta.');
    }

    public function test_the_dashboard_card_and_the_invoice_show_both_parts(): void
    {
        $this->web(now()->toDateString(), 12000);
        $this->message(now()->toDateString());
        $month = now()->format('Y-m');

        // BILLING-TENANT-CYCLE-1 — dashboard ka chalta hua khana ab KHULI rows se banta hai (jo abhi
        // kisi invoice se bandhi nahi), kisi draft invoice ke total se nahi. Is liye wo invoice
        // banne se PEHLE dekha jata hai — yani us haalat me jis me tenant usay roz dekhta hai.
        //
        // Magar website ki charge rows khud order se nahi bantin; wo ye command likhti hai (prod me
        // har raat chalti hai). Is liye wo pehle chalti hai — rows likh deti hai, bandhti kisi se
        // nahi, kyunke koi draft addon invoice ab banti hi nahi.
        // (Yahan --legacy JAAN BOOJH KAR nahi: legacy apni invoice khol kar charge bandh deta hai,
        //  aur phir dashboard ka khula khana khaali reh jata.)
        $this->artisan('billing:website-commission', ['--yes' => true, '--month' => $month, '--tenant' => 'oocom'])->assertSuccessful();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $owner = User::on('tenant')->find($this->ownerId);
        $this->actingAs($owner, 'tenant')->get('http://'.$this->host.'/dashboard')->assertOk()
            ->assertSee('Usage so far')->assertSee('129.85')
            ->assertSee('WhatsApp 9.85')->assertSee('Website orders 120.00');

        $this->artisan('billing:whatsapp-invoice', ['--legacy' => true, '--yes' => true, '--month' => $month])->assertSuccessful();
        $this->artisan('billing:website-commission', ['--legacy' => true, '--yes' => true, '--month' => $month, '--tenant' => 'oocom'])->assertSuccessful();
        $invoice = SubscriptionInvoice::where('tenant_id', $this->tenantId)->sole();

        $this->actingAs($owner, 'tenant')->get('http://'.$this->host.'/billing/invoices/'.$invoice->id)->assertOk()
            ->assertSee('Charges')->assertSee('WhatsApp messages')->assertSee('Website orders')
            ->assertSee('1 order · net sale 12,000.00 · 1%');
    }

    // ── helpers ──

    private function web(string $day, float $total, string $status = 'paid'): int
    {
        return $this->makeSale($this->branchId, ['order_source' => 'website', 'order_type' => 'delivery', 'status' => $status,
            'business_date' => $day, 'grand_total' => $total, 'web_order_key' => (string) Str::uuid()]);
    }

    private function refund(int $saleId, string $day, float $amount): void
    {
        DB::connection('tenant')->table('sales_returns')->insert([
            'return_no' => 'RT-'.Str::upper(Str::random(8)), 'sales_order_id' => $saleId, 'branch_id' => $this->branchId,
            'return_date' => $day.' 13:00:00', 'business_date' => $day, 'subtotal' => $amount, 'grand_total' => $amount,
            'refund_amount' => $amount, 'refund_method' => 'cash', 'status' => 'posted', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function message(string $day): void
    {
        WhatsAppMessage::create(['tenant_id' => $this->tenantId, 'source' => 'website', 'template' => 'web_order_received',
            'to' => '923001234567', 'wamid' => 'wamid.C'.Str::random(10), 'status' => 'accepted',
            'rate_charged' => 9.85, 'provider_cost' => 5.95, 'usage_date' => $day, 'sent_at' => now()]);
    }

    private function lines(SubscriptionInvoice $invoice): array
    {
        return SubscriptionInvoiceLine::where('invoice_id', $invoice->id)->orderBy('line_type')->orderBy('usage_date')->get()
            ->sortBy(fn ($l) => ($l->line_type === 'whatsapp_messages' ? '0' : '1').$l->usage_date->toDateString())->values()
            ->map(fn ($l) => [$l->line_type, $l->usage_date->toDateString(), $l->quantity, $l->base_amount, $l->amount])->all();
    }

    private function seedMaster(): void
    {
        DB::setDefaultConnection('master');
        $m = DB::connection('master');
        $m->table('platform_settings')->where('key', BillingRates::WEBSITE_COMMISSION)->delete();
        $m->table('tenant_domains')->where('domain', $this->host)->delete();
        if ($old = $m->table('tenants')->where('tenant_code', 'oocom')->value('id')) {
            $m->table('whatsapp_messages')->where('tenant_id', $old)->delete();
            $m->table('subscription_invoices')->where('tenant_id', $old)->delete();
            $m->table('subscriptions')->where('tenant_id', $old)->delete();
            $m->table('tenant_databases')->where('tenant_id', $old)->delete();
            $m->table('tenants')->where('id', $old)->delete();
        }
        $this->tenantId = $m->table('tenants')->insertGetId([
            'tenant_code' => 'oocom', 'business_name' => 'Commission Foods', 'owner_name' => 'Owner',
            'owner_email' => 'owner@oocom.test', 'currency_code' => 'PKR', 'status' => 'active', 'is_demo' => 0,
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
        $planId = $m->table('plans')->where('code', 'oocom-plan')->value('id')
            ?: $m->table('plans')->insertGetId(['code' => 'oocom-plan', 'name' => 'Commission', 'price' => 0, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $m->table('plan_modules')->where('plan_id', $planId)->delete();
        foreach ($m->table('modules')->pluck('id') as $moduleId) {
            $m->table('plan_modules')->insert(['plan_id' => $planId, 'module_id' => $moduleId, 'is_enabled' => 1]);
        }
        $m->table('subscriptions')->insert(['tenant_id' => $this->tenantId, 'plan_id' => $planId, 'status' => 'active',
            'current_period_ends_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function seedOwner(): void
    {
        // permissions/roles tenant MIGRATION se aate hain — kabhi truncate nahi.
        $c = DB::connection('tenant');
        $role = $c->table('roles')->where('name', 'Owner')->where('guard_name', 'tenant')->value('id')
            ?: $c->table('roles')->insertGetId(['name' => 'Owner', 'guard_name' => 'tenant', 'created_at' => now(), 'updated_at' => now()]);
        foreach (['tenant.dashboard', 'tenant.billing.index', 'tenant.billing.invoices.show'] as $name) {
            $c->table('permissions')->updateOrInsert(['name' => $name, 'guard_name' => 'tenant'], ['created_at' => now(), 'updated_at' => now()]);
        }
        foreach ($c->table('permissions')->where('guard_name', 'tenant')->pluck('id') as $pid) {
            $c->table('role_has_permissions')->updateOrInsert(['permission_id' => $pid, 'role_id' => $role], []);
        }
        $this->ownerId = $c->table('users')->insertGetId([
            'name' => 'ComOwner', 'email' => 'owner@oocom.test', 'password' => bcrypt('x'), 'employee_code' => 'COMOWN',
            'status' => 'active', 'locale' => 'en', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $c->table('model_has_roles')->insert(['role_id' => $role, 'model_type' => User::class, 'model_id' => $this->ownerId]);
    }
}
