<?php

namespace Tests\MySql;

use App\Models\Master\Subscription;
use App\Models\Master\SubscriptionInvoice;
use App\Models\Master\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * SAAS-BILLING-AUTO-1 — mahana invoice khud banta hai.
 *
 * Ye saaray guards paison ke hain, aur in me se jo toote ga wo kisi screen par nazar nahi aayega:
 * ya tenant ko dugna bill jayega, ya kisi ka invoice bane bina mahina guzar jayega aur kisi ko pata
 * na chalega ke paisa maanga hi nahi gaya.
 */
class BillingMonthlyInvoiceMySqlTest extends MySqlTenantTestCase
{
    private Tenant $tenant;

    private Subscription $sub;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::first();
        DB::connection('master')->table('subscription_payments')->delete();
        DB::connection('master')->table('subscription_invoices')->where('tenant_id', $this->tenant->id)->delete();
        DB::connection('master')->table('whatsapp_messages')->where('tenant_id', $this->tenant->id)->delete();

        $sub = Subscription::where('tenant_id', $this->tenant->id)->first();
        if (! $sub) {
            $sub = Subscription::create([
                'tenant_id' => $this->tenant->id,
                'plan_id' => DB::connection('master')->table('plans')->value('id'),
                'status' => 'active',
                'currency_code' => 'PKR',
                'billing_period' => 'monthly',
                'current_period_ends_at' => '2027-01-01 00:00:00',
            ]);
        }
        $sub->update(['price_snapshot' => 25000, 'invoice_day' => 15, 'status' => 'active']);
        $this->sub = $sub->fresh();
    }

    private function generate(string $date, bool $write = true): void
    {
        $this->artisan('billing:generate-monthly-invoices', array_filter([
            '--date' => $date,
            '--yes' => $write ?: null,
        ]))->assertSuccessful();
    }

    private function invoices(): \Illuminate\Support\Collection
    {
        return collect(DB::connection('master')->table('subscription_invoices')
            ->where('tenant_id', $this->tenant->id)
            ->where('invoice_type', 'subscription')->get());
    }

    public function test_an_invoice_is_made_only_on_this_tenants_own_day(): void
    {
        // Har tenant ka apna din hai (khatri 15, kashifkitchen 20, kashiffood 25, tawakal 10).
        // Roz chalne wali command ko sirf AAJ wale uthane chahiyen, warna sab ka invoice aik hi din
        // ban jayega aur un ki apni tareekhon ka koi matlab nahi rahega.
        $this->generate('2026-11-14');
        $this->assertCount(0, $this->invoices(), 'Ghalat din par invoice ban gaya.');

        $this->generate('2026-11-15');
        $this->assertCount(1, $this->invoices());

        $i = $this->invoices()->first();
        $this->assertSame('issued', $i->status);

        // BILLING-TENANT-CYCLE-1 — muddat ab CYCLE ki hai, calendar mahine ki nahi. Upar wala kinara
        // wo din jab invoice bani; neeche wala pichhle invoice ke baad ka din. Malik ne saaf kaha ke
        // 1-se-31 ka concept hai hi nahi: khatri 15 se 15, kisi ka 1 se 1, kisi ka 20 se 20.
        $this->assertSame('2026-11-15', (string) $i->period_end);
        $this->assertSame('2026-10-16', (string) $i->period_start);

        // Total ab LINES se banta hai, is liye plan ki apni line hona zaroori hai — wo na ho to
        // UsageInvoiceService::refresh() total ko sirf usage ke barabar kar deta aur plan ki fees
        // chup-chaap ghayab ho jati.
        $plan = DB::connection('master')->table('subscription_invoice_lines')
            ->where('invoice_id', $i->id)->where('line_type', 'plan')->first();
        $this->assertNotNull($plan, 'Plan ki line nahi bani — total se fees ghayab ho jayegi.');
        $this->assertSame('25000.0000', (string) $plan->amount);
        $this->assertSame('25000.00', (string) $i->total_amount);
    }

    public function test_the_usage_rides_the_same_invoice_and_is_locked(): void
    {
        // Malik: "WhatsApp ki alag invoice ki zaroorat nahi — usi AIK invoice me breakup dikha do,
        // aur us ke baad running bill sifar se shuru."
        //
        // Yahan dono baatein aik saath parkhi jati hain: usage usi invoice par aati hai jis par plan
        // ki fees hai, aur bandh (lock) ho jati hai — yani agle invoice me dobara nahi aayegi.
        DB::connection('master')->table('whatsapp_messages')->insert([
            'tenant_id' => $this->tenant->id, 'source' => 'schedule', 'template' => 't',
            'to' => '923000000000', 'status' => 'delivered', 'rate_charged' => 9.85,
            'provider_cost' => 5.95, 'usage_date' => '2026-11-10', 'sent_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->generate('2026-11-15');

        $i = $this->invoices()->first();
        $this->assertSame('25009.85', (string) $i->total_amount, 'Plan + usage aik hi invoice par nahi aaye.');

        $row = DB::connection('master')->table('whatsapp_messages')
            ->where('tenant_id', $this->tenant->id)->orderByDesc('id')->first();
        $this->assertSame((int) $i->id, (int) $row->invoice_id, 'Usage lock nahi hui.');

        // Aur agle cycle me wohi usage DOBARA nahi aani chahiye — yehi lock ka poora maqsad hai.
        $this->generate('2026-12-15');
        $next = $this->invoices()->sortByDesc('id')->first();
        $this->assertSame('25000.00', (string) $next->total_amount, 'Pehle se bandhi usage dobara bill ho gayi.');
    }

    public function test_running_twice_in_one_month_does_not_bill_twice(): void
    {
        // Command roz chalti hai. Agar kisi din do baar chal jaye — ya mai haath se chala dun — to
        // tenant ko dugna bill nazar aayega, aur wo hamesha "maqool" lagta hai.
        $this->generate('2026-11-15');
        $this->generate('2026-11-15');
        $this->assertCount(1, $this->invoices());

        // Agle mahine wala alag hona chahiye, warna billing aik hi invoice par ruk jaye.
        $this->generate('2026-12-15');
        $this->assertCount(2, $this->invoices());
    }

    public function test_a_tenant_with_no_rate_is_refused_not_billed_zero(): void
    {
        // price_snapshot prod par CHARON tenants par khaali tha. Sifar ka invoice kisi ko ghalat
        // nahi lagta — na tenant ko, na hamein — aur mahine guzar jate hain. Shor machana behtar hai.
        $this->sub->update(['price_snapshot' => null]);

        $this->generate('2026-11-15');
        $this->assertCount(0, $this->invoices());
    }

    public function test_a_day_that_the_month_does_not_have_still_bills(): void
    {
        // 31 wala din February me kabhi nahi aata. Bina is ke us tenant ka invoice us mahine banta
        // hi nahi, aur kisi ko pata nahi chalta ke paisa maanga hi nahi gaya.
        $this->sub->update(['invoice_day' => 31]);

        $this->generate('2027-02-28');
        $this->assertCount(1, $this->invoices(), 'February me 31 tareekh wale ka invoice chhooT gaya.');
        $this->assertSame('2027-02-28', (string) $this->invoices()->first()->period_end);
    }

    public function test_a_subscription_with_no_day_is_never_billed(): void
    {
        // NULL = is ka invoice khud nahi banta. Demo aur trial tenants isi se bachte hain — unhe
        // mahana bill nahi jana chahiye.
        $this->sub->update(['invoice_day' => null]);

        foreach (range(1, 28) as $d) {
            $this->generate(sprintf('2026-11-%02d', $d));
        }
        $this->assertCount(0, $this->invoices());
    }

    public function test_making_an_invoice_never_moves_the_live_subscription_period(): void
    {
        // 9 October 2026: isi jagah se charon live tenants band ho gaye thay. Invoice BANANA period
        // ko haath nahi lagata (sirf adaegi lagati hai, aur wo bhi ab sirf subscription qism par),
        // magar ye guard us din ke baad khali jagah nahi chhoRna chahta.
        $before = (string) $this->sub->current_period_ends_at;

        $this->generate('2026-11-15');

        $this->assertSame($before, (string) $this->sub->fresh()->current_period_ends_at);
    }
}
