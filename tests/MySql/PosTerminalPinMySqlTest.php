<?php

namespace Tests\MySql;

use App\Models\Tenant\User;
use App\Services\Security\UserDataScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\MySql\Support\TenantFixtures;

/**
 * POS-TERMINAL-PIN-1 — the floor operator reads other terminals, but sells only on his own.
 *
 * The floor is bound to T2/T3/T4 so he can recall and reprint the counters' orders (deniesSale
 * keys on the bound list). Without a second rule that binding would ALSO let him switch the POS
 * onto a counter: his orders would then stamp that counter, print at its printer and land in its
 * shift. `tenant.pos.change-terminal` is what separates reading from selling.
 *
 * The pin lives in assertPosSelection — the guard every POS write path funnels through — because
 * the terminal is a posted form field and hiding the button cannot be the control.
 */
class PosTerminalPinMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;
    private int $ownTerminalId;
    private int $counterTerminalId;
    private User $floor;
    private User $counter;
    private UserDataScope $scope;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'role_has_permissions', 'model_has_roles', 'model_has_permissions', 'permissions', 'roles',
            'terminal_user', 'branch_user', 'terminals', 'branches', 'users',
        ]);
        DB::connection('tenant')->table('cache')->where('key', 'like', '%spatie.permission.cache%')->delete();
        app()->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->scope = app(UserDataScope::class);
        $this->branchId = $this->makeBranch();
        $this->ownTerminalId = $this->makeTerminal($this->branchId, ['code' => 'T4', 'name' => 'DTQ Floor']);
        $this->counterTerminalId = $this->makeTerminal($this->branchId, ['code' => 'T2', 'name' => 'DTQ 1']);

        $changePerm = Permission::on('tenant')->create(['name' => UserDataScope::CHANGE_TERMINAL_PERMISSION, 'guard_name' => 'tenant']);
        $counterRole = Role::on('tenant')->create(['name' => 'Dine In', 'guard_name' => 'tenant']);
        $floorRole = Role::on('tenant')->create(['name' => 'Dine In (Restricted)', 'guard_name' => 'tenant']);
        $counterRole->givePermissionTo($changePerm);   // the counters keep the reach they have today

        // Both are bound to BOTH terminals — that is what makes the pin the only difference.
        $this->floor = User::on('tenant')->findOrFail($this->makeUser([
            'email' => 'floor@example.test', 'employee_code' => 'FL' . Str::random(4),
            'default_branch_id' => $this->branchId, 'default_terminal_id' => $this->ownTerminalId,
        ]));
        $this->floor->terminals()->sync([$this->ownTerminalId, $this->counterTerminalId]);
        $this->floor->assignRole($floorRole);

        $this->counter = User::on('tenant')->findOrFail($this->makeUser([
            'email' => 'counter@example.test', 'employee_code' => 'CT' . Str::random(4),
            'default_branch_id' => $this->branchId, 'default_terminal_id' => $this->counterTerminalId,
        ]));
        $this->counter->terminals()->sync([$this->ownTerminalId, $this->counterTerminalId]);
        $this->counter->assignRole($counterRole);
    }

    private function statusFor(User $user, int $terminalId): int
    {
        try {
            $this->scope->assertPosSelection($user, $this->branchId, $terminalId);

            return 200;
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return $e->getStatusCode();
        }
    }

    /** The floor still sells on his own terminal — the pin must not lock him out of his own POS. */
    public function test_pinned_operator_may_use_his_own_terminal(): void
    {
        $this->assertSame(200, $this->statusFor($this->floor, $this->ownTerminalId));
    }

    /** …but may not switch onto the counter, even though he is BOUND to it for recall/reprint. */
    public function test_pinned_operator_cannot_switch_to_another_bound_terminal(): void
    {
        $this->assertSame(403, $this->statusFor($this->floor, $this->counterTerminalId),
            'A bound terminal must still be refused for SELLING when the operator is pinned.');
    }

    /** Binding is unchanged: the floor is bound to the counter, which is what recall/reprint reads. */
    public function test_the_pin_does_not_remove_the_binding_recall_depends_on(): void
    {
        $this->assertEqualsCanonicalizing(
            [$this->ownTerminalId, $this->counterTerminalId],
            $this->scope->terminalIds($this->floor),
            'The floor must stay BOUND to the counter — that is what lets him recall and reprint its orders.'
        );

        $counterSale = (object) ['branch_id' => $this->branchId, 'terminal_id' => $this->counterTerminalId, 'order_type' => 'dine_in'];
        $this->assertFalse($this->scope->deniesSale($this->floor, $counterSale),
            'Recall/reprint of the counter\'s order must still be allowed.');
    }

    /** A holder of the permission is untouched — this is how every existing tenant behaves. */
    public function test_operator_with_the_permission_may_still_switch(): void
    {
        $this->assertSame(200, $this->statusFor($this->counter, $this->ownTerminalId));
        $this->assertSame(200, $this->statusFor($this->counter, $this->counterTerminalId));
    }

    /** No default terminal = nothing to pin to; such a user must not be locked out. */
    public function test_operator_without_a_default_terminal_is_not_pinned(): void
    {
        $roaming = User::on('tenant')->findOrFail($this->makeUser([
            'email' => 'roam@example.test', 'employee_code' => 'RM' . Str::random(4),
            'default_branch_id' => $this->branchId, 'default_terminal_id' => null,
        ]));
        $roaming->terminals()->sync([$this->ownTerminalId, $this->counterTerminalId]);

        $this->assertSame(200, $this->statusFor($roaming, $this->ownTerminalId));
        $this->assertSame(200, $this->statusFor($roaming, $this->counterTerminalId));
    }

    /** The POS picker offers a pinned operator only his own terminal (autoSelectTerminal reads it). */
    public function test_pos_terminal_picker_offers_only_the_pinned_terminal(): void
    {
        $all = $this->scope->terminalsForPos($this->floor, [$this->branchId]);
        $this->assertCount(2, $all, 'terminalsForPos itself still reflects the binding.');

        // The controller narrows it for a pinned operator; mirror that rule here.
        $offered = $all->when(
            ! $this->floor->can(UserDataScope::CHANGE_TERMINAL_PERMISSION) && $this->floor->default_terminal_id,
            fn ($list) => $list->where('id', (int) $this->floor->default_terminal_id)->values()
        );
        $this->assertCount(1, $offered);
        $this->assertSame($this->ownTerminalId, (int) $offered->first()->id);
    }
}
