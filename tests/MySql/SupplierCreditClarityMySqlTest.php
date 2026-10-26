<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\GoodsReceiptController;
use App\Http\Controllers\Tenant\PurchaseBillController;
use App\Http\Controllers\Tenant\SupplierPaymentController;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\PurchaseBill;
use App\Models\Tenant\PurchasingSetting;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\SupplierCreditAllocation;
use App\Models\Tenant\SupplierPayment;
use App\Models\Tenant\User;
use App\Services\Finance\SupplierPayableService;
use App\Services\Purchasing\SupplierRunningAccountService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\MySql\Support\ParsesInlineScripts;
use Tests\MySql\Support\TenantFixtures;

/**
 * SUPPLIER-CREDIT-CLARITY-1 (owner, 26 Oct) — a supplier paid on account: nothing is put on an invoice the person did not
 * see. A payment says where it goes (one invoice, or the oldest first — nothing chosen for them) and lists, before Save,
 * the invoices it pays; it posts only if that list is still true. An invoice that uses the supplier's advance names the
 * payments it uses and waits for a tick. A part put on the wrong invoice can be moved to another — the books do not move.
 *
 * Real paths: the payment and invoice screens through their controllers (as posted and as rendered), payments made
 * elsewhere through SupplierPayableService::recordPayment.
 */
class SupplierCreditClarityMySqlTest extends MySqlTenantTestCase
{
    use ParsesInlineScripts;
    use Support\RegistersTestTenant;
    use TenantFixtures;

    private int $branchId;
    private int $supplierId;
    private int $item;
    private int $bankId;
    private array $acc = [];

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'supplier_credit_moves', 'supplier_credit_reallocations', 'purchase_bill_return_nettings',
            'supplier_credit_allocations', 'purchasing_settings', 'cash_bank_account_transactions', 'cash_bank_accounts',
            'journal_lines', 'journal_entries', 'supplier_payments', 'purchase_return_lines', 'purchase_returns', 'purchase_bill_lines',
            'purchase_bills', 'supplier_ledgers', 'stock_ledgers', 'stock_balances', 'inventory_batches',
            'goods_receipt_lines', 'goods_receipts', 'product_variants', 'products', 'categories', 'suppliers', 'branches', 'accounts',
        ]);
        foreach ([['1200', 'Bank', 'asset', 'debit'], ['1400', 'Inventory Asset', 'asset', 'debit'],
            ['2100', 'Accounts Payable', 'liability', 'credit'], ['5100', 'Product COGS', 'expense', 'debit']] as [$c, $n, $t, $nb]) {
            $this->acc[$c] = DB::table('accounts')->insertGetId(['code' => $c, 'name' => $n, 'type' => $t, 'normal_balance' => $nb, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->branchId = $this->makeBranch(['status' => 'active']);
        $this->item = $this->makeProduct($this->makeCategory(), ['name' => 'Flour', 'is_sellable' => 0, 'is_pos_visible' => 0, 'is_purchasable' => 1, 'is_stock_tracked' => 0]);
        $this->supplierId = $this->supplier('SUP-CC', 'Clarity Mills');
        $this->bankId = DB::table('cash_bank_accounts')->insertGetId([
            'code' => 'CC-CASH', 'name' => 'Cash in Hand', 'account_type' => 'cash', 'account_id' => $this->acc['1200'],
            'branch_id' => $this->branchId, 'opening_balance' => 0, 'current_balance' => 0, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->errors();
        PurchasingSetting::tenantDefault()->update(['supplier_running_account' => true]);
    }

    // ── the payment screen ───────────────────────────────────────────────────────────────────────────────────

    public function test_the_payment_screen_asks_where_it_goes_chooses_nothing_and_lists_the_invoices_in_the_servers_order(): void
    {
        $late = $this->bill(500, '2026-10-02');
        $early = $this->bill(1000, '2026-10-01');   // made second, dated first — the date decides, as on the server

        $html = $this->paymentPage();
        $this->assertStringContainsString('id="pay-to-box"', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*name="pay_to"[^>]*id="pay_to_bill"[^>]*value="bill"\s*>/', $html, 'one invoice: not picked');
        $this->assertMatchesRegularExpression('/<input[^>]*name="pay_to"[^>]*id="pay_to_oldest"[^>]*value="oldest"\s*>/', $html, 'oldest first: not picked');
        $this->assertStringContainsString('<option value="">— choose the invoice —</option>', $html);
        $this->assertStringContainsString('id="pay-plan"', $html, 'the list of invoices it pays, before Save');
        $this->assertStringContainsString('name="plan" id="pay-plan-value"', $html);
        $this->assertStringNotContainsString('Against Bill <span class="text-muted fw-normal">(optional)</span>', $html, 'where it goes is not optional on account');
        $this->assertLessThan(strpos($html, 'data-no="' . $late->bill_no . '"'), strpos($html, 'data-no="' . $early->bill_no . '"'), 'oldest date first');
        $this->assertInlineScriptsParse($html, 'supplier payment', 'pay-plan-value');

        // Opened from an invoice's own "Pay Supplier" button: that invoice is chosen, since the person chose it there.
        $fromBill = $this->paymentPage(['purchase_bill_id' => $late->id]);
        $this->assertMatchesRegularExpression('/id="pay_to_bill" value="bill"\s+checked/', $fromBill);
    }

    public function test_no_choice_no_invoice_or_no_list_posts_nothing(): void
    {
        $bill = $this->bill(1000);
        $books = $this->books();

        $this->postPayment(['amount' => 400, 'plan' => $bill->id . ':400.00|0.00']);
        $this->assertSame('Choose where this payment goes: one invoice, or the oldest open invoices first.', $this->error('pay_to'));

        $this->postPayment(['amount' => 400, 'pay_to' => 'bill', 'plan' => $bill->id . ':400.00|0.00']);
        $this->assertSame('Choose the invoice this payment is for.', $this->error('purchase_bill_id'));

        $this->postPayment(['amount' => 400, 'pay_to' => 'oldest']);
        $this->assertSame('Check where this payment goes (listed below), then press Post again.', $this->error('plan'), 'no list shown = not seen');

        $this->assertSame(0, SupplierPayment::count());
        $this->assertSame($books, $this->books(), 'nothing posted');
    }

    public function test_oldest_first_pays_exactly_the_list_the_screen_showed(): void
    {
        $a = $this->bill(1000, '2026-10-01');
        $b = $this->bill(500, '2026-10-02');

        $this->postPayment(['amount' => 1200, 'pay_to' => 'oldest', 'purchase_bill_id' => $b->id,   // ignored: oldest first
            'plan' => $a->id . ':1000.00;' . $b->id . ':200.00|0.00']);
        $this->assertNull(session('errors'));
        $payment = SupplierPayment::sole();
        $this->assertNull($payment->purchase_bill_id, 'oldest first is not "against" one invoice');
        $this->assertSame([$a->id => 1000.0, $b->id => 200.0], $this->paidBy($payment));
        $this->assertSame(300.0, $this->balance());
    }

    public function test_one_invoice_pays_that_invoice_first_then_the_oldest_and_the_rest_lowers_the_balance(): void
    {
        $a = $this->bill(1000, '2026-10-01');
        $b = $this->bill(500, '2026-10-02');

        $this->postPayment(['amount' => 1800, 'pay_to' => 'bill', 'purchase_bill_id' => $b->id,
            'plan' => $b->id . ':500.00;' . $a->id . ':1000.00|300.00']);
        $this->assertNull(session('errors'));
        $payment = SupplierPayment::sole();
        $this->assertSame($b->id, (int) $payment->purchase_bill_id);
        $this->assertSame([$b->id => 500.0, $a->id => 1000.0], $this->paidBy($payment), 'the chosen invoice first');
        $this->assertSame(-300.0, $this->balance(), '300 paid ahead');
    }

    public function test_a_list_that_is_no_longer_true_is_refused_and_nothing_is_saved(): void
    {
        $a = $this->bill(1000, '2026-10-01');
        $b = $this->bill(500, '2026-10-02');
        $shown = $a->id . ':600.00|0.00';   // what the screen listed for 600, oldest first

        $this->pay(1000);                   // meanwhile someone else paid the first invoice
        $books = $this->books();
        $this->postPayment(['amount' => 600, 'pay_to' => 'oldest', 'plan' => $shown]);
        $this->assertSame('Check where this payment goes (listed below), then press Post again.', $this->error('plan'));
        $this->assertSame(1, SupplierPayment::count(), 'only the other payment');
        $this->assertSame($books, $this->books());

        // The wrong order, or another amount on an invoice, is not the same list either.
        $this->postPayment(['amount' => 600, 'pay_to' => 'oldest', 'plan' => $b->id . ':500.00|90.00']);
        $this->assertNotNull($this->error('plan'), 'a paisa-level difference in the advance');

        // The list the screen now shows posts.
        $this->postPayment(['amount' => 600, 'pay_to' => 'oldest', 'plan' => $b->id . ':500.00|100.00']);
        $this->assertNull(session('errors'));
        $this->assertSame([$b->id => 500.0], $this->paidBy(SupplierPayment::latest('id')->first()));
    }

    public function test_planSettle_is_what_settleCredit_then_does(): void
    {
        $a = $this->bill(700, '2026-10-01');
        $b = $this->bill(300, '2026-10-03');
        $c = $this->bill(900, '2026-10-02');
        $running = app(SupplierRunningAccountService::class);

        foreach ([[1500, null], [1500, $b->id], [250.55, $c->id], [5000, $a->id]] as [$amount, $preferred]) {
            $plan = $running->planSettle($this->supplierId, $amount, $preferred);
            DB::connection('tenant')->beginTransaction();
            $payment = $this->pay($amount, $preferred);
            $actual = $this->paidBy($payment);
            DB::connection('tenant')->rollBack();
            $this->assertSame(array_combine(array_column($plan['lines'], 'bill_id'), array_column($plan['lines'], 'amount')), $actual, 'same invoices, same order, same amounts');
            $this->assertEqualsWithDelta($amount - array_sum($actual), $plan['advance'], 0.001);
        }
    }

    public function test_running_account_off_keeps_todays_payment_screen(): void
    {
        PurchasingSetting::tenantDefault()->update(['supplier_running_account' => false]);
        $bill = $this->bill(1000);

        $html = $this->paymentPage();
        $this->assertStringNotContainsString('id="pay-to-box"', $html);
        $this->assertStringNotContainsString('id="pay-plan"', $html);
        $this->assertStringContainsString('Against Bill <span class="text-muted fw-normal">(optional)</span>', $html);

        $this->postPayment(['amount' => 400, 'purchase_bill_id' => $bill->id]);   // no choice, no list — as before
        $this->assertNull(session('errors'));
        $this->assertSame([400.0, 600.0, 'partial'], $this->billState($bill));
        $this->assertSame(0, SupplierCreditAllocation::count());
    }

    // ── an invoice that uses the advance ─────────────────────────────────────────────────────────────────────

    public function test_an_invoice_that_uses_the_advance_names_it_and_posts_only_with_the_tick(): void
    {
        $payment = $this->pay(300);
        $grn = $this->receive(1000, '2026-10-06');
        $books = $this->books();

        $html = $this->billPage($grn);
        $this->assertStringContainsString('id="pb-rv-credit"', $html, 'the review names the advance');
        $this->assertStringContainsString('name="confirm_credit" id="confirm_credit"', $html);
        $this->assertStringContainsString('"label":"Payment ' . $payment->payment_no . '"', $html, 'by payment number');
        $this->assertInlineScriptsParse($html, 'supplier bill', 'pb-form');

        $this->postBill($grn);
        $this->assertSame('This invoice uses Rs 300.00 of the supplier\'s advance. Tick "Use this advance on this invoice", then post again — nothing was saved.',
            $this->error('confirm_credit'));
        $this->assertSame(0, PurchaseBill::count(), 'no invoice');
        $this->assertSame($books, $this->books(), 'no ledger row, no journal');
        $this->assertSame(0, SupplierCreditAllocation::count());

        $reopened = $this->billPage($grn, ['confirm_credit' => 'Tick it.']);
        $this->assertStringContainsString('var CREDIT_ASKED = true;', $reopened, 'refused for the tick: the review shows it');

        $this->postBill($grn, ['confirm_credit' => '1']);
        $this->assertNull(session('errors'));
        $bill = PurchaseBill::sole();
        $this->assertSame([300.0, 700.0, 'partial'], $this->billState($bill));
        $this->assertSame(700.0, $this->balance());
    }

    public function test_an_invoice_without_an_advance_asks_nothing(): void
    {
        $grn = $this->receive(1000);
        $html = $this->billPage($grn);
        $this->assertStringNotContainsString('id="pb-rv-credit"', $html);

        $this->postBill($grn);
        $this->assertNull(session('errors'));
        $this->assertSame([0.0, 1000.0, 'posted'], $this->billState(PurchaseBill::sole()));
    }

    public function test_running_account_off_never_asks_for_the_tick(): void
    {
        PurchasingSetting::tenantDefault()->update(['supplier_running_account' => false]);
        $grn = $this->receive(1000);
        $this->assertStringNotContainsString('id="pb-rv-credit"', $this->billPage($grn));
        $this->postBill($grn);
        $this->assertNull(session('errors'));
        $this->assertSame(1, PurchaseBill::count());
    }

    // ── moving a part to another invoice ─────────────────────────────────────────────────────────────────────

    public function test_moving_a_part_to_another_invoice_changes_only_which_invoice_it_pays(): void
    {
        $a = $this->bill(1000, '2026-10-01');
        $b = $this->bill(500, '2026-10-02');
        $payment = $this->pay(600);   // oldest first: all 600 on the first invoice
        $this->assertSame([$a->id => 600.0], $this->paidBy($payment));
        $books = $this->books();
        $mover = $this->userWith('tenant.purchase-bills.move-credit');

        $this->move($a, $mover, ['source' => 'payment:' . $payment->id, 'to_bill_id' => $b->id, 'amount' => 400, 'reason' => 'it was for the second invoice']);
        $this->assertNull(session('errors'));
        $this->assertSame([200.0, 800.0, 'partial'], $this->billState($a), 'the first invoice owes 400 again');
        $this->assertSame([400.0, 100.0, 'partial'], $this->billState($b), 'the second 400 less');
        $this->assertSame([$a->id => 200.0, $b->id => 400.0], $this->paidBy($payment), 'the payment still pays 600 in all');
        $this->assertSame($books, $this->books(), 'supplier balance, ledger, journals and cash unchanged');

        $move = DB::table('supplier_credit_moves')->sole();
        $this->assertSame([$this->supplierId, 'payment', $payment->id, $a->id, $b->id, 400.0, 'it was for the second invoice', $mover->id],
            [(int) $move->supplier_id, $move->source_type, (int) $move->source_id, (int) $move->from_purchase_bill_id, (int) $move->to_purchase_bill_id,
                (float) $move->amount, $move->reason, (int) $move->moved_by]);

        // Both invoice pages and the payment's say it.
        $first = $this->billShow($a, $mover);
        $this->assertStringContainsString('How this invoice was paid', $first);
        $this->assertMatchesRegularExpression('#>Payment ' . preg_quote($payment->payment_no, '#') . '</a></td>\s*<td>[0-9-]+</td>\s*<td class="text-end">200\.00</td>#', $first);
        $this->assertStringContainsString('moved from ' . $a->bill_no, $first);
        $this->assertStringContainsString('by ' . $mover->name . ': it was for the second invoice', $first);
        $this->assertStringContainsString('id="move-credit-form"', $first, 'the move form for a person who may move');
        $this->assertInlineScriptsParse($first, 'invoice page', 'mv-source');
        $this->assertMatchesRegularExpression('#moved from ' . preg_quote($a->bill_no, '#') . '\s+to ' . preg_quote($b->bill_no, '#') . '#', $this->billShow($b, $mover));

        $paymentPage = $this->paymentShow($payment);
        $this->assertMatchesRegularExpression('#id="payment-paid">.*' . preg_quote($a->bill_no, '#') . '</a>.*200\.00.*' . preg_quote($b->bill_no, '#') . '</a>.*400\.00#s', $paymentPage);
    }

    public function test_a_move_that_does_not_fit_is_refused_and_nothing_moves(): void
    {
        $a = $this->bill(1000, '2026-10-01');
        $b = $this->bill(300, '2026-10-02');
        $payment = $this->pay(600);
        $otherSupplier = $this->supplier('SUP-OT', 'Other Mills');
        $other = $this->bill(800, '2026-10-03', $otherSupplier);
        $mover = $this->userWith('tenant.purchase-bills.move-credit');
        $before = [$this->billState($a), $this->billState($b), $this->billState($other), $this->books()];

        $cases = [
            [['amount' => 700, 'to_bill_id' => $b->id], 'move', $b->bill_no . ' owes 300.00 — less than 700.00. Nothing was moved.'],
            [['amount' => 250, 'to_bill_id' => $b->id, 'source' => 'payment:' . ($payment->id + 999)], 'move', 'This pays only 0.00 on ' . $a->bill_no . '. Nothing was moved.'],
            [['amount' => 100, 'to_bill_id' => $other->id], 'move', 'The other invoice is for a different supplier.'],
            [['amount' => 100, 'to_bill_id' => $a->id], 'move', 'Choose a different invoice to move it to.'],
            [['amount' => 100, 'to_bill_id' => $b->id, 'reason' => ''], 'reason', 'Write why it is being moved.'],
        ];
        foreach ($cases as [$fields, $key, $message]) {
            $this->move($a, $mover, $fields + ['source' => 'payment:' . $payment->id, 'reason' => 'wrong invoice']);
            $this->assertSame($message, $this->error($key));
        }
        $this->assertSame($before, [$this->billState($a), $this->billState($b), $this->billState($other), $this->books()]);
        $this->assertSame(0, DB::table('supplier_credit_moves')->count());

        // More than the payment holds on this invoice, with room on the other.
        $c = $this->bill(5000, '2026-10-04');
        $this->move($a, $mover, ['source' => 'payment:' . $payment->id, 'to_bill_id' => $c->id, 'amount' => 600.01, 'reason' => 'too much']);
        $this->assertSame('This pays only 600.00 on ' . $a->bill_no . '. Nothing was moved.', $this->error('move'));
    }

    public function test_the_move_needs_its_own_permission_and_one_press_moves_once(): void
    {
        $route = collect(Route::getRoutes())->first(fn ($r) => $r->getName() === 'tenant.purchase-bills.move-credit');
        $this->assertNotNull($route);
        $this->assertSame(['POST'], $route->methods());
        $this->assertContains('route.permission', $route->gatherMiddleware(), 'its own permission, by route name');
        $this->assertContains('prevent.duplicate.submit', $route->gatherMiddleware(), 'a double press is one move');

        $a = $this->bill(1000, '2026-10-01');
        $this->bill(500, '2026-10-02');
        $this->pay(600);
        $this->assertStringNotContainsString('id="move-credit-form"', $this->billShow($a, $this->userWith('tenant.purchase-bills.show')), 'no permission: no form');
        $this->assertStringContainsString('id="move-credit-form"', $this->billShow($a, $this->userWith('tenant.purchase-bills.move-credit')));
        $this->assertContains('tenant.purchase-bills.move-credit', $this->ownerPermissionsForNewTenants(), 'a new tenant\'s Owner gets it');
    }

    public function test_running_account_off_has_no_move_and_keeps_todays_invoice_page(): void
    {
        $a = $this->bill(1000, '2026-10-01');
        PurchasingSetting::tenantDefault()->update(['supplier_running_account' => false]);
        $mover = $this->userWith('tenant.purchase-bills.move-credit');

        $html = $this->billShow($a, $mover);
        $this->assertStringNotContainsString('How this invoice was paid', $html);
        $this->assertStringContainsString('<strong>Payments</strong>', $html, 'today\'s table');

        try {
            $this->move($a, $mover, ['source' => 'payment:1', 'to_bill_id' => $a->id, 'amount' => 1, 'reason' => 'off']);
            $this->fail('moved with the running account off');
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
            $this->assertSame(0, DB::table('supplier_credit_moves')->count());
        }
    }

    /** A transaction reset wipes the record of moves with the invoices and payments it points at (it fails closed on an unlisted table). */
    public function test_a_transaction_reset_wipes_the_moves_with_the_invoices(): void
    {
        $a = $this->bill(1000, '2026-10-01');
        $b = $this->bill(500, '2026-10-02');
        $payment = $this->pay(600);
        $this->move($a, $this->userWith('tenant.purchase-bills.move-credit'), ['source' => 'payment:' . $payment->id, 'to_bill_id' => $b->id, 'amount' => 100, 'reason' => 'before a reset']);
        $c = DB::connection('tenant');
        $this->assertSame(1, $c->table('supplier_credit_moves')->count(), 'precondition: a real move');

        $code = $this->registerTestTenant('scctest');
        $exit = Artisan::call('tenant:reset-transactions', ['tenant_code' => $code, '--yes' => true, '--confirm' => $code]);
        $output = Artisan::output();
        DB::setDefaultConnection('tenant');
        $this->assertSame(0, $exit, $output);
        $this->assertSame([0, 0, 0, 0], [$c->table('supplier_credit_moves')->count(), $c->table('supplier_credit_allocations')->count(),
            $c->table('purchase_bills')->count(), $c->table('supplier_payments')->count()]);
        $this->assertSame(1, $c->table('suppliers')->count(), 'the supplier is master data and stays');
    }

    /** A voided payment deleted for good takes the record of its moves with it — no invoice is left naming it. */
    public function test_deleting_a_voided_payment_for_good_takes_its_moves_with_it(): void
    {
        $a = $this->bill(1000, '2026-10-01');
        $b = $this->bill(500, '2026-10-02');
        $payment = $this->pay(600);
        $kept = $this->pay(50);   // another payment of the supplier: its own record stays
        $mover = $this->userWith('tenant.purchase-bills.move-credit');
        $this->move($a, $mover, ['source' => 'payment:' . $payment->id, 'to_bill_id' => $b->id, 'amount' => 100, 'reason' => 'wrong invoice']);
        $this->move($a, $mover, ['source' => 'payment:' . $kept->id, 'to_bill_id' => $b->id, 'amount' => 50, 'reason' => 'the other one']);
        $c = DB::connection('tenant');
        $this->assertSame(2, $c->table('supplier_credit_moves')->count(), 'precondition: two real moves');

        app(SupplierPayableService::class)->voidPayment($payment->fresh(), 'entered on the wrong supplier', null, \App\Support\VoidDate::ORIGINAL);
        $bills = [$this->billState($a), $this->billState($b)];
        $entry = app(\App\Services\Finance\VoidPurgeService::class)->purge('supplier_payment', $payment->id, 'client asked to remove it', null, null, true);

        $this->assertSame(0, $c->table('supplier_payments')->where('id', $payment->id)->count());
        $this->assertSame([(int) $kept->id], $c->table('supplier_credit_moves')->pluck('source_id')->map(fn ($v) => (int) $v)->all(), 'only the other payment\'s move is left');
        $this->assertSame(1, count($entry->snapshot['tables']['supplier_credit_moves'] ?? []), 'kept in the hidden copy');
        $this->assertSame($bills, [$this->billState($a), $this->billState($b)], 'no invoice changed');
        $this->assertStringNotContainsString($payment->payment_no, $this->billShow($a, $mover));
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────────────────────

    private function supplier(string $code, string $name): int
    {
        return DB::table('suppliers')->insertGetId(['code' => $code, 'name' => $name, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function receive(float $total, string $date = '2026-10-01', ?int $supplierId = null): GoodsReceipt
    {
        app(GoodsReceiptController::class)->store(Request::create('/goods-receipts', 'POST', [
            'supplier_id' => $supplierId ?? $this->supplierId, 'branch_id' => $this->branchId, 'receipt_date' => $date,
            'lines' => [['product_id' => $this->item, 'quantity_received' => 1, 'unit_cost' => $total]],
        ]));

        return GoodsReceipt::on('tenant')->latest('id')->firstOrFail();
    }

    /** A received and invoiced purchase (no advance in play, so no tick). */
    private function bill(float $total, string $date = '2026-10-01', ?int $supplierId = null): PurchaseBill
    {
        $grn = $this->receive($total, $date, $supplierId);
        $this->postBill($grn);
        $this->assertNull(session('errors'), 'the invoice posted');

        return PurchaseBill::where('goods_receipt_id', $grn->id)->firstOrFail();
    }

    private function postBill(GoodsReceipt $grn, array $fields = []): void
    {
        $this->send('/purchase-bills', ['goods_receipt_id' => $grn->id, 'bill_date' => $grn->receipt_date?->toDateString() ?? '2026-10-01'] + $fields,
            fn ($r) => app(PurchaseBillController::class)->store($r));
    }

    /** A payment made somewhere other than the payment screen (the workspace, a ledger) — the service, no list. */
    private function pay(float $amount, ?int $billId = null): SupplierPayment
    {
        return app(SupplierPayableService::class)->recordPayment([
            'supplier_id' => $this->supplierId, 'branch_id' => $this->branchId, 'cash_bank_account_id' => $this->bankId,
            'purchase_bill_id' => $billId, 'payment_date' => '2026-10-05', 'amount' => $amount, 'payment_method' => 'cash',
        ]);
    }

    private function postPayment(array $fields): void
    {
        $this->send('/supplier-payments', $fields + [
            'supplier_id' => $this->supplierId, 'branch_id' => $this->branchId, 'cash_bank_account_id' => $this->bankId,
            'payment_date' => '2026-10-05', 'payment_method' => 'cash',
        ], fn ($r) => app(SupplierPaymentController::class)->store($r));
    }

    private function move(PurchaseBill $from, User $user, array $fields): void
    {
        $this->send('/purchase-bills/' . $from->id . '/move-credit', $fields,
            fn ($r) => app(PurchaseBillController::class)->moveCredit($r, $from, app(SupplierRunningAccountService::class)), $user);
    }

    /** One form post through its controller, with a fresh session: a refusal leaves its errors in session('errors'). */
    private function send(string $uri, array $fields, callable $controller, ?User $user = null): void
    {
        app('session.store')->flush();
        $request = Request::create($uri, 'POST', $fields);
        $request->setLaravelSession(app('session.store'));
        if ($user) {
            auth('tenant')->setUser($user);
            $request->setUserResolver(fn () => $user);
        }
        $this->app->instance('request', $request);
        try {
            $controller($request);
        } catch (\Illuminate\Validation\ValidationException $e) {
            app('session.store')->put('errors', (new ViewErrorBag)->put('default', new MessageBag($e->errors())));
        }
    }

    private function error(string $key): ?string
    {
        return session('errors')?->first($key) ?: null;
    }

    private function errors(array $messages = []): void
    {
        view()->share('errors', (new ViewErrorBag)->put('default', new MessageBag($messages)));
    }

    private function paymentPage(array $query = []): string
    {
        $this->errors();

        return app(SupplierPaymentController::class)->create(Request::create('/supplier-payments/create', 'GET', $query))->render();
    }

    private function paymentShow(SupplierPayment $payment): string
    {
        $this->errors();

        return app(SupplierPaymentController::class)->show($payment->fresh())->render();
    }

    private function billPage(GoodsReceipt $grn, array $errors = []): string
    {
        $this->errors($errors);

        return app(PurchaseBillController::class)->create(Request::create('/purchase-bills/create', 'GET', ['goods_receipt_id' => $grn->id]))->render();
    }

    private function billShow(PurchaseBill $bill, User $user): string
    {
        $this->errors();
        auth('tenant')->setUser($user);
        $request = Request::create('/purchase-bills/' . $bill->id, 'GET');
        $request->setUserResolver(fn () => $user);

        return app(PurchaseBillController::class)->show($request, $bill->fresh())->render();
    }

    private function userWith(string $permission): User
    {
        $user = User::create(['name' => 'Clerk ' . substr(uniqid(), -5), 'email' => 'c' . uniqid() . '@test.local', 'password' => bcrypt('x'), 'status' => 'active']);
        Permission::findOrCreate($permission, 'tenant');
        $user->givePermissionTo($permission);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    /** The Owner list TenantProvisioner gives a new tenant (read from its source: the list is a literal there). */
    private function ownerPermissionsForNewTenants(): array
    {
        preg_match_all("/'(tenant\.[a-z0-9.\-]+)'/", file_get_contents(app_path('Services/Tenancy/TenantProvisioner.php')), $m);

        return $m[1];
    }

    /** bill id => amount this payment pays on it, in allocation order */
    private function paidBy(SupplierPayment $payment): array
    {
        return SupplierCreditAllocation::where('source_type', 'payment')->where('source_id', $payment->id)->orderBy('id')->get()
            ->groupBy('purchase_bill_id')->map(fn ($rows) => round((float) $rows->sum('amount'), 2))->all();
    }

    private function billState(PurchaseBill $bill): array
    {
        $bill = $bill->fresh();

        return [round((float) $bill->amount_paid, 2), round((float) $bill->balance_due, 2), $bill->status];
    }

    private function balance(): float
    {
        return round((float) Supplier::find($this->supplierId)->current_balance, 2);
    }

    /** What a move must never change: suppliers' balances, their ledgers, the journals and cash. */
    private function books(): array
    {
        $c = DB::connection('tenant');

        return [
            $c->table('suppliers')->orderBy('id')->pluck('current_balance')->map(fn ($v) => round((float) $v, 4))->all(),
            [$c->table('supplier_ledgers')->count(), round((float) $c->table('supplier_ledgers')->sum('amount'), 4)],
            [$c->table('journal_lines')->count(), round((float) $c->table('journal_lines')->sum('debit'), 4), round((float) $c->table('journal_lines')->sum('credit'), 4)],
            $c->table('cash_bank_account_transactions')->count(),
        ];
    }
}
