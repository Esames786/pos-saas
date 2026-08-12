<?php

namespace Tests\MySql;

use App\Models\Tenant\PrintJob;
use App\Models\Tenant\SalesOrder;
use App\Services\Printing\PrintJobService;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\MySql\Support\TenantFixtures;

/**
 * "Clear it off the queue" must NOT be markPrinted().
 *
 * A superseded/obsolete job was administratively cleared by calling markPrinted() — which claims a
 * PHYSICAL print and carries side effects: receipt_print_count++, kot_print_count++, and the
 * last-printed timestamps. That wrote false history. dismiss() (cancelObsolete) is the correct
 * operation: a job that printed nothing moves to a terminal state with none of those effects.
 */
class PrintJobDismissMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'print_jobs', 'sales_order_lines', 'sales_orders', 'branches', 'users',
        ]);
    }

    private function service(): PrintJobService
    {
        return app(PrintJobService::class);
    }

    public function test_dismissing_a_failed_job_never_touches_print_counters_or_printed_at(): void
    {
        $branchId = $this->makeBranch();
        $saleId = $this->makeSale($branchId, ['receipt_print_count' => 2, 'kot_print_count' => 1]);
        $jobId = $this->makePrintJob(null, [
            'document_type' => 'receipt', 'print_status' => 'failed', 'printed_at' => null,
            'failed_at' => now(), 'error_message' => 'ehostunreach', 'reference_type' => 'sales_order',
            'reference_id' => $saleId,
        ]);

        $this->service()->cancelObsolete(PrintJob::findOrFail($jobId), 'Superseded by successful copy');

        $job = PrintJob::findOrFail($jobId);
        $this->assertSame('cancelled', $job->print_status);
        $this->assertNull($job->printed_at, 'A dismissed job printed nothing — printed_at must stay NULL.');
        $this->assertSame('Superseded by successful copy', $job->error_message);

        $sale = SalesOrder::findOrFail($saleId);
        $this->assertSame(2, (int) $sale->receipt_print_count, 'Dismiss must not increment the receipt counter.');
        $this->assertSame(1, (int) $sale->kot_print_count, 'Dismiss must not touch the KOT counter.');
        $this->assertNull($sale->last_receipt_printed_at, 'Dismiss must not stamp a print time.');
    }

    public function test_a_printed_job_cannot_be_dismissed(): void
    {
        $branchId = $this->makeBranch();
        $saleId = $this->makeSale($branchId);
        $jobId = $this->makePrintJob(null, [
            'print_status' => 'printed', 'printed_at' => now(),
            'reference_type' => 'sales_order', 'reference_id' => $saleId,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only a failed or queued job can be dismissed');

        $this->service()->cancelObsolete(PrintJob::findOrFail($jobId), 'should be refused');
    }

    public function test_a_dismissed_job_can_still_be_retried_back_to_the_queue(): void
    {
        $branchId = $this->makeBranch();
        $saleId = $this->makeSale($branchId);
        $jobId = $this->makePrintJob(null, [
            'document_type' => 'kot', 'print_status' => 'queued', 'printed_at' => null,
            'reference_type' => 'sales_order', 'reference_id' => $saleId,
        ]);

        $service = $this->service();
        $service->cancelObsolete(PrintJob::findOrFail($jobId), 'changed my mind');
        $this->assertSame('cancelled', PrintJob::findOrFail($jobId)->print_status);

        // The existing recovery contract: failed|cancelled -> queued.
        $service->requeueFailed(PrintJob::findOrFail($jobId));
        $job = PrintJob::findOrFail($jobId);
        $this->assertSame('queued', $job->print_status);
        $this->assertNull($job->error_message);
    }

    public function test_markprinted_is_the_physical_path_and_still_carries_its_side_effects(): void
    {
        // Guards the DISTINCTION: markPrinted remains the transport-success operation with counters.
        // This is what dismiss() must never be conflated with.
        $branchId = $this->makeBranch();
        $saleId = $this->makeSale($branchId, ['receipt_print_count' => 0]);
        $jobId = $this->makePrintJob(null, [
            'document_type' => 'receipt', 'print_status' => 'queued', 'printed_at' => null,
            'reference_type' => 'sales_order', 'reference_id' => $saleId,
        ]);

        $this->service()->markPrinted(PrintJob::findOrFail($jobId));

        $sale = SalesOrder::findOrFail($saleId);
        $this->assertSame(1, (int) $sale->receipt_print_count, 'markPrinted DOES increment — that is exactly why it is wrong for queue cleanup.');
        $this->assertNotNull(PrintJob::findOrFail($jobId)->printed_at);
    }
}
