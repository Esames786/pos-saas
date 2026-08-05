<?php

namespace Tests\MySql;

use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * §8 — Historical Reminder destination proof (MYSQL-TEST-FOUNDATION-1).
 *
 * Scenario: an original Reminder was printed to Printer A. Cloud config later
 * removes Printer A. A sent-item cancellation must still route the cancellation
 * Reminder to that historical destination.
 *
 * DB-level findings proven here (test-infrastructure only, no printing implemented):
 *   1. print_jobs.printer_id has NO foreign key to printers, and print_jobs stores
 *      ONLY printer_id — it does not snapshot the printer name / ip / port. So if a
 *      printer row is removed, the historical print job keeps a DANGLING printer_id
 *      and its destination (name/ip) becomes UNRESOLVABLE.
 *   2. Retaining/tombstoning the printer (is_active=0) preserves resolvability.
 *
 * Verdict for the Edge print-audit contract (§G): the print event MUST snapshot the
 * physical destination (name/ip/port/routing identity) AND/OR the printer must be
 * tombstoned rather than deleted. Recommended: both.
 */
class HistoricalReminderDestinationTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    public function test_deleting_a_printer_leaves_history_but_loses_the_destination(): void
    {
        $this->cleanTenant(['print_jobs', 'printers']);

        $printerId = $this->makePrinter(['name' => 'Kitchen A', 'ip_address' => '192.168.1.50']);
        $jobId = $this->makePrintJob($printerId, ['document_type' => 'reminder']);

        // Config refresh removes Printer A (no FK on print_jobs.printer_id -> no cascade).
        DB::connection('tenant')->table('printers')->where('id', $printerId)->delete();

        // The historical job row survives (not cascaded)...
        $job = DB::connection('tenant')->table('print_jobs')->where('id', $jobId)->first();
        $this->assertNotNull($job, 'Historical print job must survive printer removal.');
        $this->assertSame($printerId, (int) $job->printer_id, 'printer_id is retained but now dangling.');

        // ...but the destination is unresolvable: joining to printers yields nothing.
        $resolvedName = DB::connection('tenant')->table('print_jobs as j')
            ->leftJoin('printers as p', 'p.id', '=', 'j.printer_id')
            ->where('j.id', $jobId)
            ->value('p.name');

        $this->assertNull(
            $resolvedName,
            'Destination name is LOST after printer deletion — print_jobs snapshots no destination. '
            . 'Historical cancellation-Reminder routing would break.'
        );
    }

    public function test_tombstoning_the_printer_preserves_historical_destination(): void
    {
        $this->cleanTenant(['print_jobs', 'printers']);

        $printerId = $this->makePrinter(['name' => 'Kitchen A', 'ip_address' => '192.168.1.50']);
        $jobId = $this->makePrintJob($printerId, ['document_type' => 'reminder']);

        // Safe pattern: tombstone instead of delete.
        DB::connection('tenant')->table('printers')->where('id', $printerId)
            ->update(['is_active' => 0, 'updated_at' => now()]);

        $row = DB::connection('tenant')->table('print_jobs as j')
            ->leftJoin('printers as p', 'p.id', '=', 'j.printer_id')
            ->where('j.id', $jobId)
            ->first(['p.name', 'p.ip_address', 'p.is_active']);

        $this->assertSame('Kitchen A', $row->name, 'Tombstoned printer keeps destination resolvable.');
        $this->assertSame('192.168.1.50', $row->ip_address);
        $this->assertSame(0, (int) $row->is_active, 'Printer is inactive but retained for history.');
    }
}
