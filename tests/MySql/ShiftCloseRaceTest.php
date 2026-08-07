<?php

namespace Tests\MySql;

use App\Models\Tenant\Branch;
use App\Models\Tenant\Shift;
use App\Models\Tenant\Terminal;
use App\Services\Sales\ShiftService;
use Tests\MySql\Support\TenantFixtures;

/**
 * SHIFT-TIMEZONE-BUSINESS-DATE-HARDEN-1 (#1) — the shift-close vs POS-operation race, proven with
 * TWO real OS processes calling the REAL ShiftService locking contract (lockOpenShiftForTerminal /
 * lockOpenShiftForBranch vs assertClosableUnderLock — exactly what the controllers delegate to).
 *
 * Invariant under test: a new commercial operation can NEVER commit against a shift that has been
 * closed. Whichever process takes the shift row lock first wins deterministically; the other is
 * serialized behind it. No 500s, no orphaned state.
 */
class ShiftCloseRaceTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private string $opWorker;
    private string $closeWorker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->opWorker = base_path('tests/MySql/Support/shift_op_worker.php');
        $this->closeWorker = base_path('tests/MySql/Support/shift_close_worker.php');
    }

    public function test_close_vs_new_sale_race_never_commits_a_sale_after_close(): void
    {
        $this->runRace('sale');
    }

    public function test_close_vs_hold_race_never_commits_a_hold_after_close(): void
    {
        $this->runRace('hold');
    }

    public function test_close_vs_open_table_race_never_commits_a_table_after_close(): void
    {
        $this->runRace('table');
    }

    private function runRace(string $mode): void
    {
        $this->cleanTenant(['restaurant_table_sessions', 'restaurant_tables', 'restaurant_floors', 'sales_orders', 'shifts', 'terminals', 'branches']);
        $branchId = $this->makeBranch(['timezone' => 'Asia/Karachi']);
        $terminalId = $this->makeTerminal($branchId);
        $userId = $this->makeUser();
        $tableId = $mode === 'table' ? $this->makeTable($branchId) : null;

        // ── Scenario A: operation acquires the shift lock FIRST, close contends ──────────────────
        $shiftA = app(ShiftService::class)->open(Branch::on('tenant')->find($branchId), Terminal::on('tenant')->find($terminalId), $userId, 0.0);
        $readyFile = tempnam(sys_get_temp_dir(), 'shiftrace_');
        @unlink($readyFile); // worker will (re)create it once it holds the lock

        $op = $this->startWorker(
            [PHP_BINARY, $this->opWorker, (string) $branchId, (string) $terminalId, (string) $userId, $mode, (string) $tableId],
            ['SHIFT_OP_SLEEP_MS' => '900', 'SHIFT_OP_READY_FILE' => $readyFile]
        );
        $this->waitForFile($readyFile, $op, 30.0); // start close ONLY once op holds the lock
        $close = $this->startWorker([PHP_BINARY, $this->closeWorker, (string) $shiftA->id], []);

        $opOut = $this->finishWorker($op);
        $closeOut = $this->finishWorker($close);
        @unlink($readyFile);

        $this->assertStringStartsWith('COMMITTED:', $opOut, "[$mode] op holds the lock first, so it must commit: op=$opOut close=$closeOut");
        $this->assertStringNotContainsString('ERROR', $opOut . $closeOut, "[$mode] no process may crash: op=$opOut close=$closeOut");

        $shiftA->refresh();
        if ($mode === 'sale') {
            // A paid sale is not unresolved work, so the close proceeds — but only AFTER the sale
            // committed under the still-open shift. Safe.
            $this->assertSame('CLOSED', $closeOut, "[$mode] close proceeds after the paid sale commits");
            $this->assertSame('closed', $shiftA->status);
        } else {
            // A held sale / open table IS unresolved work, so the close is blocked and the shift
            // stays open. The new state is never orphaned by a concurrent close.
            $this->assertSame('BLOCKED', $closeOut, "[$mode] unresolved work blocks the close");
            $this->assertSame('open', $shiftA->status, "[$mode] shift stays open when its close is blocked");
        }

        // Reset scenario A's leftovers (settle the held/table row + close the shift) so scenario B
        // starts from a clean, shift-free branch/terminal.
        $this->tenant()->table('sales_orders')->where('shift_id', $shiftA->id)->update(['status' => 'paid']);
        $this->tenant()->table('restaurant_table_sessions')->where('opened_shift_id', $shiftA->id)->update(['status' => 'closed']);
        $this->tenant()->table('shifts')->where('id', $shiftA->id)->update(['status' => 'closed']);

        // ── Scenario B: close acquires FIRST (no work yet); a later operation must be rejected ────
        $shiftB = app(ShiftService::class)->open(Branch::on('tenant')->find($branchId), Terminal::on('tenant')->find($terminalId), $this->makeUser(), 0.0);
        $closeB = $this->finishWorker($this->startWorker([PHP_BINARY, $this->closeWorker, (string) $shiftB->id], []));
        $this->assertSame('CLOSED', $closeB, "[$mode] a clean shift closes");

        $before = $this->commercialRowCount($mode, $shiftB->id);
        $opB = $this->finishWorker($this->startWorker(
            [PHP_BINARY, $this->opWorker, (string) $branchId, (string) $terminalId, (string) $userId, $mode, (string) $tableId],
            []
        ));
        $this->assertSame('REJECTED_NO_SHIFT', $opB, "[$mode] no new operation may run after the shift closed: $opB");
        $this->assertSame($before, $this->commercialRowCount($mode, $shiftB->id), "[$mode] no commercial row committed against the closed shift");
    }

    private function commercialRowCount(string $mode, int $shiftId): int
    {
        if ($mode === 'table') {
            return $this->tenant()->table('restaurant_table_sessions')->where('opened_shift_id', $shiftId)->count();
        }

        return $this->tenant()->table('sales_orders')->where('shift_id', $shiftId)->count();
    }

    private function startWorker(array $cmd, array $env): array
    {
        $pipes = [];
        $proc = proc_open(
            $cmd,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
            array_merge(getenv() ?: [], ['EDGE_TEST_TENANT_DB' => $this->tenantDb, 'APP_ENV' => 'testing'], $env)
        );

        return ['proc' => $proc, 'pipes' => $pipes];
    }

    private function finishWorker(array $h): string
    {
        $out = trim(stream_get_contents($h['pipes'][1]));
        fclose($h['pipes'][1]);
        fclose($h['pipes'][2]);
        proc_close($h['proc']);

        return $out;
    }

    /**
     * Deterministic worker-ready handshake: wait (bounded) for the op worker to signal it holds the
     * shift lock via $path. Generous bound tolerates worker Laravel-boot latency under full-suite load;
     * fails FAST (with the worker's stderr) if the process exits before signalling, instead of masking a
     * real crash behind the full timeout.
     */
    private function waitForFile(string $path, array $workerHandle, float $timeoutSeconds): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            clearstatcache(true, $path);
            if (is_file($path) && filesize($path) > 0) {
                return;
            }
            $status = proc_get_status($workerHandle['proc']);
            if (! ($status['running'] ?? true)) {
                $err = trim(stream_get_contents($workerHandle['pipes'][2]) ?: '');
                $this->fail("Op worker exited (code {$status['exitcode']}) before acquiring the shift lock. stderr: {$err}");
            }
            usleep(20000);
        }
        $this->fail('Timed out waiting for the op worker to acquire the shift lock after ' . $timeoutSeconds . 's.');
    }
}
