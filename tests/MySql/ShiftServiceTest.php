<?php

namespace Tests\MySql;

use App\Exceptions\ShiftException;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Shift;
use App\Models\Tenant\Terminal;
use App\Services\Sales\ShiftService;
use Carbon\Carbon;
use Tests\MySql\Support\TenantFixtures;

/**
 * SHIFT-TIMEZONE-BUSINESS-DATE-1 — the canonical ShiftService, on the authoritative MySQL test DB.
 * Includes a GENUINE two-process concurrency proof (spawns the real service in two OS processes).
 */
class ShiftServiceTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    public function test_open_freezes_business_date_and_timezone(): void
    {
        $this->cleanTenant(['shifts', 'terminals', 'branches']);
        $branchId = $this->makeBranch(['timezone' => 'Asia/Karachi']);
        $terminalId = $this->makeTerminal($branchId);
        $userId = $this->makeUser();

        $shift = app(ShiftService::class)->open(
            Branch::on('tenant')->find($branchId),
            Terminal::on('tenant')->find($terminalId),
            $userId, 1000.0, 'open note'
        );

        $expected = Carbon::now('Asia/Karachi')->toDateString();
        $this->assertSame('Asia/Karachi', $shift->timezone_name);
        $this->assertSame($expected, $shift->business_date->toDateString(), 'business_date is the branch-tz calendar date at open');
        $this->assertSame('open', $shift->status);
    }

    public function test_assert_open_shift_is_universal_and_controlled(): void
    {
        $this->cleanTenant(['shifts', 'terminals', 'branches']);
        $branchId = $this->makeBranch(['timezone' => 'Asia/Karachi']);
        // requires_shift = 0 must NOT bypass the rule (universal enforcement).
        $terminalId = $this->makeTerminal($branchId, ['requires_shift' => 0]);
        $terminal = Terminal::on('tenant')->find($terminalId);
        $svc = app(ShiftService::class);

        // No open shift -> controlled ShiftException even though requires_shift = 0.
        try {
            $svc->assertOpenShift($terminal);
            $this->fail('Expected ShiftException when no shift is open.');
        } catch (ShiftException $e) {
            $this->assertStringContainsStringIgnoringCase('shift', $e->getMessage());
        }

        // Open one, then assertOpenShift returns it.
        $opened = $svc->open(Branch::on('tenant')->find($branchId), $terminal, $this->makeUser(), 0.0);
        $this->assertSame($opened->id, $svc->assertOpenShift($terminal)->id);
    }

    public function test_two_processes_opening_same_terminal_yield_exactly_one_shift(): void
    {
        $this->cleanTenant(['shifts', 'terminals', 'branches']);
        $branchId = $this->makeBranch(['timezone' => 'Asia/Karachi']);
        $terminalId = $this->makeTerminal($branchId);
        $userId = $this->makeUser();

        $worker = base_path('tests/MySql/Support/shift_open_worker.php');
        $php = PHP_BINARY;
        $env = ['EDGE_TEST_TENANT_DB' => $this->tenantDb, 'APP_ENV' => 'testing'];

        // Launch two real processes as simultaneously as possible against the same terminal.
        $procs = [];
        $pipes = [];
        for ($i = 0; $i < 2; $i++) {
            $procs[$i] = proc_open(
                [$php, $worker, (string) $branchId, (string) $terminalId, (string) $userId],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes[$i],
                base_path(),
                array_merge(getenv() ?: [], $env)
            );
        }
        $out = [];
        for ($i = 0; $i < 2; $i++) {
            $out[$i] = trim(stream_get_contents($pipes[$i][1]));
            fclose($pipes[$i][1]);
            fclose($pipes[$i][2]);
            proc_close($procs[$i]);
        }

        $opened = count(array_filter($out, fn ($o) => str_starts_with($o, 'OPENED:')));
        $already = count(array_filter($out, fn ($o) => $o === 'ALREADY_OPEN'));
        $errors = array_filter($out, fn ($o) => str_starts_with($o, 'ERROR:'));

        $this->assertEmpty($errors, 'No process may crash: ' . implode(' | ', $out));
        $this->assertSame(1, $opened, 'Exactly one process opens the shift: ' . implode(' | ', $out));
        $this->assertSame(1, $already, 'The other gets a controlled ALREADY_OPEN: ' . implode(' | ', $out));

        $this->assertSame(
            1,
            Shift::on('tenant')->where('terminal_id', $terminalId)->where('status', 'open')->count(),
            'The per-terminal invariant holds: exactly one OPEN shift.'
        );
    }
}
