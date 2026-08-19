<?php

namespace Tests\MySql;

use App\Models\Tenant\PrintJob;
use App\Services\Printing\PrintJobFactory;
use App\Services\Printing\PrintJobNumber;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\MySql\Support\TenantFixtures;

/**
 * PRINT-JOB-NUMBER-1 — job_no is allocated by one authority and is unique under
 * burst and across processes.
 *
 * The old generator was 'PJ-'+YmdHis+random_int(100,999): 900 values inside a
 * second, so a burst of KOTs on one Hold collided by the birthday bound and
 * threw a duplicate-key error on print_jobs.job_no. These tests hold the fix:
 * the print_jobs.job_no UNIQUE index is the authority and PrintJobFactory
 * bounded-retries a collision. Time is frozen so every job in the burst shares
 * the exact same to-the-second stamp — the precise condition that used to fail —
 * and the collision path is forced deterministically, never left to probability.
 */
class PrintJobNumberMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant(['print_jobs', 'printers', 'branches']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function attributes(?int $printerId): array
    {
        return [
            'printer_id' => $printerId,
            'document_type' => 'receipt',
            'print_status' => 'queued',
            'copy_no' => 1,
            'attempts' => 0,
        ];
    }

    public function test_a_burst_in_the_same_second_all_get_unique_numbers(): void
    {
        Carbon::setTestNow('2026-08-19 18:33:47'); // freeze the second the whole burst shares
        $printerId = $this->makePrinter(['code' => 'BURST']);
        $factory = app(PrintJobFactory::class);

        $count = 300;
        for ($i = 0; $i < $count; $i++) {
            $factory->create($this->attributes($printerId));
        }

        $numbers = PrintJob::on('tenant')->pluck('job_no');
        $this->assertCount($count, $numbers, 'every job in the burst persisted');
        $this->assertSame($count, $numbers->unique()->count(), 'every job_no is unique despite one shared second');

        // The exact old failure condition: same to-the-second stamp for all.
        $this->assertTrue($numbers->every(fn ($n) => str_contains($n, '20260819183347')),
            'all jobs really do share the frozen second');
        $this->assertTrue($numbers->every(fn ($n) => mb_strlen($n) <= 255), 'job_no fits its column');
    }

    public function test_a_colliding_number_is_retried_to_a_unique_one(): void
    {
        $printerId = $this->makePrinter(['code' => 'RETRY']);

        // A number that already exists — what a concurrent process would have
        // written. The generator hands it out twice before a fresh one.
        $taken = 'PJ-20260819183347-DEADBEEF';
        $this->makePrintJob($printerId, ['job_no' => $taken]);
        $before = PrintJob::on('tenant')->count();

        $this->app->instance(PrintJobNumber::class, $this->scriptedNumbers([
            $taken, $taken, 'PJ-20260819183347-FRESH001',
        ]));

        $job = app(PrintJobFactory::class)->create($this->attributes($printerId));

        $this->assertSame('PJ-20260819183347-FRESH001', $job->job_no, 'it retried past the collisions');
        $this->assertSame($before + 1, PrintJob::on('tenant')->count(), 'exactly one new row');
        $this->assertSame(1, PrintJob::on('tenant')->where('job_no', $taken)->count(), 'the pre-existing row is untouched');
    }

    public function test_it_fails_loudly_when_no_unique_number_can_be_found(): void
    {
        $printerId = $this->makePrinter(['code' => 'FAIL']);
        $taken = 'PJ-20260819183347-ALWAYSDUP';
        $this->makePrintJob($printerId, ['job_no' => $taken]);
        $before = PrintJob::on('tenant')->count();

        // A broken generator that never yields anything new.
        $this->app->instance(PrintJobNumber::class, $this->scriptedNumbers([], $taken));

        try {
            app(PrintJobFactory::class)->create($this->attributes($printerId));
            $this->fail('exhausting the attempts must throw, not silently overwrite or drop the job');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('unique', $e->getMessage());
        }

        $this->assertSame($before, PrintJob::on('tenant')->count(), 'no row was created on failure');
    }

    public function test_a_logical_key_collision_is_not_treated_as_a_job_no_collision(): void
    {
        $printerId = $this->makePrinter(['code' => 'LOGKEY']);
        $factory = app(PrintJobFactory::class);

        $factory->create($this->attributes($printerId) + ['logical_key' => 'kot:evt-1:printer-1']);
        $before = PrintJob::on('tenant')->count();

        // The same logical_key is the domain's idempotency signal. The factory
        // must re-throw it (it is NOT a job_no collision), so callers can resolve
        // it to the existing job rather than the factory silently retrying.
        try {
            $factory->create($this->attributes($printerId) + ['logical_key' => 'kot:evt-1:printer-1']);
            $this->fail('a logical_key collision must surface, not be swallowed by the job_no retry');
        } catch (QueryException $e) {
            $this->assertStringContainsString('logical_key', $e->getMessage());
        }

        $this->assertSame($before, PrintJob::on('tenant')->count(), 'no duplicate logical job was created');
    }

    public function test_numbers_carry_the_requested_prefix(): void
    {
        $printerId = $this->makePrinter(['code' => 'PREFIX']);
        $factory = app(PrintJobFactory::class);

        $receipt = $factory->create($this->attributes($printerId));
        $report = $factory->create($this->attributes($printerId) + ['document_type' => 'receipt'], 'RPT');
        $test = $factory->create($this->attributes($printerId), 'PJ-TEST');

        $this->assertStringStartsWith('PJ-', $receipt->job_no);
        $this->assertStringStartsWith('RPT-', $report->job_no);
        $this->assertStringStartsWith('PJ-TEST-', $test->job_no);
    }

    /**
     * A PrintJobNumber whose generate() returns scripted values in order, then
     * falls back to $fallback (or the real generator) once the script is spent.
     *
     * @param  array<int, string>  $values
     */
    private function scriptedNumbers(array $values, ?string $fallback = null): PrintJobNumber
    {
        return new class($values, $fallback) extends PrintJobNumber
        {
            private int $i = 0;

            /** @param array<int, string> $values */
            public function __construct(private array $values, private ?string $fallback) {}

            public function generate(string $prefix = 'PJ'): string
            {
                if ($this->i < count($this->values)) {
                    return $this->values[$this->i++];
                }

                return $this->fallback ?? parent::generate($prefix);
            }
        };
    }
}
