<?php

namespace Tests\MySql;

use App\Models\Tenant\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySql\Support\EdgeLocalRuntimeFixture;
use Tests\MySql\Support\TenantFixtures;

/**
 * W4 (Team 4) — SHIFT PARITY over the REAL branch_server routes (audit R1.2–R1.9; Online Tenant\ShiftController +
 * tenant/shifts/{open,close,index,show}):
 *  - R1.2 open: Opening Cash REQUIRED (numeric ≥ 0) + Opening Notes persisted, terminal named in the answer;
 *  - R1.1 badge: GET /shift keeps its shape and ADDS the Online posStatus keys (backward compatible);
 *  - R1.3 close: the Online denomination count (Σ qty × face value wins over the typed total, CashCountLine rows),
 *    closing notes, a refused close leaves NO orphan count line; no CASH-SHORTAGE voucher is created on the appliance;
 *  - R1.4 blind count: the close answer carries NO figure for an operator the branch hides amounts from;
 *  - R1.5 zero drawer, R1.6 operating business date (executable);
 *  - R1.8/R1.9 the Edge shift history + detail screens: Online route permissions, bound branch only, figures masked.
 */
class EdgeCashierShiftParityHttpMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;
    use EdgeLocalRuntimeFixture;

    private int $branchId;
    private int $otherBranchId;
    private int $terminalA;
    private int $terminalB;
    private int $userId;
    private int $productId;
    private int $cashMethodId;
    /** @var array<int,int> face value => denomination id */
    private array $denoms = [];

    protected function setUp(): void
    {
        putenv('APP_ROLE=branch_server');
        $_ENV['APP_ROLE'] = $_SERVER['APP_ROLE'] = 'branch_server';
        $key = 'base64:' . base64_encode(random_bytes(32));
        putenv("EDGE_LOCAL_APP_KEY={$key}");
        $_ENV['EDGE_LOCAL_APP_KEY'] = $_SERVER['EDGE_LOCAL_APP_KEY'] = $key;
        parent::setUp();
        config(['database.connections.edge_local' => array_merge(config('database.connections.edge_local', []), [
            'host' => config('database.connections.tenant.host'), 'port' => config('database.connections.tenant.port'),
            'database' => $this->tenantDb, 'username' => config('database.connections.tenant.username'), 'password' => config('database.connections.tenant.password'),
        ])]);
        DB::purge('edge_local');
        DB::setDefaultConnection('tenant');
        $this->ensureEdgeSchema();
        $this->cleanTenant([
            'edge_sync_outbox', 'edge_operational_stock_movements', 'edge_operational_stock_balances', 'edge_operational_stock_baselines',
            'edge_auth_audit', 'edge_local_user_credentials', 'edge_local_meta', 'model_has_permissions', 'permissions',
            'cash_count_lines', 'currency_denominations', 'currencies', 'expense_vouchers',
            'sales_order_line_cancellations', 'sales_ledgers', 'cash_bank_account_transactions', 'journal_lines', 'journal_entries',
            'stock_ledgers', 'stock_balances', 'sale_payments', 'sales_order_lines', 'sales_orders',
            'payment_methods', 'products', 'categories', 'shifts', 'terminals', 'branches', 'users',
        ]);
        $this->branchId = $this->makeBranch(['name' => 'Shift Branch', 'allow_negative_stock' => 0, 'timezone' => 'Asia/Karachi']);
        $this->otherBranchId = $this->makeBranch(['name' => 'Other Branch', 'timezone' => 'Asia/Karachi']);
        $this->userId = $this->makeUser(['name' => 'Cashier Asad', 'default_branch_id' => $this->branchId, 'employee_code' => 'SHP' . Str::random(4)]);
        $this->terminalA = $this->makeTerminal($this->branchId, ['name' => 'Counter A']);
        $this->terminalB = $this->makeTerminal($this->branchId, ['name' => 'Floor (no drawer)']);
        $this->productId = $this->makeProduct($this->makeCategory(), ['inventory_consumption_method' => 'stock_item', 'is_stock_tracked' => 1, 'is_sellable' => 1, 'is_pos_visible' => 1, 'status' => 'active', 'default_selling_price' => 100]);
        $this->cashMethodId = $this->makePaymentMethod(['method_type' => 'cash']);
        // The Online default currency + denominations (the close form's count grid).
        $currencyId = (int) DB::table('currencies')->insertGetId(['code' => 'PKR', 'name' => 'Rupee', 'symbol' => 'Rs', 'is_default' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([[500, 'note'], [100, 'note'], [10, 'coin']] as [$v, $t]) {
            $this->denoms[$v] = (int) DB::table('currency_denominations')->insertGetId(['currency_id' => $currencyId, 'denomination_value' => $v, 'denomination_type' => $t, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->bindEdgeLocalMeta($this->branchId, 1);
        $this->acceptTestBaseline([['product_id' => $this->productId, 'product_variant_id' => null, 'quantity' => 50]]);
        $this->seedEdgeCredential($this->userId, $this->branchId, 1);
        foreach (['tenant.shifts.index', 'tenant.shifts.show'] as $p) {
            $this->grantEdgePermission($this->userId, $p);
        }
        $this->login();
        $this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $this->terminalA])->assertOk();
    }

    protected function tearDown(): void
    {
        putenv('APP_ROLE');
        unset($_ENV['APP_ROLE'], $_SERVER['APP_ROLE']);
        putenv('EDGE_LOCAL_APP_KEY');
        unset($_ENV['EDGE_LOCAL_APP_KEY'], $_SERVER['EDGE_LOCAL_APP_KEY']);
        $this->resetRuntimeRole();
        parent::tearDown();
    }

    private function login(): void
    {
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs(User::on('tenant')->find($this->userId), 'tenant');
        Auth::shouldUse('tenant');
    }

    private function cashSale(float $qty = 1): void
    {
        $this->postJson('/edge/local/pos/sales', [
            'order_type' => 'takeaway', 'client_uuid' => (string) Str::uuid(),
            'lines' => [['product_id' => $this->productId, 'quantity' => $qty]],
            'payments' => [['payment_method_id' => $this->cashMethodId, 'amount' => 100 * $qty, 'tendered_amount' => 100 * $qty]],
        ])->assertStatus(201);
    }

    private function openShiftId(): int
    {
        return (int) DB::table('shifts')->where('terminal_id', $this->terminalA)->where('status', 'open')->value('id');
    }

    public function test_open_shift_requires_opening_cash_and_keeps_the_opening_notes_and_the_badge_stays_backward_compatible(): void
    {
        // R1.2: Online `opening_cash` is REQUIRED (numeric ≥ 0) — a blank box is refused, never defaulted to 0.
        $this->postJson('/edge/local/pos/shift/open', [])->assertStatus(422)->assertJsonValidationErrors('opening_cash');
        $this->postJson('/edge/local/pos/shift/open', ['opening_cash' => -1])->assertStatus(422);
        $this->assertSame(0, DB::table('shifts')->count());

        $open = $this->postJson('/edge/local/pos/shift/open', ['opening_cash' => 500, 'opening_notes' => 'float from safe'])->assertStatus(201);
        $this->assertSame('Counter A', $open->json('terminal_name'));
        $this->assertSame('float from safe', DB::table('shifts')->where('id', $open->json('shift_id'))->value('opening_notes'));

        // R1.1 badge: every pre-existing key stays; the Online posStatus keys are added.
        $s = $this->getJson('/edge/local/pos/shift')->assertOk();
        foreach (['terminal_id', 'may_see_amounts', 'shift.id', 'shift.shift_uuid', 'shift.business_date', 'shift.opened_at', 'shift.total_sales', 'shift.expected_cash',
            'terminal_name', 'has_terminal', 'open', 'business_date', 'timezone', 'opened_at_display', 'server_epoch_ms'] as $k) {
            $this->assertTrue($s->json($k) !== null || array_key_exists(explode('.', $k)[0], $s->json()), "GET /shift must carry {$k}");
        }
        $this->assertTrue($s->json('open'));
        $this->assertSame('Counter A', $s->json('terminal_name'));
        $this->assertSame(500.0, (float) $s->json('shift.expected_cash'));

        // The summary exposes the denomination book (highest first), opening notes and the Online permissions.
        $sum = $this->getJson('/edge/local/pos/shift/summary')->assertOk();
        $this->assertSame([500.0, 100.0, 10.0], array_map('floatval', array_column($sum->json('currency.denominations'), 'value')));
        $this->assertSame('float from safe', $sum->json('shift.opening_notes'));
        $this->assertTrue($sum->json('permissions.can_close'));
        $this->assertTrue($sum->json('permissions.can_view_history'));
    }

    public function test_close_with_denominations_records_the_count_lines_and_the_notes_and_raises_no_voucher(): void
    {
        $this->postJson('/edge/local/pos/shift/open', ['opening_cash' => 500])->assertStatus(201);
        $this->cashSale(2); // expected = 500 + 200 = 700
        $shiftId = $this->openShiftId();

        // A refused close (drawer holding cash, no count) leaves NO count lines and the shift open.
        $this->postJson('/edge/local/pos/shift/close', ['denominations' => [$this->denoms[500] => 0]])->assertStatus(422);
        $this->assertSame(0, DB::table('cash_count_lines')->count());
        $this->assertSame('open', DB::table('shifts')->where('id', $shiftId)->value('status'));

        // Denominations WIN over a typed total (Online calculateCashCount): 1×500 + 1×100 + 8×10 = 680 → variance −20.
        $r = $this->postJson('/edge/local/pos/shift/close', [
            'counted_cash' => 9999,
            'denominations' => [$this->denoms[500] => 1, $this->denoms[100] => 1, $this->denoms[10] => 8],
            'closing_notes' => 'two coins missing',
        ])->assertOk();
        $this->assertSame('closed', $r->json('status'));
        $this->assertSame('denominations', $r->json('count_source'));
        $this->assertSame(680.0, (float) $r->json('counted_cash'));
        $this->assertSame(-20.0, (float) $r->json('cash_variance'));
        $this->assertFalse($r->json('shortage_voucher.raised'));
        $this->assertStringContainsString('Cash short by 20.00', (string) $r->json('shortage_voucher.message'));
        $row = DB::table('shifts')->where('id', $shiftId)->first();
        $this->assertSame('two coins missing', $row->closing_notes);
        $this->assertSame(680.0, (float) $row->counted_cash);
        $lines = DB::table('cash_count_lines')->where('source_type', 'shift')->where('source_id', $shiftId)->orderByDesc('amount')->get();
        $this->assertSame([500.0, 100.0, 80.0], $lines->pluck('amount')->map(fn ($v) => (float) $v)->all());
        $this->assertSame([1, 1, 8], $lines->pluck('quantity')->map(fn ($v) => (int) $v)->all());
        // CONTRACT BOUNDARY: no finance entry is created by the appliance (no expense voucher, no journal).
        $this->assertSame(0, DB::table('expense_vouchers')->count(), 'CASH-SHORTAGE-1 voucher is a Cloud posting — never created offline');
        $this->assertSame(0, DB::table('journal_entries')->count());
        $this->assertStringEndsWith('/edge/local/pos/shifts/' . $shiftId, (string) $r->json('detail_url'));
    }

    public function test_blind_count_close_carries_no_figure_and_zero_drawer_and_operating_date_are_executable(): void
    {
        $this->postJson('/edge/local/pos/shift/open', ['opening_cash' => 500])->assertStatus(201);
        $this->cashSale(1);
        $shiftDate = (string) DB::table('shifts')->where('id', $this->openShiftId())->value('business_date');
        // R1.6: the operating business date is the OPEN shift's business_date.
        $this->assertSame($shiftDate, $this->getJson('/edge/local/pos/shift/summary')->assertOk()->json('operating_business_date'));

        DB::table('branches')->where('id', $this->branchId)->update(['hide_amounts_from_operators' => 1]);
        $sum = $this->getJson('/edge/local/pos/shift/summary')->assertOk();
        $this->assertFalse($sum->json('may_see_amounts'));
        $this->assertNull($sum->json('breakup.expected_cash'));

        // R1.4: the blind count close answers WITHOUT the expected / counted / variance figures.
        $r = $this->postJson('/edge/local/pos/shift/close', ['counted_cash' => 550, 'closing_notes' => 'blind'])->assertOk();
        $this->assertFalse($r->json('may_see_amounts'));
        foreach (['expected_cash', 'counted_cash', 'cash_variance', 'shortage_voucher.message'] as $k) {
            $this->assertNull($r->json($k), "{$k} must be stripped for a blind count");
        }
        $this->assertStringNotContainsString('600', $r->getContent());
        $this->assertSame(-50.0, (float) DB::table('shifts')->where('terminal_id', $this->terminalA)->value('cash_variance'), 'the variance is still recorded on the shift');

        // R1.5 zero drawer: the floor terminal's empty drawer closes without a count.
        $this->postJson('/edge/local/pos/terminal/select', ['terminal_id' => $this->terminalB])->assertOk();
        $this->postJson('/edge/local/pos/shift/open', ['opening_cash' => 0])->assertStatus(201);
        $this->assertTrue($this->getJson('/edge/local/pos/shift/summary')->assertOk()->json('shift.zero_drawer'));
        $this->postJson('/edge/local/pos/shift/close', [])->assertOk()->assertJsonPath('status', 'closed')->assertJsonPath('count_source', 'zero_drawer');
    }

    public function test_shift_history_and_detail_screens_follow_the_online_permissions_branch_and_masking(): void
    {
        $this->postJson('/edge/local/pos/shift/open', ['opening_cash' => 500, 'opening_notes' => 'morning'])->assertStatus(201);
        $this->cashSale(1);
        $shiftId = $this->openShiftId();
        $this->postJson('/edge/local/pos/shift/close', ['counted_cash' => 590, 'closing_notes' => 'evening close'])->assertOk();
        // A shift of ANOTHER branch in the same database is never listed nor opened here.
        $foreign = (int) DB::table('shifts')->insertGetId(['branch_id' => $this->otherBranchId, 'terminal_id' => $this->makeTerminal($this->otherBranchId), 'opened_by_user_id' => $this->userId,
            'opening_cash' => 1, 'expected_cash' => 1, 'status' => 'open', 'opened_at' => now(), 'business_date' => now()->toDateString(), 'shift_uuid' => (string) Str::ulid(), 'created_at' => now(), 'updated_at' => now()]);

        $list = $this->get('/edge/local/pos/shifts')->assertOk()->getContent();
        foreach (['id="shift-history-table"', 'id="status-filter"', 'id="date-from"', 'id="date-to"', 'id="shift-filter-today"', 'id="close-branch-note"', 'Counter A', 'Cash detail', '590.00', 'id="shift-view-' . $shiftId . '"'] as $needle) {
            $this->assertStringContainsString($needle, $list, "the shift history must carry {$needle}");
        }
        $this->assertStringNotContainsString('id="shift-view-' . $foreign . '"', $list);
        $this->assertStringContainsString('id="shift-view-' . $shiftId . '"', $this->get('/edge/local/pos/shifts?status=closed')->assertOk()->getContent());
        $this->assertStringNotContainsString('id="shift-view-' . $shiftId . '"', $this->get('/edge/local/pos/shifts?status=open')->assertOk()->getContent());

        $detail = $this->get('/edge/local/pos/shifts/' . $shiftId)->assertOk()->getContent();
        foreach (['Shift Summary', 'Cash Summary', 'morning', 'evening close', '600.00', '590.00', '-10.00', 'id="shift-shortage-note"'] as $needle) {
            $this->assertStringContainsString($needle, $detail, "the shift detail must carry {$needle}");
        }
        $this->get('/edge/local/pos/shifts/' . $foreign)->assertNotFound();

        // HIDE-AMOUNTS-2: the same screens mask every figure for a blind-count operator.
        DB::table('branches')->where('id', $this->branchId)->update(['hide_amounts_from_operators' => 1]);
        $masked = $this->get('/edge/local/pos/shifts/' . $shiftId)->assertOk()->getContent();
        $this->assertStringContainsString('*****', $masked);
        $this->assertStringNotContainsString('590.00', $masked);
        $this->assertStringNotContainsString('600.00', $masked);
        $this->assertStringNotContainsString('590.00', $this->get('/edge/local/pos/shifts')->assertOk()->getContent());

        // Online route permissions: tenant.shifts.index / tenant.shifts.show.
        $revoke = function (string $p) {
            $id = (int) DB::table('permissions')->where('name', $p)->value('id');
            DB::table('model_has_permissions')->where('model_id', $this->userId)->where('permission_id', $id)->delete();
            $this->login();
        };
        $revoke('tenant.shifts.index');
        $this->get('/edge/local/pos/shifts')->assertForbidden();
        $revoke('tenant.shifts.show');
        $this->get('/edge/local/pos/shifts/' . $shiftId)->assertForbidden();
    }
}
