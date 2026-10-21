<?php

namespace Tests\MySql;

use App\Models\Tenant\Account;
use App\Models\Tenant\CashBankAccount;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\OpeningBalanceBatch;
use App\Services\Finance\OpeningBalanceService;
use App\Support\VoidDate;
use Database\Seeders\Tenant\DefaultChartOfAccountsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * VOID-ON-ORIGINAL-DATE-1 — an opening balance entered wrong is wiped on its OWN opening date, so the
 * books from that day on read as if it was never there; "it was right, undo it today" still dates today.
 */
class OpeningBalanceVoidDateMySqlTest extends MySqlTenantTestCase
{
    private int $cashId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::setDefaultConnection('tenant');
        $this->cleanTenant([
            'opening_balance_lines', 'opening_balance_batches', 'journal_lines', 'journal_entries',
            'cash_bank_account_transactions', 'cash_bank_accounts', 'accounts',
        ]);
        (new DefaultChartOfAccountsSeeder())->run();
        $this->cashId = CashBankAccount::create([
            'account_id' => Account::where('code', '1110')->value('id'), 'name' => 'Main Drawer', 'code' => 'CASH',
            'account_type' => 'cash', 'current_balance' => 0, 'is_active' => 1,
        ])->id;
    }

    private function postedBatch(string $openingDate): OpeningBalanceBatch
    {
        $service = app(OpeningBalanceService::class);
        $batch = $service->createDraft([
            'batch_no' => null, 'opening_date' => $openingDate, 'description' => 'Opening cash',
            'lines' => [
                ['account_id' => Account::where('code', '1110')->value('id'), 'cash_bank_account_id' => $this->cashId, 'debit' => 5000, 'credit' => 0],
                ['account_id' => Account::where('code', '3300')->value('id'), 'debit' => 0, 'credit' => 5000],
            ],
        ]);

        return $service->post($batch);
    }

    public function test_a_wrong_opening_is_wiped_on_its_opening_date(): void
    {
        $day = now()->subDays(10)->toDateString();
        $batch = $this->postedBatch($day);

        app(OpeningBalanceService::class)->void($batch, null, 'typed the wrong amount', VoidDate::ORIGINAL);

        $reversal = JournalEntry::where('reversed_entry_id', $batch->fresh()->journal_entry_id)->sole();
        $this->assertSame($day, $reversal->entry_date->toDateString());
        $back = DB::connection('tenant')->table('cash_bank_account_transactions')->where('transaction_type', 'opening_balance_void_reversal')->sole();
        $this->assertSame($day, Carbon::parse($back->transaction_date)->toDateString());
        $this->assertEqualsWithDelta(0, (float) CashBankAccount::find($this->cashId)->current_balance, 0.001);
    }

    public function test_reverse_today_and_no_answer_both_keep_the_old_behaviour(): void
    {
        $first = $this->postedBatch(now()->subDays(10)->toDateString());
        app(OpeningBalanceService::class)->void($first, null, 'it was right', VoidDate::TODAY);
        $this->assertSame(now()->toDateString(), JournalEntry::where('reversed_entry_id', $first->fresh()->journal_entry_id)->sole()->entry_date->toDateString());

        $second = $this->postedBatch(now()->subDays(8)->toDateString());
        app(OpeningBalanceService::class)->void($second, null, 'an old caller');
        $this->assertSame(now()->toDateString(), JournalEntry::where('reversed_entry_id', $second->fresh()->journal_entry_id)->sole()->entry_date->toDateString());
    }
}
