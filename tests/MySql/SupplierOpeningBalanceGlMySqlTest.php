<?php

namespace Tests\MySql;

use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\Supplier;
use App\Services\Finance\JournalPostingService;
use Database\Seeders\Tenant\DefaultChartOfAccountsSeeder;
use Illuminate\Support\Facades\DB;

/**
 * SUPPLIER-OPENING-GL — a supplier's opening balance must reach the GENERAL LEDGER, not
 * just the supplier subsidiary ledger. Otherwise Accounts Payable in the Trial Balance
 * never reconciles with the supplier ledger (the exact defect a live client hit: opening
 * 168k in the supplier ledger, payments in the GL, so AP showed the payments alone).
 *
 * It posts Dr Opening Balance Equity / Cr Accounts Payable — an EQUITY offset, because an
 * opening balance predates the period and must never touch the current P&L.
 */
class SupplierOpeningBalanceGlMySqlTest extends MySqlTenantTestCase
{
    public function test_a_supplier_opening_balance_posts_to_the_gl_against_equity_not_pl(): void
    {
        DB::setDefaultConnection('tenant');
        $this->cleanTenant(['journal_lines', 'journal_entries', 'supplier_ledgers', 'suppliers', 'accounts', 'branches']);
        (new DefaultChartOfAccountsSeeder)->run();

        $supplier = Supplier::create([
            'code' => 'S1', 'name' => 'Kashif kitchen', 'status' => 'active',
            'opening_balance' => 168000, 'current_balance' => 168000,
        ]);

        $entry = app(JournalPostingService::class)->postSupplierOpeningBalance($supplier);

        $this->assertNotNull($entry, 'the opening balance posts a GL entry');
        $entry->loadMissing('lines.account');
        $byCode = $entry->lines->mapWithKeys(fn ($l) => [$l->account->code => ['d' => (float) $l->debit, 'c' => (float) $l->credit]]);

        // Dr Opening Balance Equity (3300) / Cr Accounts Payable (2100), balanced.
        $this->assertSame(168000.0, $byCode['3300']['d'] ?? null, 'Opening Balance Equity is debited');
        $this->assertSame(168000.0, $byCode['2100']['c'] ?? null, 'Accounts Payable is credited');
        $this->assertEqualsWithDelta((float) $entry->lines->sum('debit'), (float) $entry->lines->sum('credit'), 0.001, 'entry balances');

        // ONLY balance-sheet accounts are touched — an opening balance never hits P&L.
        $this->assertSame(['2100', '3300'], $entry->lines->pluck('account.code')->sort()->values()->all(),
            'only Accounts Payable + Opening Balance Equity — no income/expense (P&L) account');

        // Idempotent — posting again returns the same entry, never a duplicate.
        $again = app(JournalPostingService::class)->postSupplierOpeningBalance($supplier);
        $this->assertSame($entry->id, $again->id);
        $this->assertSame(1, JournalEntry::where('source_type', 'supplier_opening_balance')->count());
    }
}
