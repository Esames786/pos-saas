<?php

namespace Tests\MySql;

use App\Models\Tenant\PrintJob;
use App\Services\Printing\PrintJobService;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * A TRANSIENT PRINTER FAILURE MUST NOT LOSE THE TICKET.
 *
 * Port 9100 accepts one connection at a time, so a KOT and a receipt raised seconds apart collide:
 * the second connection cannot open, the agent abandons it after its socket timeout, and the slip
 * is gone until a human notices. Khatri Biryani were re-raising 48.7% of bills by hand — the live
 * proof being a receipt that failed at 14:55:04 and printed at 14:55:10 on the SAME printer.
 *
 * The server now hands a connect-phase failure straight back to the queue, so the agent retries on
 * its next poll. Only connect-phase errors qualify: once bytes are on the wire the printer may
 * already have printed, and re-sending would hand the customer a second copy of the same bill.
 */
class PrintAutoRequeueMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant(['print_jobs', 'sales_orders', 'printers', 'branches']);
        $this->branchId = $this->makeBranch();
    }

    private function job(array $attrs = []): PrintJob
    {
        $saleId = $this->makeSale($this->branchId, ['status' => 'paid', 'completed_at' => now()]);
        $printerId = $this->makePrinter(['code' => 'P1', 'print_role' => 'both', 'branch_id' => $this->branchId]);

        return PrintJob::create(array_merge([
            'job_no' => 'PJ-' . uniqid(),
            'branch_id' => $this->branchId,
            'printer_id' => $printerId,
            'document_type' => 'receipt',
            'print_status' => 'queued',
            'reference_type' => 'sales_order',
            'reference_id' => $saleId,
            'attempts' => 0,
        ], $attrs));
    }

    public function test_a_connection_timeout_goes_back_to_the_queue_instead_of_being_lost(): void
    {
        $job = $this->job();

        app(PrintJobService::class)->markFailed($job, 'Printer connection timed out.');

        $job->refresh();
        $this->assertSame('queued', $job->print_status, 'a transient failure must be retried, not abandoned');
        $this->assertSame(1, (int) $job->attempts);
        $this->assertNull($job->claimed_at, 'the claim must be released so any agent can pick it up');
    }

    public function test_it_gives_up_after_the_bounded_number_of_attempts(): void
    {
        $job = $this->job(['attempts' => PrintJobService::MAX_AUTO_REQUEUE - 1]);

        app(PrintJobService::class)->markFailed($job, 'Printer connection timed out.');

        $job->refresh();
        $this->assertSame('failed', $job->print_status, 'a printer that is genuinely down must surface, not loop forever');
        $this->assertSame(PrintJobService::MAX_AUTO_REQUEUE, (int) $job->attempts);
        $this->assertNotNull($job->failed_at);
    }

    public function test_a_non_transient_failure_is_never_re_sent(): void
    {
        $job = $this->job();

        // Anything that is not a connect-phase error might mean the ticket already came out.
        app(PrintJobService::class)->markFailed($job, 'No IP address configured for this printer.');

        $job->refresh();
        $this->assertSame('failed', $job->print_status, 'only connect-phase failures may be re-sent');
        $this->assertSame(1, (int) $job->attempts);
    }

    public function test_an_already_printed_job_is_never_demoted_or_re_queued(): void
    {
        $job = $this->job(['print_status' => 'printed']);

        app(PrintJobService::class)->markFailed($job, 'Printer connection timed out.');

        $job->refresh();
        $this->assertSame('printed', $job->print_status, 'a completed print can never be reopened');
        $this->assertSame(0, (int) $job->attempts);
    }
}
