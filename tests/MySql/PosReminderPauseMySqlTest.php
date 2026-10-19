<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\PrintJobController;
use App\Http\Controllers\Tenant\ShiftController;
use App\Models\Tenant\Branch;
use App\Models\Tenant\PrintJob;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\Shift;
use App\Models\Tenant\Terminal;
use App\Models\Tenant\User;
use App\Services\Printing\DirectPayPrintOrchestrator;
use App\Services\Printing\PrintJobService;
use App\Services\Printing\ReminderPauseService;
use App\Services\Sales\ShiftService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\MySql\Support\TenantFixtures;

/**
 * POS-REMINDER-PAUSE-1 — a counter pauses its Reminder slips until its shift closes.
 *
 * Khatri's Dine In counter prints ~3 Reminder slips per order. The switch lives on the POS; the
 * pause is a stamp on the terminal's OPEN shift, so the next shift starts with Reminder ON.
 * Driven through the real controllers and services the POS calls.
 */
class PosReminderPauseMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private int $branchId;
    private int $dineIn;       // T4 — the counter that pauses
    private int $takeaway;     // T1 — has its own Reminder rule, must be unaffected
    private int $bare;         // T9 — no Reminder rule at all
    private int $dinePrinter;
    private int $takePrinter;
    private int $cat;
    private int $cashierId;    // may pause
    private int $waiterId;     // may not

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'print_jobs', 'kot_batch_lines', 'kot_batches', 'sales_order_line_cancellations',
            'void_reasons', 'sales_order_lines', 'sales_orders', 'category_printer_mappings',
            'terminal_printer_settings', 'printers', 'products', 'categories', 'shifts',
            'terminals', 'branches', 'users',
        ]);

        $this->branchId = $this->makeBranch();
        $this->cat = $this->makeCategory(['name' => 'Biryani', 'slug' => 'biryani']);
        $this->dineIn = $this->makeTerminal($this->branchId, ['code' => 'T4', 'name' => '4-Dine In']);
        $this->takeaway = $this->makeTerminal($this->branchId, ['code' => 'T1', 'name' => '1-Takeaway']);
        $this->bare = $this->makeTerminal($this->branchId, ['code' => 'T9', 'name' => '9-Spare']);
        $this->dinePrinter = $this->makePrinter(['code' => 'P-DINE', 'print_role' => 'both', 'branch_id' => $this->branchId, 'supports_reminder' => 1]);
        $this->takePrinter = $this->makePrinter(['code' => 'P-TAKE', 'print_role' => 'both', 'branch_id' => $this->branchId, 'supports_reminder' => 1]);

        foreach ([[$this->dineIn, $this->dinePrinter], [$this->takeaway, $this->takePrinter]] as [$term, $printer]) {
            foreach (['kot', 'reminder'] as $role) {
                DB::table('category_printer_mappings')->insert([
                    'branch_id' => $this->branchId, 'terminal_id' => $term, 'category_id' => $role === 'kot' ? $this->cat : null,
                    'printer_id' => $printer, 'print_role' => $role, 'order_type' => 'all', 'is_active' => 1,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        // T9 prints KOT but has no Reminder rule.
        DB::table('category_printer_mappings')->insert([
            'branch_id' => $this->branchId, 'terminal_id' => $this->bare, 'category_id' => $this->cat,
            'printer_id' => $this->takePrinter, 'print_role' => 'kot', 'order_type' => 'all', 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->cashierId = $this->makeUser(['name' => 'Shahzad']);
        $this->waiterId = $this->makeUser(['name' => 'No Permission']);
        Permission::findOrCreate(ReminderPauseService::PERMISSION, 'tenant');
        User::on('tenant')->find($this->cashierId)->givePermissionTo(ReminderPauseService::PERMISSION);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([$this->dineIn, $this->takeaway, $this->bare] as $term) {
            $this->openShift($term);
        }
    }

    private function openShift(int $terminalId): int
    {
        return DB::table('shifts')->insertGetId([
            'shift_uuid' => (string) Str::ulid(), 'branch_id' => $this->branchId, 'terminal_id' => $terminalId,
            'opened_by_user_id' => $this->cashierId, 'opening_cash' => 0, 'status' => 'open',
            'business_date' => now()->toDateString(), 'timezone_name' => 'Asia/Karachi', 'opened_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function signIn(int $userId): void
    {
        $this->actingAs(User::on('tenant')->find($userId), 'tenant');
        Auth::shouldUse('tenant');
    }

    /** POST /api/pos/reminder-pause, through the controller. */
    private function toggle(int $terminalId, bool $paused, ?int $userId = null)
    {
        $this->signIn($userId ?? $this->cashierId);

        return app(ShiftController::class)->posReminderPause(
            Request::create('/api/pos/reminder-pause', 'POST', ['terminal_id' => $terminalId, 'paused' => $paused]),
            app(ReminderPauseService::class)
        );
    }

    /** GET /api/pos/shift-status → its `reminder` block. */
    private function reminderStatus(int $terminalId, ?int $userId = null): array
    {
        $this->signIn($userId ?? $this->cashierId);

        return app(ShiftController::class)->posStatus(
            Request::create('/api/pos/shift-status', 'GET', ['terminal_id' => $terminalId]),
            app(ShiftService::class)
        )->getData(true)['reminder'];
    }

    /** A held order at a terminal with one unsent line. */
    private function order(int $terminalId, string $orderType = 'dine_in', string $status = 'held'): SalesOrder
    {
        $product = $this->makeProduct($this->cat, ['name' => 'Beef Khatri Biryani ' . uniqid()]);
        $saleId = $this->makeSale($this->branchId, ['status' => $status, 'order_type' => $orderType, 'terminal_id' => $terminalId]);
        $this->makeSaleLine($saleId, $product, ['product_name' => 'Beef Khatri Biryani', 'quantity' => 1, 'kot_sent_quantity' => 0]);

        return SalesOrder::on('tenant')->with('lines')->findOrFail($saleId);
    }

    /** Add an unsent line — the next KOT round. */
    private function addLine(SalesOrder $sale): void
    {
        $product = $this->makeProduct($this->cat, ['name' => 'Raita ' . uniqid()]);
        $this->makeSaleLine($sale->id, $product, ['product_name' => 'Raita', 'quantity' => 1, 'kot_sent_quantity' => 0]);
    }

    /** What the POS does on Hold: POST /printing/jobs/kot/{sale}, through the controller. */
    private function sendKot(SalesOrder $sale): array
    {
        $this->signIn($this->cashierId);
        $request = Request::create('/printing/jobs/kot/' . $sale->id, 'POST', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $data = app(PrintJobController::class)->queueKot($request, $sale->fresh('lines'))->getData(true);

        // The print agent's transport success — this is what books the lines as sent.
        foreach ($data['jobs'] as $job) {
            app(PrintJobService::class)->markPrinted(PrintJob::find($job['job_id']));
        }

        return $data;
    }

    private function reminders(SalesOrder $sale): \Illuminate\Support\Collection
    {
        return PrintJob::where('reference_type', 'sales_order')->where('reference_id', $sale->id)
            ->where('document_type', 'reminder')->orderBy('id')->get();
    }

    private function cancelWholeOrder(SalesOrder $sale, int $terminalId): array
    {
        $this->signIn($this->cashierId);
        $sale = $sale->fresh('lines');
        $quantities = $sale->lines->mapWithKeys(fn ($l) => [(string) $l->id => (float) $l->quantity])->all();
        $service = app(PrintJobService::class);
        $queued = $service->queueCancellationKot($sale, $quantities, (string) $terminalId);

        return $service->queueCancellationReminders($sale, $queued['batch'], true, (string) $terminalId);
    }

    // ── the guards ──────────────────────────────────────────────────────────────────

    public function test_a_paused_terminal_prints_the_kot_but_no_reminder(): void
    {
        $res = $this->toggle($this->dineIn, true);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertTrue($res->getData(true)['reminder']['paused']);
        $this->assertSame('Shahzad', $res->getData(true)['reminder']['paused_by'], 'who paused it is on record');

        $sale = $this->order($this->dineIn);
        $data = $this->sendKot($sale);

        $this->assertNotEmpty($data['jobs'], 'the KOT still goes to the kitchen');
        $this->assertSame([], $data['reminder']['auto_jobs']);
        $this->assertCount(0, $this->reminders($sale), 'no Reminder slip while paused');
    }

    public function test_another_terminal_keeps_its_reminders(): void
    {
        $this->toggle($this->dineIn, true);

        $sale = $this->order($this->takeaway, 'takeaway');
        $this->sendKot($sale);

        $this->assertCount(1, $this->reminders($sale), 'pausing the Dine In counter must not silence Takeaway');
    }

    public function test_resuming_prints_the_next_round(): void
    {
        $this->toggle($this->dineIn, true);
        $sale = $this->order($this->dineIn);
        $this->sendKot($sale);
        $this->assertCount(0, $this->reminders($sale));

        $this->assertFalse($this->toggle($this->dineIn, false)->getData(true)['reminder']['paused']);
        $this->addLine($sale);
        $this->sendKot($sale);

        $reminders = $this->reminders($sale);
        $this->assertCount(1, $reminders, 'ON again → the next round prints');
        $this->assertSame('UPDATED ORDER', $reminders->first()->payload['heading'],
            'revision counts KOT rounds, so it is an UPDATED ORDER slip — carrying the whole order');
    }

    public function test_a_new_shift_starts_with_reminder_on(): void
    {
        $this->toggle($this->dineIn, true);
        $this->assertTrue($this->reminderStatus($this->dineIn)['paused']);

        $shifts = app(ShiftService::class);
        $terminal = Terminal::find($this->dineIn);
        $shifts->closeShift($shifts->activeShiftForTerminal($terminal), $this->cashierId, 0.0);
        $shifts->open(Branch::find($this->branchId), $terminal, $this->cashierId, 0.0);

        $this->assertFalse($this->reminderStatus($this->dineIn)['paused'], 'nobody touched it — the new shift is ON');

        $sale = $this->order($this->dineIn);
        $this->sendKot($sale);
        $this->assertCount(1, $this->reminders($sale));
    }

    public function test_a_cancellation_is_owed_only_where_a_reminder_is_on_paper(): void
    {
        // Order A got its Reminder before the pause; order B was punched while paused.
        $a = $this->order($this->dineIn);
        $this->sendKot($a);
        $this->assertCount(1, $this->reminders($a));

        $this->toggle($this->dineIn, true);
        $b = $this->order($this->dineIn);
        $this->sendKot($b);

        $this->assertCount(1, $this->cancelWholeOrder($a, $this->dineIn),
            'A has a slip on the counter — its cancellation must still print, or someone serves from it');
        $this->assertCount(0, $this->cancelWholeOrder($b, $this->dineIn),
            'B never had a slip — there is nothing to correct');
    }

    public function test_a_cancellation_when_not_paused_is_unchanged(): void
    {
        // An order with no Reminder history, cancelled with Reminder ON, still gets its slip — as before.
        DB::table('category_printer_mappings')->where('print_role', 'reminder')->where('terminal_id', $this->dineIn)->update(['is_active' => 0]);
        $sale = $this->order($this->dineIn);
        $this->sendKot($sale);
        DB::table('category_printer_mappings')->where('print_role', 'reminder')->where('terminal_id', $this->dineIn)->update(['is_active' => 1]);

        $this->assertCount(1, $this->cancelWholeOrder($sale, $this->dineIn));
    }

    public function test_review_and_pay_while_paused_finishes_without_a_reminder(): void
    {
        $this->toggle($this->dineIn, true);
        $sale = $this->order($this->dineIn, 'dine_in', 'paid');
        DB::table('sales_orders')->where('id', $sale->id)->update([
            'direct_pay_print_state' => json_encode(DirectPayPrintOrchestrator::initialState('print', 'skip')),
        ]);

        $this->signIn($this->cashierId);
        $result = app(DirectPayPrintOrchestrator::class)->orchestrate($sale);

        $this->assertSame('queued', $result['state']['kot_status'], 'paid, and the KOT went to the kitchen');
        $this->assertSame('paused', $result['state']['reminder_status']);
        $this->assertFalse($result['retry_available'], 'a paused Reminder is finished, not "Printing needs attention"');
        $this->assertCount(0, $this->reminders($sale));
    }

    public function test_without_the_permission_nothing_changes(): void
    {
        try {
            $this->toggle($this->dineIn, true, $this->waiterId);
            $this->fail('a user without the permission must be refused');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertNull(Shift::where('terminal_id', $this->dineIn)->where('status', 'open')->value('reminders_paused_at'));
        $status = $this->reminderStatus($this->dineIn, $this->waiterId);
        $this->assertTrue($status['available'], 'the state is still shown to him');
        $this->assertFalse($status['can_toggle'], 'but not the button');
    }

    public function test_no_open_shift_is_refused(): void
    {
        DB::table('shifts')->where('terminal_id', $this->dineIn)->update(['status' => 'closed', 'closed_at' => now()]);

        $res = $this->toggle($this->dineIn, true);

        $this->assertSame(422, $res->getStatusCode());
        $this->assertSame(0, Shift::where('terminal_id', $this->dineIn)->whereNotNull('reminders_paused_at')->count());
    }

    public function test_the_switch_shows_only_where_a_reminder_rule_fires(): void
    {
        $this->assertTrue($this->reminderStatus($this->dineIn)['available']);
        $this->assertFalse($this->reminderStatus($this->bare)['available'], 'a terminal without a Reminder rule shows no switch');

        // A rule on an inactive printer, or a printer that cannot print Reminders, does not count.
        DB::table('printers')->where('id', $this->dinePrinter)->update(['supports_reminder' => 0]);
        $this->assertFalse($this->reminderStatus($this->dineIn)['available']);
    }

    public function test_the_migration_grants_counter_roles_once(): void
    {
        $hold = Permission::findOrCreate('tenant.held-sales.store', 'tenant');
        $owner = Role::findOrCreate('Owner', 'tenant');
        $counter = Role::findOrCreate('Counter ' . uniqid(), 'tenant');
        $counter->givePermissionTo($hold);
        $accounts = Role::findOrCreate('Accounts ' . uniqid(), 'tenant');

        $migration = require base_path('database/migrations/tenant/2026_10_05_000002_add_reminder_pause_to_shifts.php');
        $migration->up();
        $migration->up(); // idempotent

        $holders = fn () => DB::table('role_has_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('p.name', ReminderPauseService::PERMISSION)->pluck('rp.role_id')->map(fn ($id) => (int) $id);

        $this->assertContains($owner->id, $holders()->all());
        $this->assertContains($counter->id, $holders()->all(), 'anyone who can hold a POS order can pause');
        $this->assertNotContains($accounts->id, $holders()->all(), 'a role that never works the till gets nothing');
        $this->assertSame($holders()->count(), $holders()->unique()->count(), 'running it twice grants nothing twice');
    }
}
