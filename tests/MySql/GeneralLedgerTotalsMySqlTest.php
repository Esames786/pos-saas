<?php

namespace Tests\MySql;

use App\Http\Controllers\Tenant\Finance\GeneralLedgerController;
use App\Services\Finance\FinancialExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Tests\MySql\Support\TenantFixtures;

/**
 * GL-TOTALS-1 — the General Ledger adds up at the bottom.
 *
 * Kashif Kitchen asked for a total under the ledger lines. The total is over every line the filters
 * match — the screen stops at 5,000 lines and a total summed from the page would leave the rest out.
 */
class GeneralLedgerTotalsMySqlTest extends MySqlTenantTestCase
{
    use TenantFixtures;

    private array $acc = [];

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant(['journal_lines', 'journal_entries', 'accounts', 'branches']);
        foreach ([['1110', 'Main Cash Drawer', 'asset', 'debit'], ['2100', 'Accounts Payable', 'liability', 'credit'], ['1400', 'Inventory Asset', 'asset', 'debit']] as [$c, $n, $t, $nb]) {
            $this->acc[$c] = DB::table('accounts')->insertGetId(['code' => $c, 'name' => $n, 'type' => $t, 'normal_balance' => $nb, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->entry('2026-10-01', [['1400', 117600, 0], ['2100', 0, 117600]]);
        $this->entry('2026-10-01', [['2100', 117600, 0], ['1110', 0, 117600]]);
        $this->entry('2026-10-02', [['1400', 22500, 0], ['2100', 0, 22500]]);
        $this->entry('2026-10-02', [['1110', 999, 0], ['2100', 0, 999]], 'draft'); // never posted
        view()->share('errors', new ViewErrorBag);
    }

    private function entry(string $date, array $lines, string $status = 'posted'): void
    {
        $id = DB::table('journal_entries')->insertGetId(['entry_no' => 'JE-' . uniqid(), 'entry_date' => $date, 'status' => $status, 'description' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        foreach ($lines as [$code, $dr, $cr]) {
            DB::table('journal_lines')->insert(['journal_entry_id' => $id, 'account_id' => $this->acc[$code], 'debit' => $dr, 'credit' => $cr, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function page(array $params): string
    {
        return app(GeneralLedgerController::class)
            ->index(Request::create('/finance/general-ledger', 'GET', $params + ['date_from' => '2026-10-01', 'date_to' => '2026-10-31']))
            ->render();
    }

    public function test_the_ledger_totals_its_lines(): void
    {
        $html = $this->page([]);

        $this->assertStringContainsString('<td class="text-end" id="gl-total-debit">257,700.00</td>', $html, '117,600 + 117,600 + 22,500');
        $this->assertStringContainsString('<td class="text-end" id="gl-total-credit">257,700.00</td>', $html);
        $this->assertStringContainsString('<td colspan="2" class="text-end" id="gl-difference">0.00</td>', $html, 'a whole ledger balances');
    }

    public function test_one_account_shows_its_totals_and_closing_balance(): void
    {
        $html = $this->page(['account_id' => $this->acc['2100']]);

        $this->assertStringContainsString('id="gl-total-debit">117,600.00<', $html);
        $this->assertStringContainsString('id="gl-total-credit">140,100.00<', $html, 'the 999 draft is not in the books');
        $this->assertStringContainsString('id="gl-closing">22,500.00<', $html, 'payable left: 140,100 − 117,600');
        $this->assertStringNotContainsString('id="gl-difference"', $html, 'the balance column already says it');
    }

    public function test_the_total_covers_lines_past_the_limit(): void
    {
        // The screen passes 5,000; the same function with a limit of 2 must still total all six lines.
        $lines = app(FinancialExportService::class)->generalLedgerLines('2026-10-01', '2026-10-31', null, null, 2);

        $this->assertCount(2, $lines);
        $this->assertTrue($lines->truncated);
        $this->assertSame(257700.0, $lines->total_debit);
        $this->assertSame(257700.0, $lines->total_credit);
    }

    public function test_an_empty_ledger_has_no_total_row(): void
    {
        $html = $this->page(['date_from' => '2027-01-01', 'date_to' => '2027-01-31']);

        $this->assertStringNotContainsString('gl-total-debit', $html);
    }
}
