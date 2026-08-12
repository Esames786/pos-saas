<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\ShiftController;
use App\Models\Tenant\Shift;
use App\Models\Tenant\User;
use App\Services\Sales\ShiftService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\MySql\Support\TenantFixtures;

/**
 * A terminal cashier opens/closes only their OWN terminal's shift; Owner/Manager reach all.
 *
 * Enforced by the terminal binding (terminal_user pivot) already used for POS + reports — a user
 * bound to specific terminals is limited to them, an unbound user reaches any. The shortage
 * draft-expense flow is deliberately left untouched; these tests only prove the WHO/WHICH gate.
 */
class ShiftTerminalRestrictionMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'shifts', 'terminal_user', 'terminals', 'branches', 'users',
        ]);
    }

    private function actAs(int $userId): void
    {
        $this->actingAs(User::on('tenant')->find($userId), 'tenant');
        Auth::shouldUse('tenant');
    }

    private function bindTerminal(int $userId, int $terminalId): void
    {
        DB::connection('tenant')->table('terminal_user')->insert([
            'user_id' => $userId, 'terminal_id' => $terminalId,
        ]);
    }

    public function test_a_bound_cashier_cannot_close_another_terminals_shift(): void
    {
        $branchId = $this->makeBranch();
        $t1 = $this->makeTerminal($branchId, ['name' => 'Delivery']);
        $t2 = $this->makeTerminal($branchId, ['name' => 'Dine In']);
        $cashier = $this->makeUser();
        $this->bindTerminal($cashier, $t1);      // cashier assigned to Delivery only
        $this->actAs($cashier);

        // An open shift on the OTHER terminal (Dine In).
        $svc = app(ShiftService::class);
        $otherShift = $svc->open(
            \App\Models\Tenant\Branch::on('tenant')->find($branchId),
            \App\Models\Tenant\Terminal::on('tenant')->find($t2),
            $cashier, 0.0
        );

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('only open or close a shift on a terminal assigned to you');

        app(ShiftController::class)->close(
            Request::create('/shifts/' . $otherShift->id . '/close', 'POST', ['counted_cash' => '0']),
            Shift::on('tenant')->find($otherShift->id),
            $svc
        );
    }

    public function test_a_bound_cashier_can_close_their_own_terminals_shift(): void
    {
        $branchId = $this->makeBranch();
        $t1 = $this->makeTerminal($branchId, ['name' => 'Delivery']);
        $cashier = $this->makeUser();
        $this->bindTerminal($cashier, $t1);
        $this->actAs($cashier);

        $svc = app(ShiftService::class);
        $own = $svc->open(
            \App\Models\Tenant\Branch::on('tenant')->find($branchId),
            \App\Models\Tenant\Terminal::on('tenant')->find($t1),
            $cashier, 0.0
        );

        $response = app(ShiftController::class)->close(
            Request::create('/shifts/' . $own->id . '/close', 'POST', ['counted_cash' => '0']),
            Shift::on('tenant')->find($own->id),
            $svc
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('closed', Shift::on('tenant')->find($own->id)->status);
    }

    public function test_an_unbound_user_owner_can_close_any_terminals_shift(): void
    {
        $branchId = $this->makeBranch();
        $t1 = $this->makeTerminal($branchId, ['name' => 'Delivery']);
        $owner = $this->makeUser();          // NO terminal binding = unrestricted (owner/manager)
        $this->actAs($owner);

        $svc = app(ShiftService::class);
        $shift = $svc->open(
            \App\Models\Tenant\Branch::on('tenant')->find($branchId),
            \App\Models\Tenant\Terminal::on('tenant')->find($t1),
            $owner, 0.0
        );

        $response = app(ShiftController::class)->close(
            Request::create('/shifts/' . $shift->id . '/close', 'POST', ['counted_cash' => '0']),
            Shift::on('tenant')->find($shift->id),
            $svc
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('closed', Shift::on('tenant')->find($shift->id)->status);
    }

    public function test_a_bound_cashier_cannot_open_a_shift_on_another_terminal(): void
    {
        $branchId = $this->makeBranch();
        $t1 = $this->makeTerminal($branchId, ['name' => 'Delivery']);
        $t2 = $this->makeTerminal($branchId, ['name' => 'Dine In']);
        $cashier = $this->makeUser();
        $this->bindTerminal($cashier, $t1);
        $this->actAs($cashier);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('only open or close a shift on a terminal assigned to you');

        app(ShiftController::class)->store(
            Request::create('/shifts/open', 'POST', [
                'branch_id' => $branchId,
                'terminal_ids' => [$t2],       // opening on the terminal they are NOT assigned to
                'opening_cash' => '1000',
            ]),
            app(ShiftService::class)
        );
    }
}
