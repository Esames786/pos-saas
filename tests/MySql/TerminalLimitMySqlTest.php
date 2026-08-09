<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\TerminalController;
use App\Models\Tenant\Terminal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * TERMINAL LIMIT (Khatri go-live §21) — terminal_limit caps ACTIVE terminals; "2 active" is only the
 * initial state. Activation up to the cap (4) succeeds through the EDIT path; activation beyond the
 * cap is refused on BOTH store and update (the historical update() bypass is closed).
 */
class TerminalLimitMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant(['shifts', 'terminals', 'branches', 'users']);
        DB::connection('master')->table('plan_features')->delete();
        DB::connection('master')->table('subscriptions')->delete();
        DB::connection('master')->table('plans')->where('code', 'limit_test')->delete();
        DB::connection('master')->table('tenants')->where('tenant_code', 'limittest')->delete();

        $planId = DB::connection('master')->table('plans')->insertGetId([
            'code' => 'limit_test', 'name' => 'Limit Test', 'price' => 0, 'currency_code' => 'PKR',
            'billing_period' => 'monthly', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('master')->table('plan_features')->insert([
            'plan_id' => $planId, 'feature_key' => 'terminal_limit', 'feature_value' => '4', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $tenantId = DB::connection('master')->table('tenants')->insertGetId([
            'tenant_code' => 'limittest', 'business_name' => 'Limit Test', 'currency_code' => 'PKR',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('master')->table('subscriptions')->insert([
            'tenant_id' => $tenantId, 'plan_id' => $planId, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        app()->instance('tenant', \App\Models\Master\Tenant::find($tenantId));

        $this->branchId = $this->makeBranch();
    }

    private function updateTerminal(Terminal $terminal, string $status)
    {
        $request = Request::create('/terminals/' . $terminal->id, 'PUT', [
            'branch_id' => $this->branchId, 'code' => $terminal->code, 'name' => $terminal->name, 'status' => $status,
        ]);
        $request->setLaravelSession(app('session.store'));

        return app(TerminalController::class)->update($request, $terminal);
    }

    public function test_activation_up_to_the_cap_succeeds_and_beyond_is_refused_on_update(): void
    {
        // the Khatri contract state: 4 rows, 2 active.
        $ids = [];
        foreach ([['T1', 'active'], ['T2', 'active'], ['T3', 'inactive'], ['T4', 'inactive']] as [$code, $status]) {
            $ids[$code] = $this->makeTerminal($this->branchId, ['code' => $code, 'status' => $status]);
        }
        // a 5th legacy/inactive row that must never be activatable while 4 are active.
        $ids['T5'] = $this->makeTerminal($this->branchId, ['code' => 'T5', 'status' => 'inactive']);

        // activating T3 and T4 (up to the cap of 4 ACTIVE) succeeds via the EDIT path.
        $this->updateTerminal(Terminal::findOrFail($ids['T3']), 'active');
        $this->assertSame('active', Terminal::find($ids['T3'])->status, 'activation within the cap must succeed');
        $this->updateTerminal(Terminal::findOrFail($ids['T4']), 'active');
        $this->assertSame('active', Terminal::find($ids['T4'])->status);
        $this->assertSame(4, Terminal::where('status', 'active')->count());

        // the FIFTH activation is refused — the update() bypass is closed.
        $this->updateTerminal(Terminal::findOrFail($ids['T5']), 'active');
        $this->assertSame('inactive', Terminal::find($ids['T5'])->status, 'activation beyond terminal_limit must be refused on update');

        // deactivating frees a slot; re-activation then succeeds (flexible 4-active pool).
        $this->updateTerminal(Terminal::findOrFail($ids['T4']), 'inactive');
        $this->assertSame('inactive', Terminal::find($ids['T4'])->status, 'deactivation is never blocked');
        $this->updateTerminal(Terminal::findOrFail($ids['T5']), 'active');
        $this->assertSame('active', Terminal::find($ids['T5'])->status, 'a freed slot is reusable by another terminal');

        // non-status edits on an already-ACTIVE terminal are never limit-blocked.
        $t1 = Terminal::findOrFail($ids['T1']);
        $request = Request::create('/terminals/' . $t1->id, 'PUT', [
            'branch_id' => $this->branchId, 'code' => 'T1', 'name' => 'Counter One Renamed', 'status' => 'active',
        ]);
        $request->setLaravelSession(app('session.store'));
        app(TerminalController::class)->update($request, $t1);
        $this->assertSame('Counter One Renamed', Terminal::find($ids['T1'])->name, 'renaming an active terminal is unaffected');
    }
}
