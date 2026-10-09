<?php

namespace Tests\MySql;

use App\Models\Master\Tenant;
use App\Models\Master\WhatsAppMessage;
use App\Services\Reports\Delivery\ReportDelivery;
use App\Services\Reports\Delivery\WhatsAppChannel;
use App\Services\Reports\SalesReportEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\MySql\Support\TenantFixtures;

/**
 * WHATSAPP-USAGE-LEDGER-1 — ginti ka register.
 *
 * Ye saaray guards paison ke hain. Jo yahan toote ga wo kisi screen par nazar nahi aayega: tenant ko
 * bill mil jayega, raqam ghalat hogi, aur sabit karne ko kuch na hoga. Takhmeena 112 nikla tha aur
 * Meta 90 keh raha tha — 15% ka farq, aur wohi farq har mahine tenant ki jaib se jata.
 */
class WhatsAppUsageLedgerMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant(['sales_order_lines', 'sales_orders', 'branches']);
        $this->makeBranch();

        config()->set('services.whatsapp.token', 'test-token-never-logged');
        config()->set('services.whatsapp.phone_number_id', '1335895766273271');
        config()->set('services.whatsapp.template', WhatsAppChannel::TEMPLATE_SUMMARY);
        config()->set('services.whatsapp.language', 'en');
        config()->set('services.whatsapp.rate_pkr', 9.85);
        config()->set('services.whatsapp.cost_pkr', 5.95);

        DB::connection('master')->table('whatsapp_messages')->delete();
        DB::connection('master')->table('report_share_links')->delete();

        app()->instance('tenant', Tenant::first());
    }

    /**
     * Har call par ALAG wamid. Meta asal me yehi karta hai, aur column par unique index hai — aik
     * hi id lautane wala fake asli nahi hota aur chup-chaap rows gira deta hai.
     */
    private function okUnique(): void
    {
        $n = 0;
        Http::fake(function () use (&$n) {
            $n++;

            return Http::response(['messages' => [['id' => 'wamid.T'.$n]]], 200);
        });
    }

    private function delivery(): ReportDelivery
    {
        return new ReportDelivery(
            businessName: 'Kashif Food',
            label: '2026-10-08 to 2026-10-08',
            filters: app(SalesReportEngine::class)
                ->normalizeFilters(['date_from' => '2026-10-08', 'date_to' => '2026-10-08']),
            sections: ['overview'],
        );
    }

    public function test_every_message_leaves_a_row_behind(): void
    {
        $this->okUnique();

        app(WhatsAppChannel::class)->send($this->delivery(), [
            '923328252838', '923331279246', '923090583647',
        ]);

        // Pehle sirf "is raat whatsapp chala" likha jata tha. Kitne numbers par chala ye kahin nahi
        // tha, aur numbers badalte rehte hain — isi liye ginti se bill nahi ban sakta tha.
        $this->assertSame(3, WhatsAppMessage::count());
        $this->assertSame(
            ['923090583647', '923328252838', '923331279246'],
            WhatsAppMessage::orderBy('to')->pluck('to')->all(),
        );
    }

    public function test_a_number_that_failed_is_not_recorded_as_sent(): void
    {
        // Meta aik number par 400 deta hai, baqi par 200. Purana code pehle wale ko chup-chaap nigal
        // leta tha: koi log nahi, koi nishan nahi, aur agle mahine us ka bill bhi chala jata.
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            return $calls === 2
                ? Http::response(['error' => ['message' => 'Invalid parameter']], 400)
                : Http::response(['messages' => [['id' => 'wamid.OK'.$calls]]], 200);
        });

        app(WhatsAppChannel::class)->send($this->delivery(), [
            '923328252838', '923331279246', '923090583647',
        ]);

        $this->assertSame(3, WhatsAppMessage::count());
        $this->assertSame(1, WhatsAppMessage::where('status', 'failed')->count());
        $this->assertSame('923331279246', WhatsAppMessage::where('status', 'failed')->value('to'));
        $this->assertNotNull(WhatsAppMessage::where('status', 'failed')->value('failure_reason'));
    }

    public function test_the_bill_follows_sending_but_the_outcome_is_still_recorded(): void
    {
        $this->okUnique();

        app(WhatsAppChannel::class)->send($this->delivery(), ['923328252838', '923331279246']);

        // Malik ka faisla: charge BHEJNE par hai, pohanchne par nahi. Maine delivered par rakhne ki
        // tajweez di thi (Meta khud sirf delivered par leta hai) — magar qeemat ka faisla malik ka
        // hai, mera nahi.
        $this->assertSame(2, WhatsAppMessage::billable()->count());

        // Magar nateeja phir bhi likha jata hai, aur ye be-kaar nahi: isi se pata chala ke
        // kashiffood ka aik number HAR raat fail hota hai. Maloomat hai, bill ki shart nahi.
        $this->assertSame('accepted', WhatsAppMessage::first()->status);

        WhatsAppMessage::query()->limit(1)->update(['status' => 'failed']);
        $this->assertSame(2, WhatsAppMessage::billable()->count(), 'Nakaam row bill se nikal gayi.');
    }

    public function test_the_rate_is_frozen_on_the_row_not_looked_up_later(): void
    {
        $this->okUnique();

        app(WhatsAppChannel::class)->send($this->delivery(), ['923328252838']);
        $this->assertSame('9.8500', WhatsAppMessage::first()->rate_charged);

        // Rate badalne se PURANI row nahi hilni chahiye, warna guzre mahine ka invoice khud ba khud
        // badal jayega — wohi usool jo catering ke invoice par hai.
        config()->set('services.whatsapp.rate_pkr', 12.00);
        $this->assertSame('9.8500', WhatsAppMessage::first()->fresh()->rate_charged);

        app(WhatsAppChannel::class)->send($this->delivery(), ['923331279246']);
        $this->assertSame('12.0000', WhatsAppMessage::orderByDesc('id')->first()->rate_charged);
    }

    public function test_our_own_cost_is_recorded_so_the_margin_can_be_measured(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(
            ['messages' => [['id' => 'wamid.COST']]], 200,
        )]);

        app(WhatsAppChannel::class)->send($this->delivery(), ['923328252838']);

        // Margin naapa jaye, farz na kiya jaye: dollar ya tax hile to wo yahan dikhega.
        $row = WhatsAppMessage::first();
        $this->assertSame('5.9500', $row->provider_cost);
        $this->assertSame('9.8500', $row->rate_charged);
    }

    public function test_a_broken_ledger_never_stops_the_report(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(
            ['messages' => [['id' => 'wamid.X']]], 200,
        )]);

        // Register billing ke liye hai; report malik ke karobar ke liye. Agar register na likha ja
        // sake to us ki saza report ko nahi milni chahiye — warna hum aik hisaab ke masle par wo
        // cheez rok dete jo har subah pohanchni chahiye.
        DB::connection('master')->statement('ALTER TABLE whatsapp_messages RENAME TO whatsapp_messages_hidden');

        try {
            app(WhatsAppChannel::class)->send($this->delivery(), ['923328252838']);
            $sent = true;
        } catch (\Throwable) {
            $sent = false;
        } finally {
            DB::connection('master')->statement('ALTER TABLE whatsapp_messages_hidden RENAME TO whatsapp_messages');
        }

        $this->assertTrue($sent, 'Register na likh sakne par report ruk gayi.');
        Http::assertSentCount(1);
    }

    public function test_the_wamid_comes_back_so_the_webhook_can_find_the_row(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(
            ['messages' => [['id' => 'wamid.HBgMOTIzMzI4MjUyODM4']]], 200,
        )]);

        app(WhatsAppChannel::class)->send($this->delivery(), ['923328252838']);

        // Is ke baghair "pohancha ya nahi" ka jawab kabhi nahi milta: webhook message ko wamid se
        // pehchanta hai, aur bill delivered par banta hai.
        $this->assertSame('wamid.HBgMOTIzMzI4MjUyODM4', WhatsAppMessage::first()->wamid);
    }

    public function test_the_backfill_reads_the_clock_the_sender_actually_uses(): void
    {
        // Ye prod par TOOT chuka hai. Maine backfill me tenant ka timezone `tenants.timezone` se
        // liya tha. Wo column prod par KHAALI hai, to app.timezone (UTC) lag gaya, to schedule ke
        // 00:30 aur 02:30 seedha 00:30/02:30 UTC ban gaye — jabke asal buckets 19:30 aur 21:30 UTC
        // hain. Nateeja: koi bucket kisi tenant par match hi nahi kiya aur poora hisaab sifar aaya.
        //
        // Sahi jawab wahi hai jo BHEJNE WALA khud istemal karta hai — ReportScheduleService::
        // timezone() (active branch ka business timezone). Ginti usi ghari se honi chahiye jis se
        // bhejna hua, warna dono kisi din chup-chaap alag ho jate hain.
        //
        // Guard matn par hai, chalne par nahi: command saaray master tenants par ghoomti hai, jo
        // test ke mahaul me asli shakl nahi banta. Jo cheez TOOTI thi wo yehi do satrein hain.
        $body = file_get_contents(base_path('app/Console/Commands/WhatsAppBackfillUsageCommand.php'));

        $this->assertStringContainsString('ReportScheduleService::class)->timezone()', $body);
        $this->assertStringNotContainsString('$tenant->timezone', $body);
    }
}
