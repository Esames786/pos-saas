<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\ShiftController;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\User;
use App\Services\Sales\ShiftService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\TenantFixtures;

/**
 * USER-DATA-SCOPE-1 (shift screens) — a terminal-bound cashier may only OPEN his own terminals and,
 * on Close Branch, only see the open shifts he can actually close (so the "Close N shifts" count is
 * honest). An unbound Owner/Manager still sees every terminal. The close ACTION was already scoped;
 * this brings the two screens in line with it.
 */
class ShiftTerminalScopeMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;

    private int $terminalA;

    private int $terminalB;

    private User $boundUser;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant(['shifts', 'sales_orders', 'terminal_user', 'branch_user', 'terminals', 'branches', 'users']);

        $this->branchId = $this->makeBranch(['timezone' => 'Asia/Karachi']);
        $this->terminalA = $this->makeTerminal($this->branchId, ['code' => 'T-A', 'name' => 'Takeaway']);
        $this->terminalB = $this->makeTerminal($this->branchId, ['code' => 'T-B', 'name' => 'Delivery']);

        $this->boundUser = User::on('tenant')->findOrFail($this->makeUser([
            'email' => 'takeaway@example.test', 'employee_code' => 'TA'.Str::random(4), 'default_branch_id' => $this->branchId,
        ]));
        $this->boundUser->terminals()->sync([$this->terminalA]); // bound to Takeaway only

        $this->owner = User::on('tenant')->findOrFail($this->makeUser([
            'email' => 'owner@example.test', 'employee_code' => 'OW'.Str::random(4), 'default_branch_id' => $this->branchId,
        ]));

        // Open a shift on BOTH terminals.
        $svc = app(ShiftService::class);
        $svc->open(Branch::on('tenant')->find($this->branchId), Terminal::on('tenant')->find($this->terminalA), $this->boundUser->id, 0.0);
        $svc->open(Branch::on('tenant')->find($this->branchId), Terminal::on('tenant')->find($this->terminalB), $this->owner->id, 0.0);
    }

    public function test_open_shift_lists_only_the_bound_users_terminals(): void
    {
        $this->actingAs($this->boundUser, 'tenant');
        Auth::shouldUse('tenant');
        $terminals = app(ShiftController::class)->create()->getData()['terminals'];
        $this->assertSame([$this->terminalA], $terminals->pluck('id')->map(fn ($id) => (int) $id)->all(), 'a bound cashier opens only his terminal');

        $this->actingAs($this->owner, 'tenant');
        Auth::shouldUse('tenant');
        $ownerTerminals = app(ShiftController::class)->create()->getData()['terminals'];
        $this->assertEqualsCanonicalizing([$this->terminalA, $this->terminalB], $ownerTerminals->pluck('id')->map(fn ($id) => (int) $id)->all(), 'the owner sees every terminal');
    }

    public function test_close_branch_shows_only_the_shifts_the_user_can_close(): void
    {
        $this->actingAs($this->boundUser, 'tenant');
        Auth::shouldUse('tenant');
        $shifts = app(ShiftController::class)->closeBranchForm(Request::create('/shifts-close-branch', 'GET', ['branch_id' => $this->branchId]))->getData()['openShifts'];
        $this->assertSame([$this->terminalA], $shifts->pluck('terminal_id')->map(fn ($id) => (int) $id)->all(), 'only his own open shift is listed (count is honest)');

        $this->actingAs($this->owner, 'tenant');
        Auth::shouldUse('tenant');
        $ownerShifts = app(ShiftController::class)->closeBranchForm(Request::create('/shifts-close-branch', 'GET', ['branch_id' => $this->branchId]))->getData()['openShifts'];
        $this->assertCount(2, $ownerShifts, 'the owner sees both open shifts');
    }
}
