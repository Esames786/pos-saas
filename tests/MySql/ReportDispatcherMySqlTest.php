<?php

namespace Tests\MySql;

use App\Models\Master\Tenant;
use App\Services\Reports\Delivery\ReportDispatcher;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * WHATSAPP-REPORT-CHANNEL-1 (qadam 2) — one door, and it stays one door.
 *
 * Three places send a report: the POS Quick Report button, the Report Center button, and the nightly
 * cron. Each used to build its own mail. The danger in that is not duplication, it is DRIFT: the path
 * somebody forgets keeps emailing while the others move to WhatsApp, and the owner gets some reports
 * one way and some the other with nothing on either to explain why.
 */
class ReportDispatcherMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant(['branches']);
        $this->branchId = $this->makeBranch();
    }

    private function bindTenant(mixed $channels = null): void
    {
        app()->instance('tenant', new Tenant([
            'tenant_code' => 'ttest', 'business_name' => 'Test Biz',
            'owner_email' => 'owner@example.test', 'report_channels' => $channels,
        ]));
    }

    private function dispatcher(): ReportDispatcher
    {
        return app(ReportDispatcher::class);
    }

    /**
     * The guard that keeps the door shut.
     *
     * A behavioural test cannot prove a FOURTH caller will not appear next month and send its own
     * mail — so this reads the source. Any report-sending Mail::to() outside EmailChannel fails the
     * build, whoever adds it and whatever they meant.
     */
    public function test_no_report_leaves_by_any_door_but_the_dispatcher(): void
    {
        $offenders = [];

        foreach ($this->phpFilesIn(base_path('app')) as $file) {
            $body = file_get_contents($file);

            if (! str_contains($body, 'Mail::to')) {
                continue;
            }
            if (! str_contains($body, 'SalesReportMail')) {
                continue; // not a sales report — catering and the rest are their own business
            }
            if (str_contains($file, "EmailChannel.php")) {
                continue; // the one door
            }

            $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
        }

        $this->assertSame([], $offenders,
            'a sales report may only be emailed from EmailChannel — add the channel, not another Mail::to()');
    }

    public function test_a_tenant_that_configures_nothing_still_gets_email(): void
    {
        $this->bindTenant(null);

        $this->assertSame(['email'], $this->dispatcher()->channelsFor(null));
        $this->assertSame(['email'], $this->dispatcher()->channelsFor($this->branchId),
            'an unconfigured branch falls through to the tenant, and the tenant default is email');
    }

    public function test_the_branch_answer_wins_over_the_tenant_answer(): void
    {
        // A two-branch tenant wants each branch's figures going where that branch says.
        $this->bindTenant(['email']);
        DB::connection('tenant')->table('branches')->where('id', $this->branchId)
            ->update(['report_channels' => json_encode(['email'])]);

        $this->assertSame(['email'], $this->dispatcher()->channelsFor($this->branchId));

        // NULL at branch level is not "no channels" — it means "ask the tenant".
        DB::connection('tenant')->table('branches')->where('id', $this->branchId)
            ->update(['report_channels' => null]);
        $this->bindTenant(['email']);

        $this->assertSame(['email'], $this->dispatcher()->channelsFor($this->branchId));
    }

    public function test_a_channel_this_build_does_not_have_cannot_take_the_report_down(): void
    {
        // Settings outlive code: a row may name 'whatsapp' before that channel ships, or after it is
        // removed. Reading one must not throw — the nightly report has to go out regardless.
        $this->bindTenant(['email', 'whatsapp', 'carrier-pigeon']);

        $this->assertSame(['email'], $this->dispatcher()->channelsFor(null),
            'unknown channels are dropped, and email still goes');
    }

    public function test_a_settings_row_naming_only_unknown_channels_still_sends_email(): void
    {
        // The worst shape: everything configured is unavailable. Returning [] here would mean the
        // report silently stops — the owner would notice only by its absence, days later.
        $this->bindTenant(['whatsapp']);

        $this->assertSame(['email'], $this->dispatcher()->channelsFor(null));
    }

    /** @return list<string> */
    private function phpFilesIn(string $dir): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));

        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
