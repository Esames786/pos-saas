<?php

namespace Tests\MySql;

use App\Models\Tenant\Account;
use App\Models\Tenant\Branch;
use App\Models\Tenant\ExpenseVoucher;
use App\Models\Tenant\Product;
use App\Models\Tenant\Supplier;
use App\Services\Finance\CashShortageExpenseService;
use App\Services\Finance\ExpenseService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\IsolatedPharmacyStore;

/**
 * FINANCE-RESTART-1 (4 Nov) — a business starts its books again from its POS sales. The owner: "sirf sales aur short
 * shift closing rahein, cash aur card ka balance un ke hisaab se; purchase, expense, GRN, inventory, naye heads, opening —
 * sab hatao." Through the real command, on a store built like the business that asked: sales that touch only cash and
 * income, a purchase with its invoice and payment, a shift closing's short-cash voucher that a person later re-split
 * into expenses and posted, and an account head and expense category of the business's own.
 */
class FinanceRestartKeepSalesMySqlTest extends MySqlTenantTestCase
{
    use IsolatedPharmacyStore;

    private const STORE_CODE = 'frstest';
    private const STORE_SUFFIX = '_frs';
    private const STORE_FEATURES = ['product_screen_v2' => true, 'packs' => true, 'purchasing_v2' => true, 'purchase_workspace' => true];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStore();
    }

    public function test_a_business_whose_sales_move_stock_is_refused_and_nothing_is_written(): void
    {
        $c = DB::connection('tenant');
        // The demo store's sales take stock out (COGS against inventory) — taking stock off would break them.
        if (! $c->table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('e.source_type', 'like', 'sales\_%')->where('a.code', '1400')->exists()) {
            $this->saleJournalTouching('1400');
        }
        $before = $this->books();

        $code = Artisan::call('finance:restart-keep-sales', ['tenant_code' => self::STORE_CODE, '--yes' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('REFUSED: sale journals touch heads that are neither cash/bank nor income', Artisan::output());
        $this->assertSame($before, $this->books(), 'a refusal writes nothing');
    }

    public function test_the_books_start_again_from_the_sales_and_the_closings_short_cash_goes_back_to_draft(): void
    {
        $this->onlySalesThatTouchCashAndIncome();
        $c = DB::connection('tenant');

        // A sale (cash), as the business sells: no stock behind the item.
        $plate = $this->product('FRS Biryani Plate', $this->piece()->id, ['default_selling_price' => 500, 'is_stock_tracked' => false, 'is_purchasable' => false]);
        $this->assertTrue($this->sell([['product_id' => $plate->id, 'quantity' => 2, 'unit_price' => 500]], 1000)->isSuccessful());
        $cashId = (int) $c->table('cash_bank_account_transactions')->where('transaction_type', 'sales_payment')->latest('id')->value('cash_bank_account_id');

        // A purchase with its invoice and a payment (stock in, supplier owed, GL, cash out).
        $oil = $this->product('FRS Oil', $this->piece()->id, ['default_selling_price' => 0]);
        $supplier = Supplier::create(['code' => 'FRS-S1', 'name' => 'FRS Supplier', 'status' => 'active', 'opening_balance' => 0, 'current_balance' => 0]);
        $this->owner()->postJson($this->url('/purchases'), ['start' => 'new', '_entry_v2' => '1', 'branch_id' => $this->branchId(), 'supplier_id' => $supplier->id,
            'receipt_date' => now()->toDateString(), 'lines' => [['product_id' => $oil->id, 'buy_pack' => 'base', 'price' => '100', 'packs' => ['base' => '10']]],
            'record_invoice' => '1', 'invoice' => ['bill_date' => now()->toDateString()], 'pay' => '1',
            'payment' => ['amount' => 400, 'cash_bank_account_id' => $cashId, 'payment_method' => 'cash', 'payment_date' => now()->toDateString()]])->assertOk();

        // The business's own head and expense category.
        $parent = Account::where('code', '6000')->firstOrFail();
        $own = Account::create(['code' => '6000-77', 'name' => 'FRS Staff Food', 'type' => 'expense', 'normal_balance' => 'debit', 'parent_id' => $parent->id, 'is_system' => false, 'is_active' => true]);
        $ownCat = $c->table('expense_categories')->insertGetId(['code' => 'FRS-FOOD', 'name' => 'FRS Staff Food', 'account_id' => $own->id, 'is_system' => false,
            'is_active' => true, 'sort_order' => 99, 'created_at' => now(), 'updated_at' => now()]);

        // A shift closing short by 1,500 — then a person re-split it into expenses and posted it.
        $short = app(CashShortageExpenseService::class)->recordShortage(Branch::findOrFail($this->branchId()), now()->toDateString(), 1500, 'shift', 990001, null,
            'Shift #990001. Counted cash was short by 1,500.00. Review and post (or void) once the difference is explained.');
        $c->table('expense_voucher_lines')->where('expense_voucher_id', $short->id)->delete();
        $c->table('expense_voucher_lines')->insert(['expense_voucher_id' => $short->id, 'expense_category_id' => $ownCat, 'account_id' => $own->id,
            'description' => 'staff food', 'amount' => 900, 'tax_amount' => 0, 'line_total' => 900, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        app(ExpenseService::class)->post(app(ExpenseService::class)->recalcTotals($short->fresh()), null);
        $this->assertSame('posted', $short->fresh()->status);
        $sales = $this->sales();

        $this->assertSame(0, Artisan::call('finance:restart-keep-sales', ['tenant_code' => self::STORE_CODE]));
        $this->assertStringContainsString('Dry run — rolled back', Artisan::output());
        $this->assertSame('posted', $short->fresh()->status, 'a dry run writes nothing');

        $this->assertSame(0, Artisan::call('finance:restart-keep-sales', ['tenant_code' => self::STORE_CODE, '--yes' => true]), Artisan::output());

        $this->assertSame($sales, $this->sales(), 'every sale, its journal and its cash movement exactly as before');
        foreach (['goods_receipts', 'purchase_bills', 'supplier_payments', 'supplier_ledgers', 'stock_ledgers', 'stock_balances', 'opening_balance_batches'] as $t) {
            $this->assertSame(0, $c->table($t)->count(), "{$t} starts again from zero");
        }
        $this->assertSame(0, $c->table('journal_entries')->where('source_type', 'not like', 'sales\_%')->count(), 'only sale journals are left');
        $tb = $c->table('journal_lines')->selectRaw('ROUND(SUM(debit),2) d, ROUND(SUM(credit),2) c')->first();
        $this->assertSame((float) $tb->d, (float) $tb->c, 'the trial balance balances');

        $cash = $c->table('cash_bank_accounts')->where('id', $cashId)->first();
        $saleCash = (float) $c->table('cash_bank_account_transactions')->where('cash_bank_account_id', $cashId)
            ->sum(DB::raw("CASE WHEN direction = 'in' THEN amount ELSE -amount END"));
        $this->assertEqualsWithDelta($saleCash, (float) $cash->current_balance, 0.001, 'the drawer holds what the sales brought in');
        $this->assertEqualsWithDelta($saleCash, (float) $c->table('cash_bank_account_transactions')->where('cash_bank_account_id', $cashId)
            ->orderByDesc('transaction_date')->orderByDesc('id')->value('balance_after'), 0.001, 'its running balance is rebuilt');
        $this->assertEqualsWithDelta(0, (float) $supplier->fresh()->current_balance, 0.001);

        $back = ExpenseVoucher::with('lines')->findOrFail($short->id);
        $this->assertSame(['draft', 1500.0, 1], [$back->status, (float) $back->total_amount, $back->lines->count()],
            'the closing\'s short cash is back as the closing made it — draft, the amount it was short');
        $this->assertSame(CashShortageExpenseService::CATEGORY_CODE, $c->table('expense_categories')->where('id', $back->lines->first()->expense_category_id)->value('code'));

        $this->assertNull(Account::where('code', '6000-77')->first(), 'the business\'s own head is gone');
        $this->assertFalse($c->table('expense_categories')->where('id', $ownCat)->exists(), 'with its own expense category');
        $this->assertNotNull(Account::where('code', '2100')->first(), 'every system head stays');

        $heads = Account::pluck('code')->sort()->values()->all();
        $this->assertSame(0, Artisan::call('finance:restart-keep-sales', ['tenant_code' => self::STORE_CODE, '--yes' => true]));
        $this->assertSame($sales, $this->sales(), 'running it again changes nothing');
        $this->assertSame($heads, Account::pluck('code')->sort()->values()->all(), 'nor any head');
        $this->assertSame(['draft', 1500.0], [$back->fresh()->status, (float) $back->fresh()->total_amount]);
    }

    // ─────────────────────────────────────────────────────────────────────────────

    /** The demo store's own sales move stock; the business this is for sells without stock — start from such sales. */
    private function onlySalesThatTouchCashAndIncome(): void
    {
        $c = DB::connection('tenant');
        $ids = $c->table('journal_entries')->where('source_type', 'like', 'sales\_%')->pluck('id');
        $c->table('journal_lines')->whereIn('journal_entry_id', $ids)->delete();
        $c->table('journal_entries')->whereIn('id', $ids)->delete();
    }

    private function saleJournalTouching(string $code): void
    {
        $c = DB::connection('tenant');
        $id = $c->table('journal_entries')->insertGetId(['entry_no' => 'JE-FRS-' . random_int(1000, 9999), 'entry_date' => now()->toDateString(),
            'source_type' => 'sales_order_paid', 'source_id' => 990002, 'description' => 'sale with COGS', 'status' => 'posted', 'total_debit' => 10,
            'total_credit' => 10, 'is_reversal' => false, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([['5100', 10, 0], [$code, 0, 10]] as $i => [$acc, $dr, $cr]) {
            $c->table('journal_lines')->insert(['journal_entry_id' => $id, 'account_id' => Account::where('code', $acc)->value('id'), 'debit' => $dr,
                'credit' => $cr, 'sort_order' => $i, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function sales(): string
    {
        $c = DB::connection('tenant');

        return json_encode([
            $c->table('sales_orders')->selectRaw('COUNT(*) n, ROUND(SUM(grand_total),2) t')->first(),
            $c->table('journal_entries')->where('source_type', 'like', 'sales\_%')->selectRaw('COUNT(*) n, ROUND(SUM(total_debit),2) d')->first(),
            $c->table('cash_bank_account_transactions')->where('transaction_type', 'like', 'sales\_%')->selectRaw('COUNT(*) n, ROUND(SUM(amount),2) a')->first(),
        ]);
    }

    private function books(): string
    {
        $c = DB::connection('tenant');

        return json_encode([$c->table('journal_entries')->count(), $c->table('journal_lines')->count(), $c->table('goods_receipts')->count(),
            $c->table('expense_vouchers')->count(), $c->table('stock_ledgers')->count(), $c->table('accounts')->count(), $c->table('cash_bank_account_transactions')->count()]);
    }
}
