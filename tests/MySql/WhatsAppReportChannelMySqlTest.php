<?php

namespace Tests\MySql;

use App\Models\Master\Tenant;
use App\Services\Reports\Delivery\ReportDelivery;
use App\Services\Reports\Delivery\WhatsAppChannel;
use App\Services\Reports\SalesReportEngine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\MySql\Support\TenantFixtures;

/**
 * WHATSAPP-REPORT-CHANNEL-1 (qadam 3) — the WhatsApp channel.
 *
 * Every failure guarded here is a SILENT one. A mistyped number, a leaked token, one bad entry eating
 * everyone else's report: none of them raise anything the owner would notice. They are found months
 * later, by someone wondering why a report stopped arriving.
 */
class WhatsAppReportChannelMySqlTest extends MySqlTenantTestCase
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
        config()->set('services.whatsapp.template', 'daily_sales_report');
        config()->set('services.whatsapp.language', 'en');

        DB::connection('master')->table('report_share_links')->delete();

        app()->instance('tenant', Tenant::first() ?: new Tenant(['tenant_code' => 'ttest', 'business_name' => 'Test Biz']));
    }

    private function delivery(): ReportDelivery
    {
        return new ReportDelivery(
            businessName: 'Kashif Food',
            label: '2026-09-29 to 2026-09-29',
            filters: app(SalesReportEngine::class)
                ->normalizeFilters(['date_from' => '2026-09-29', 'date_to' => '2026-09-29']),
            sections: ['overview'],
        );
    }

    private function ok(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'x']]], 200)]);
    }

    public function test_numbers_are_normalised_to_the_shape_meta_accepts(): void
    {
        $this->ok();

        // People write 0300-1234567. Meta wants 923001234567 and silently drops anything else — the
        // number looks right on the settings screen and the message simply never arrives.
        app(WhatsAppChannel::class)->send($this->delivery(), [
            '0309 0583647', '+92 332 2617111', '923176402018', '0321-9201804',
        ]);

        $sentTo = [];
        Http::assertSent(function ($request) use (&$sentTo) {
            $sentTo[] = $request->data()['to'];

            return true;
        });

        sort($sentTo);
        $this->assertSame(['923090583647', '923176402018', '923219201804', '923322617111'], $sentTo);
    }

    public function test_a_half_typed_number_is_dropped_rather_than_sent_to_a_stranger(): void
    {
        $this->ok();

        app(WhatsAppChannel::class)->send($this->delivery(), ['923090583647', '03090', '9230905836471234']);

        Http::assertSentCount(1);
    }

    public function test_one_bad_number_does_not_cost_everybody_else_their_report(): void
    {
        $calls = 0;
        Http::fake(function ($request) use (&$calls) {
            $calls++;

            return $request->data()['to'] === '923090583647'
                ? Http::response(['error' => ['message' => 'Recipient not found', 'code' => 131026]], 400)
                : Http::response(['messages' => [['id' => 'x']]], 200);
        });

        // Must NOT throw: the other two went out, and a single wrong digit in one owner's entry
        // cannot be allowed to take the whole list down.
        app(WhatsAppChannel::class)->send($this->delivery(), ['923090583647', '923322617111', '923176402018']);

        $this->assertSame(3, $calls);
    }

    public function test_when_every_number_fails_the_channel_says_so(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Bad', 'code' => 100]], 400)]);

        // Nothing got through — the scheduler must hear about it so it retries on the next tick.
        $this->expectExceptionMessageMatches('/WhatsApp/');
        app(WhatsAppChannel::class)->send($this->delivery(), ['923090583647']);
    }

    public function test_a_failure_message_never_carries_the_token_or_a_whole_number(): void
    {
        // Meta echoes the request on some errors. Putting that straight into last_failure would write
        // a permanent token into the database and the log, readable by anyone who can see either.
        Http::fake(['graph.facebook.com/*' => Http::response([
            'error' => ['message' => 'Unsupported post request', 'code' => 100],
            'request' => ['headers' => ['Authorization' => 'Bearer test-token-never-logged']],
        ], 400)]);

        try {
            app(WhatsAppChannel::class)->send($this->delivery(), ['923090583647']);
            $this->fail('expected the channel to report total failure');
        } catch (\Throwable $e) {
            $this->assertStringNotContainsString('test-token-never-logged', $e->getMessage());
            $this->assertStringNotContainsString('923090583647', $e->getMessage(),
                'a full customer number must not land in last_failure either');
            $this->assertStringContainsString('Unsupported post request', $e->getMessage(),
                'but the reason must survive, or nobody can fix it');
        }
    }

    public function test_the_message_carries_a_link_that_expires(): void
    {
        $this->ok();

        app(WhatsAppChannel::class)->send($this->delivery(), ['923090583647']);

        $link = DB::connection('master')->table('report_share_links')->first();
        $this->assertNotNull($link, 'the summary is useless without the full report behind it');
        $this->assertTrue(Carbon::parse($link->expires_at)->isFuture());
        $this->assertTrue(
            Carbon::parse($link->expires_at)->lessThan(now()->addDays(3)),
            "a shop's takings must not stay readable in an old chat for ever"
        );

        // Only the token travels in the button — Meta appends it to the fixed base URL.
        Http::assertSent(function ($request) use ($link) {
            $button = collect($request->data()['template']['components'])->firstWhere('type', 'button');

            return $button['parameters'][0]['text'] === $link->token;
        });
    }

    public function test_the_approved_template_name_and_language_are_what_goes_out(): void
    {
        $this->ok();

        app(WhatsAppChannel::class)->send($this->delivery(), ['923090583647']);

        // The template is approved and frozen. 'en_US' here — the code the two SAMPLE templates on
        // the same account use — is not a warning, it is a rejected send.
        Http::assertSent(function ($request) {
            return $request->data()['template']['name'] === 'daily_sales_report'
                && $request->data()['template']['language']['code'] === 'en'
                && count($request->data()['template']['components'][0]['parameters']) === 5;
        });
    }
}
