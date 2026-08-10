<?php

namespace Tests\MySql;

use App\Models\Tenant\Branch;
use App\Models\Tenant\ExpenseCategory;
use App\Models\Tenant\ExpenseVoucher;
use App\Services\Finance\CashShortageExpenseService;
use Illuminate\Support\Facades\DB;
use Tests\MySql\Support\TenantFixtures;

/**
 * CASH-SHORTAGE-1 — closing a drawer SHORT raises a DRAFT expense voucher under an
 * auto-created "Daily Closing — Short Cash" category so finance settles it later.
 * Draft-only by design: no journal is posted until finance posts the voucher.
 */
class CashShortageExpenseMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant(['expense_voucher_lines', 'expense_vouchers', 'expense_categories', 'cash_bank_account_transactions', 'cash_bank_accounts', 'journal_lines', 'journal_entries', 'accounts', 'branches', 'users']);
        $this->branch = Branch::findOrFail($this->makeBranch());
        (new \Database\Seeders\Tenant\DefaultChartOfAccountsSeeder())->run();
        DB::connection('tenant')->table('cash_bank_accounts')->insert([
            'branch_id' => $this->branch->id, 'code' => 'CASH-1', 'name' => 'Main Drawer',
            'account_type' => 'cash', 'is_default' => 1, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_short_close_creates_one_draft_voucher_and_never_posts_a_journal(): void
    {
        $service = app(CashShortageExpenseService::class);

        $voucher = $service->recordShortage($this->branch, '2026-08-10', 670.0, 'shift', 42, null, 'Terminal Counter 1.');

        $this->assertNotNull($voucher);
        $this->assertSame('draft', $voucher->status, 'the shortage is a DRAFT for finance — never auto-posted');
        $this->assertSame(670.0, (float) $voucher->total_amount);
        $this->assertSame($this->branch->id, (int) $voucher->branch_id);

        $line = $voucher->lines()->first();
        $this->assertSame(670.0, (float) $line->line_total);

        $category = ExpenseCategory::find($line->expense_category_id);
        $this->assertSame(CashShortageExpenseService::CATEGORY_CODE, $category->code, 'auto-created system category');
        $this->assertTrue((bool) $category->is_system);
        $this->assertSame(
            CashShortageExpenseService::ACCOUNT_CODE,
            DB::connection('tenant')->table('accounts')->where('id', $category->account_id)->value('code'),
            'category maps to the Cash Short / Over expense account'
        );

        // Draft-only: nothing in the GL or the cash-bank ledger yet.
        $this->assertSame(0, DB::connection('tenant')->table('journal_entries')->count());
        $this->assertSame(0, DB::connection('tenant')->table('cash_bank_account_transactions')->count());
    }

    public function test_repeated_close_of_the_same_source_never_duplicates_the_voucher(): void
    {
        $service = app(CashShortageExpenseService::class);

        $first = $service->recordShortage($this->branch, '2026-08-10', 500.0, 'shift', 7);
        $again = $service->recordShortage($this->branch, '2026-08-10', 500.0, 'shift', 7);

        $this->assertSame($first->id, $again->id, 'same source → same voucher (idempotent)');
        $this->assertSame(1, ExpenseVoucher::count());

        // a DIFFERENT source (branch daily closing) is its own voucher.
        $service->recordShortage($this->branch, '2026-08-10', 120.0, 'daily_closing', 7);
        $this->assertSame(2, ExpenseVoucher::count());
    }

    public function test_exact_or_over_count_raises_nothing(): void
    {
        $service = app(CashShortageExpenseService::class);

        $this->assertNull($service->recordShortage($this->branch, '2026-08-10', 0.0, 'shift', 1), 'exact count');
        $this->assertNull($service->recordShortage($this->branch, '2026-08-10', -250.0, 'shift', 2), 'over count');
        $this->assertSame(0, ExpenseVoucher::count());
        $this->assertSame(0, ExpenseCategory::count(), 'no category is created until a real shortage happens');
    }
}
