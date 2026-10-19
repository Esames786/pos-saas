<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\Reports\SalesReportCenterController;
use App\Models\Master\Tenant;
use App\Services\Reports\Delivery\WhatsAppChannel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * WHATSAPP-REPORT-CHANNEL-1 — the settings screen.
 *
 * Until this existed, changing a number meant someone editing the database by hand. The guards here
 * are about the one failure this screen could introduce: a number that SAVES but never RECEIVES.
 */
class ReportChannelSettingsMySqlTest extends MySqlTenantTestCase
{
    private function tenant(): Tenant
    {
        $t = Tenant::on('master')->first();

        if (! $t) {
            $this->markTestSkipped('no tenant on the master test database');
        }

        app()->instance('tenant', $t);

        return $t;
    }

    private function save(array $input)
    {
        return app(SalesReportCenterController::class)
            ->saveChannels(Request::create('/reports/center/channels', 'POST', $input));
    }

    public function test_however_a_number_is_typed_it_is_stored_the_way_meta_needs_it(): void
    {
        $t = $this->tenant();

        $this->save(['channels' => ['email', 'whatsapp'], 'whatsapp' => '0309 0583647, +92 332 2617111, 923176402018']);

        $this->assertSame(
            ['923090583647', '923322617111', '923176402018'],
            (array) $t->fresh()->report_whatsapp,
        );
    }

    public function test_the_screen_and_the_sender_use_the_SAME_rule(): void
    {
        // The whole point. Two copies of "what is a valid number" would drift, and the day they did a
        // number would save happily and then silently never receive anything.
        $typed = ['0309 0583647', '03090', '+92 332 2617111', 'not-a-number', '9230905836471234'];

        $t = $this->tenant();
        $this->save(['channels' => ['email', 'whatsapp'], 'whatsapp' => implode(', ', $typed)]);

        $this->assertSame(
            WhatsAppChannel::normalise($typed),
            (array) $t->fresh()->report_whatsapp,
            'what the settings screen keeps must be exactly what the channel would send to',
        );
    }

    public function test_turning_whatsapp_on_with_no_usable_number_is_refused(): void
    {
        $t = $this->tenant();
        $t->report_channels = ['email'];
        $t->report_whatsapp = [];
        $t->save();

        $response = $this->save(['channels' => ['email', 'whatsapp'], 'whatsapp' => 'not-a-number, 12345']);

        // Saving this would look like success and then deliver nothing at all.
        $this->assertNotEmpty($response->getSession()->get('errors'));
        $this->assertSame(['email'], (array) $t->fresh()->report_channels, 'and nothing was changed');
    }

    public function test_a_tenant_can_never_be_left_with_no_way_to_receive_anything(): void
    {
        $t = $this->tenant();

        // Unticking everything is almost certainly a slip, not a wish to stop reports entirely.
        $this->save(['channels' => [], 'whatsapp' => '']);

        $this->assertSame(['email'], (array) $t->fresh()->report_channels);
    }

    public function test_the_operator_is_told_what_was_thrown_away(): void
    {
        $this->tenant();

        $response = $this->save(['channels' => ['email', 'whatsapp'], 'whatsapp' => '03090583647, oops, 12']);
        $status = (string) $response->getSession()->get('status');

        // Silently dropping entries is how a shop spends a week wondering why one owner gets nothing.
        $this->assertStringContainsString('skipped', $status);
    }

    public function test_the_buttons_no_longer_promise_email(): void
    {
        // The button names a destination, not a channel — it may now carry WhatsApp too.
        foreach ([
            'resources/views/tenant/pos/index.blade.php',
            'resources/views/tenant/reports/center/index.blade.php',
        ] as $view) {
            $body = file_get_contents(base_path($view));

            $this->assertStringNotContainsString('Email to owner', $body);
            $this->assertStringNotContainsString('>Email Now<', $body);
        }
    }

    public function test_only_someone_who_may_save_the_setting_can_see_the_numbers(): void
    {
        // On khatribiryani SEVEN roles can open the Report Center — Delivery, Dine In, Takeaway,
        // Quick Sale, Accounts, Manager, Owner — while only Owner holds the channels permission.
        // Ungated, this card puts the owner's and managers' personal mobile numbers on a delivery
        // rider's screen and then refuses their Save with a 403. The gate IS the permission that
        // governs saving, so what you can see and what you can save cannot drift apart.
        $body = file_get_contents(base_path('resources/views/tenant/reports/center/index.blade.php'));

        $open = strpos($body, "@can('tenant.reports.center.channels')");
        $this->assertNotFalse($open, 'The report-delivery card is not gated at all.');

        $close = strpos($body, '@endcan', $open);
        $this->assertNotFalse($close, 'The gate is never closed.');

        $gated = substr($body, $open, $close - $open);

        // The number field is the thing that leaks, so it is the thing that must sit inside.
        $this->assertStringContainsString('name="whatsapp"', $gated);
        $this->assertStringContainsString('Report delivery', $gated);

        // ...and it must not also appear anywhere outside the gate.
        $this->assertSame(1, substr_count($body, 'name="whatsapp"'));
    }
}
