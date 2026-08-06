<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\ShiftController;
use App\Models\Tenant\Branch;
use App\Models\Tenant\DailyClosing;
use App\Models\Tenant\Shift;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\User;
use App\Services\Sales\ShiftService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * SHIFT-BRANCH-UX-1 — branch-oriented open/close over the unchanged per-terminal model.
 */
class ShiftBranchUxTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private ?string $originalDefaultConnection = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalDefaultConnection = config('database.default');
        DB::setDefaultConnection('tenant'); // controller exists: validation resolves against tenant
    }

    protected function tearDown(): void
    {
        if ($this->originalDefaultConnection) {
            DB::setDefaultConnection($this->originalDefaultConnection);
        }
        parent::tearDown();
    }

    private function actingAsTenant(): int
    {
        $id = $this->makeUser();
        $this->actingAs(User::on('tenant')->find($id), 'tenant');
        Auth::shouldUse('tenant');

        return $id;
    }

    public function test_open_many_opens_all_selected_with_override_and_skips_already_open(): void
    {
        $this->cleanTenant(['shifts', 'terminals', 'branches']);
        $branchId = $this->makeBranch(['timezone' => 'Asia/Karachi']);
        $branch = Branch::on('tenant')->find($branchId);
        $userId = $this->makeUser();
        $t1 = $this->makeTerminal($branchId);
        $t2 = $this->makeTerminal($branchId);
        $t3 = $this->makeTerminal($branchId);

        $svc = app(ShiftService::class);
        $svc->open($branch, Terminal::on('tenant')->find($t1), $userId, 0.0); // t1 already open

        $result = $svc->openMany($branch, [$t1, $t2, $t3], $userId, 100.0, [$t2 => 250.0]);

        $this->assertCount(2, $result['opened'], 't2 + t3 open; t1 skipped');
        $this->assertArrayHasKey($t1, $result['skipped']);
        $this->assertEqualsWithDelta(250.0, (float) $result['opened'][$t2]->opening_cash, 0.001, 'per-terminal override applied');
        $this->assertEqualsWithDelta(100.0, (float) $result['opened'][$t3]->opening_cash, 0.001, 'shared opening cash applied');
        $this->assertSame(3, Shift::on('tenant')->where('branch_id', $branchId)->where('status', 'open')->count());
    }

    public function test_close_branch_per_terminal_records_each_variance(): void
    {
        $this->cleanTenant(['daily_closings', 'shifts', 'terminals', 'branches']);
        $branchId = $this->makeBranch(['timezone' => 'Asia/Karachi']);
        $branch = Branch::on('tenant')->find($branchId);
        $userId = $this->actingAsTenant();
        $svc = app(ShiftService::class);
        $s1 = $svc->open($branch, Terminal::on('tenant')->find($this->makeTerminal($branchId)), $userId, 0.0);
        $s2 = $svc->open($branch, Terminal::on('tenant')->find($this->makeTerminal($branchId)), $userId, 0.0);
        $s1->update(['expected_cash' => 500]);
        $s2->update(['expected_cash' => 300]);

        $resp = app()->call([app(ShiftController::class), 'closeBranch'], [
            'request' => $this->req(['branch_id' => $branchId, 'mode' => 'per_terminal', 'counted' => [$s1->id => 490, $s2->id => 300]]),
        ]);
        $this->assertContains($resp->getStatusCode(), [302, 200]);

        $this->assertSame('closed', $s1->refresh()->status);
        $this->assertEqualsWithDelta(-10.0, (float) $s1->cash_variance, 0.001, 't1 short by 10');
        $this->assertEqualsWithDelta(0.0, (float) $s2->refresh()->cash_variance, 0.001, 't2 exact');
        $this->assertSame(0, DailyClosing::on('tenant')->count(), 'per-terminal mode does not create a Daily Closing');
    }

    public function test_close_branch_total_closes_at_expected_and_records_daily_closing(): void
    {
        $this->cleanTenant(['daily_closings', 'shifts', 'terminals', 'branches']);
        $branchId = $this->makeBranch(['timezone' => 'Asia/Karachi']);
        $branch = Branch::on('tenant')->find($branchId);
        $userId = $this->actingAsTenant();
        $svc = app(ShiftService::class);
        $s1 = $svc->open($branch, Terminal::on('tenant')->find($this->makeTerminal($branchId)), $userId, 0.0);
        $s2 = $svc->open($branch, Terminal::on('tenant')->find($this->makeTerminal($branchId)), $userId, 0.0);
        $s1->update(['expected_cash' => 500, 'total_sales' => 500]);
        $s2->update(['expected_cash' => 300, 'total_sales' => 300]);

        $resp = app()->call([app(ShiftController::class), 'closeBranch'], [
            'request' => $this->req(['branch_id' => $branchId, 'mode' => 'branch_total', 'branch_counted_cash' => 780]),
        ]);
        $this->assertContains($resp->getStatusCode(), [302, 200]);

        // Each terminal closes at its expected (per-terminal variance 0)...
        $this->assertEqualsWithDelta(0.0, (float) $s1->refresh()->cash_variance, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $s2->refresh()->cash_variance, 0.001);
        $this->assertSame('closed', $s1->status);
        // ...and the branch total + variance land on a Daily Closing.
        $dc = DailyClosing::on('tenant')->where('branch_id', $branchId)->first();
        $this->assertNotNull($dc);
        $this->assertEqualsWithDelta(800.0, (float) $dc->expected_cash, 0.001);
        $this->assertEqualsWithDelta(780.0, (float) $dc->counted_cash, 0.001);
        $this->assertEqualsWithDelta(-20.0, (float) $dc->cash_variance, 0.001, 'branch short by 20');
    }

    private function req(array $body): Request
    {
        $r = Request::create('/x', 'POST', $body);
        $r->headers->set('Accept', 'application/json');

        return $r;
    }
}
